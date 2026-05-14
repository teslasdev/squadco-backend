<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="VirtualAccount",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="worker_id", type="integer"),
 *   @OA\Property(property="account_number", type="string"),
 *   @OA\Property(property="bank_name", type="string"),
 *   @OA\Property(property="provider", type="string"),
 *   @OA\Property(property="balance", type="number"),
 *   @OA\Property(property="is_active", type="boolean")
 * )
 */
class VirtualAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id', 'account_number', 'bank_name', 'provider', 'balance', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balance' => 'decimal:2',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }
}
