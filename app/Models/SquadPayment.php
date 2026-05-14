<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="SquadPayment",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="worker_id", type="integer"),
 *   @OA\Property(property="cycle_id", type="integer"),
 *   @OA\Property(property="verification_id", type="integer", nullable=true),
 *   @OA\Property(property="amount", type="number"),
 *   @OA\Property(property="bank_name", type="string"),
 *   @OA\Property(property="bank_account_masked", type="string"),
 *   @OA\Property(property="squad_reference", type="string"),
 *   @OA\Property(property="status", type="string", enum={"released","blocked","pending","failed"}),
 *   @OA\Property(property="disbursed_at", type="string", format="date-time", nullable=true)
 * )
 */
class SquadPayment extends Model
{
    use HasFactory;

    protected $table = 'squad_payments';

    protected $fillable = [
        'worker_id', 'cycle_id', 'verification_id', 'amount',
        'bank_name', 'bank_account_masked', 'squad_reference',
        'status', 'disbursed_at',
    ];

    protected $casts = [
        'disbursed_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function cycle()
    {
        return $this->belongsTo(VerificationCycle::class, 'cycle_id');
    }

    public function verification()
    {
        return $this->belongsTo(Verification::class, 'verification_id');
    }
}
