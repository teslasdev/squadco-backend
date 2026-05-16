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
                            {id? : Reset only this cycle id (otherwise all stuck ones)}
                            {--stale=0 : Only reset cycles stuck running for at least N minutes}
                            {--status=pending : Status to reset to (pending|failed)}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Reset verification cycles frozen at status=running so they can be re-run';

    public function handle(): int
    {
        $resetTo = $this->option('status');
        if (! in_array($resetTo, ['pending', 'failed'], true)) {
            $this->error("--status must be 'pending' or 'failed', got '{$resetTo}'.");
            return self::FAILURE;
        }

        $query = VerificationCycle::where('status', 'running');

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
            $this->info('No stuck cycles found. Nothing to do.');
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
