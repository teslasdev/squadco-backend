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
}
