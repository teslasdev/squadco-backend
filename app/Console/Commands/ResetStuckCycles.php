<?php

namespace App\Console\Commands;

use App\Models\VerificationCycle;
use Illuminate\Console\Command;

/**
 * Unsticks verification cycles frozen at status='running'.
 *
 * A cycle is set to `running` the moment the Run button is pressed (see
 * VerificationCycleController::run), then RunVerificationCycleJob is dispatched
 * to the queue to actually resolve it. If the queue worker isn't running, or
 * the job dies, the cycle never reaches `completed` — it's frozen at `running`
 * forever, and every subsequent Run press returns HTTP 409
 * ("Cycle is already running or not in a runnable state.").
 *
 * This command flips such cycles back to `pending` so they can be re-run.
 */
class ResetStuckCycles extends Command
{
    protected $signature = 'cycles:reset
                            {id? : Reset only this cycle id (otherwise all matching ones)}
                            {--stale=0 : Only reset cycles stuck for at least N minutes}
                            {--status=pending : Status to reset to (pending|completed)}
                            {--from=running : Source status to match. Use "" to repair cycles whose status was truncated to empty}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Reset verification cycles frozen at status=running (or repair empty-status rows) so they can be re-run';

    /**
     * The verification_cycles.status column is a MySQL ENUM defined in
     * 2026_05_13_000105_create_verification_cycles_table.php as exactly
     * ['pending','running','completed']. Writing anything else (e.g. 'failed')
     * makes MySQL silently truncate it to '' and corrupt the row, so we must
     * validate against the real ENUM before any UPDATE.
     */
    private const VALID_STATUSES = ['pending', 'running', 'completed'];

    public function handle(): int
    {
        $resetTo = $this->option('status');
        // Only 'pending' or 'completed' make sense as a *reset target*
        // ('running' would just re-freeze it) — but both must be real ENUM
        // members so we never truncate the column again.
        if (! in_array($resetTo, ['pending', 'completed'], true)) {
            $this->error("--status must be 'pending' or 'completed' (valid ENUM values), got '{$resetTo}'.");
            return self::FAILURE;
        }

        $from = $this->option('from');
        if (! in_array($from, [...self::VALID_STATUSES, ''], true)) {
            $this->error("--from must be one of: '" . implode("', '", self::VALID_STATUSES) . "', or \"\" (truncated/empty). Got '{$from}'.");
            return self::FAILURE;
        }

        $query = VerificationCycle::where('status', $from);

        if ($id = $this->argument('id')) {
            $query->where('id', (int) $id);
        }

        $staleMinutes = (int) $this->option('stale');
        if ($staleMinutes > 0) {
            // Only touch cycles that have been running longer than the window —
            // protects a genuinely in-flight cycle from being yanked.
            $query->where('started_at', '<=', now()->subMinutes($staleMinutes));
        }

        $cycles = $query->get(['id', 'name', 'status', 'started_at']);

        if ($cycles->isEmpty()) {
            $label = $from === '' ? 'empty/truncated' : "status='{$from}'";
            $this->info("No cycles with {$label} found. Nothing to do.");
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Status', 'Started at'],
            $cycles->map(fn ($c) => [
                $c->id,
                $c->name,
                $c->status,
                optional($c->started_at)->toDateTimeString() ?? '—',
            ])->all(),
        );

        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm("Reset {$cycles->count()} cycle(s) to '{$resetTo}' in PRODUCTION?")) {
                $this->warn('Aborted.');
                return self::FAILURE;
            }
        }

        $affected = VerificationCycle::whereIn('id', $cycles->pluck('id'))
            ->update(['status' => $resetTo]);

        $this->info("Reset {$affected} cycle(s) to '{$resetTo}'. They can now be re-run.");

        return self::SUCCESS;
    }
}
