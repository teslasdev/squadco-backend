<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="Mda",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="code", type="string"),
 *   @OA\Property(property="state", type="string"),
 *   @OA\Property(property="ministry_type", type="string", enum={"federal","state"}),
 *   @OA\Property(property="contact_email", type="string"),
 *   @OA\Property(property="head_name", type="string"),
 *   @OA\Property(property="worker_count", type="integer"),
 *   @OA\Property(property="risk_score", type="number", format="float")
 * )
 */
class Mda extends Model
{
    use HasFactory;

    protected $table = 'mdas';

    protected $fillable = [
        'name', 'code', 'state', 'ministry_type', 'contact_email',
        'head_name', 'worker_count', 'risk_score',
    ];

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function workers()
    {
        return $this->hasMany(Worker::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }
}
