<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="GhostWorkerAlert",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="worker_id", type="integer"),
 *   @OA\Property(property="verification_id", type="integer", nullable=true),
 *   @OA\Property(property="alert_type", type="string"),
 *   @OA\Property(property="severity", type="string", enum={"critical","high","medium","low"}),
 *   @OA\Property(property="ai_confidence", type="number"),
 *   @OA\Property(property="salary_at_risk", type="number"),
 *   @OA\Property(property="status", type="string"),
 *   @OA\Property(property="notes", type="string"),
 *   @OA\Property(property="raised_at", type="string", format="date-time"),
 *   @OA\Property(property="resolved_at", type="string", format="date-time", nullable=true)
 * )
 */
class GhostWorkerAlert extends Model
{
    use HasFactory;

    protected $table = 'ghost_worker_alerts';

    protected $fillable = [
        'worker_id', 'verification_id', 'alert_type', 'severity',
        'ai_confidence', 'salary_at_risk', 'status', 'notes',
        'raised_at', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'raised_at' => 'datetime',
        'resolved_at' => 'datetime',
        'ai_confidence' => 'decimal:2',
        'salary_at_risk' => 'decimal:2',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function verification()
    {
        return $this->belongsTo(Verification::class, 'verification_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function dispatches()
    {
        return $this->hasMany(AgentDispatch::class, 'alert_id');
    }
}
