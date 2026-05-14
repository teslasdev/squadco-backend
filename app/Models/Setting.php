<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="Setting",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="key", type="string"),
 *   @OA\Property(property="value", type="string"),
 *   @OA\Property(property="description", type="string")
 * )
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'description'];
}
