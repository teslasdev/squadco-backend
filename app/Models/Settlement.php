<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="Settlement",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="mda_id", type="integer"),
 *   @OA\Property(property="cycle_id", type="integer"),
 *   @OA\Property(property="total_disbursed", type="number"),
 *   @OA\Property(property="total_blocked", type="number"),
 *   @OA\Property(property="squad_batch_id", type="string"),
 *   @OA\Property(property="status", type="string", enum={"pending","settled","failed"}),
 *   @OA\Property(property="settled_at", type="string", format="date-time", nullable=true)
 * )
 */
class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'mda_id', 'cycle_id', 'total_disbursed', 'total_blocked',
        'squad_batch_id', 'status', 'settled_at',
    ];

    protected $casts = [
        'settled_at' => 'datetime',
        'total_disbursed' => 'decimal:2',
        'total_blocked' => 'decimal:2',
    ];

    public function mda()
    {
        return $this->belongsTo(Mda::class);
    }

    public function cycle()
    {
        return $this->belongsTo(VerificationCycle::class, 'cycle_id');
    }
}
