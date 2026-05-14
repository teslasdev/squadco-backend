<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="FieldAgent",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="user_id", type="integer"),
 *   @OA\Property(property="agent_code", type="string"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="phone", type="string"),
 *   @OA\Property(property="state", type="string"),
 *   @OA\Property(property="lga", type="string"),
 *   @OA\Property(property="gps_lat", type="number"),
 *   @OA\Property(property="gps_lng", type="number"),
 *   @OA\Property(property="status", type="string", enum={"active","inactive"}),
 *   @OA\Property(property="assignments_count", type="integer")
 * )
 */
class FieldAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'agent_code', 'name', 'phone', 'state', 'lga',
        'gps_lat', 'gps_lng', 'status', 'assignments_count', 'last_assignment_at',
    ];

    protected $casts = [
        'last_assignment_at' => 'datetime',
        'gps_lat' => 'decimal:8',
        'gps_lng' => 'decimal:8',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dispatches()
    {
        return $this->hasMany(AgentDispatch::class, 'agent_id');
    }
}
