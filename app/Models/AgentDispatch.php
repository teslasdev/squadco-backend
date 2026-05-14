<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="AgentDispatch",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="alert_id", type="integer"),
 *   @OA\Property(property="agent_id", type="integer"),
 *   @OA\Property(property="worker_id", type="integer"),
 *   @OA\Property(property="status", type="string", enum={"pending","en_route","completed","failed"}),
 *   @OA\Property(property="gps_lat", type="number"),
 *   @OA\Property(property="gps_lng", type="number"),
 *   @OA\Property(property="notes", type="string"),
 *   @OA\Property(property="dispatched_at", type="string", format="date-time"),
 *   @OA\Property(property="completed_at", type="string", format="date-time", nullable=true)
 * )
 */
class AgentDispatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_id', 'agent_id', 'worker_id', 'status',
        'gps_lat', 'gps_lng', 'notes', 'dispatched_at', 'completed_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function alert()
    {
        return $this->belongsTo(GhostWorkerAlert::class, 'alert_id');
    }

    public function agent()
    {
        return $this->belongsTo(FieldAgent::class, 'agent_id');
    }

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }
}
