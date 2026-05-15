<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="VerificationCycle",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="cycle_month", type="string", format="date"),
 *   @OA\Property(property="federation", type="string"),
 *   @OA\Property(property="status", type="string", enum={"pending","running","completed"}),
 *   @OA\Property(property="total_workers", type="integer"),
 *   @OA\Property(property="verified_count", type="integer"),
 *   @OA\Property(property="failed_count", type="integer"),
 *   @OA\Property(property="inconclusive_count", type="integer"),
 *   @OA\Property(property="payroll_released", type="number"),
 *   @OA\Property(property="payroll_blocked", type="number")
 * )
 */
class VerificationCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'cycle_month', 'federation', 'status', 'total_workers',
        'verified_count', 'failed_count', 'inconclusive_count',
        'payroll_released', 'payroll_blocked', 'started_at', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'cycle_month' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'payroll_released' => 'decimal:2',
        'payroll_blocked' => 'decimal:2',
    ];

    public function verifications()
    {
        return $this->hasMany(Verification::class, 'cycle_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class, 'cycle_id');
    }

    public function payments()
    {
        return $this->hasMany(SquadPayment::class, 'cycle_id');
    }

    /**
     * Counts derived LIVE from this cycle's Verification rows.
     *
     * The cycle is now async (Vapi webhooks + worker face checks land over
     * time), so the static *_count columns can't be the source of truth. One
     * grouped query gives:
     *   verified      = PASS
     *   failed        = FAIL
     *   inconclusive  = INCONCLUSIVE
     *   pending       = verdict NULL (seeded, not yet completed)
     *   resolved      = anything with a non-null verdict
     *
     * @return array{verified:int,failed:int,inconclusive:int,pending:int,resolved:int,total:int}
     */
    public function liveStats(): array
    {
        $rows = $this->verifications()
            ->selectRaw('verdict, COUNT(*) as c')
            ->groupBy('verdict')
            ->pluck('c', 'verdict');

        $verified     = (int) ($rows['PASS'] ?? 0);
        $failed       = (int) ($rows['FAIL'] ?? 0);
        $inconclusive = (int) ($rows['INCONCLUSIVE'] ?? 0);
        // NULL verdict groups under the empty/null key depending on driver.
        $pending      = (int) ($rows[''] ?? $rows[null] ?? 0);
        $resolved     = $verified + $failed + $inconclusive;

        return [
            'verified'     => $verified,
            'failed'       => $failed,
            'inconclusive' => $inconclusive,
            'pending'      => $pending,
            'resolved'     => $resolved,
            'total'        => $resolved + $pending,
        ];
    }

    /**
     * Write-through completion: a cycle is "completed" once every expected
     * result that CAN resolve without the worker has resolved — i.e. all
     * non-pending rows are in. Pending face rows roll over and never block
     * completion. Idempotent; safe to call from any read path.
     */
    public function syncCompletion(): void
    {
        if (in_array($this->status, ['completed', 'failed'], true)) {
            return;
        }

        $stats = $this->liveStats();
        $expected = (int) $this->total_workers;

        // total_workers was set at dispatch to (phone dispatched + face
        // pending). Cycle is done once resolved >= the non-pending portion,
        // i.e. once nothing is left that resolves automatically. We treat
        // "resolved >= expected - currentPending" as complete so outstanding
        // face checks don't keep it open forever.
        if ($expected > 0 && $stats['resolved'] >= max(0, $expected - $stats['pending'])) {
            $this->update([
                'status'             => 'completed',
                'verified_count'     => $stats['verified'],
                'failed_count'       => $stats['failed'],
                'inconclusive_count' => $stats['inconclusive'],
                'completed_at'       => $this->completed_at ?? now(),
            ]);
        }
    }
}
