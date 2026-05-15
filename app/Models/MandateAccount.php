<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MandateAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_number',
        'bank_code',
        'description',
        'start_date',
        'end_date',
        'is_primary',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];
}
