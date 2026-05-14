<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="Department",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="mda_id", type="integer"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="code", type="string")
 * )
 */
class Department extends Model
{
    use HasFactory;

    protected $fillable = ['mda_id', 'name', 'code'];

    public function mda()
    {
        return $this->belongsTo(Mda::class);
    }

    public function workers()
    {
        return $this->hasMany(Worker::class);
    }
}
