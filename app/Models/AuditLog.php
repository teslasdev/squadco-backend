<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="AuditLog",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="user_id", type="integer", nullable=true),
 *   @OA\Property(property="action", type="string"),
 *   @OA\Property(property="entity_type", type="string"),
 *   @OA\Property(property="entity_id", type="integer"),
 *   @OA\Property(property="old_values", type="object"),
 *   @OA\Property(property="new_values", type="object"),
 *   @OA\Property(property="ip_address", type="string"),
 *   @OA\Property(property="user_agent", type="string"),
 *   @OA\Property(property="created_at", type="string", format="date-time")
 * )
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
