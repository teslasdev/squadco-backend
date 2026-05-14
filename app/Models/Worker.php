<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="Worker",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="ippis_id", type="string"),
 *   @OA\Property(property="full_name", type="string"),
 *   @OA\Property(property="nin", type="string"),
 *   @OA\Property(property="bvn", type="string"),
 *   @OA\Property(property="phone", type="string"),
 *   @OA\Property(property="email", type="string"),
 *   @OA\Property(property="mda_id", type="integer"),
 *   @OA\Property(property="department_id", type="integer"),
 *   @OA\Property(property="grade_level", type="string"),
 *   @OA\Property(property="step", type="string"),
 *   @OA\Property(property="state_of_posting", type="string"),
 *   @OA\Property(property="salary_amount", type="number"),
 *   @OA\Property(property="bank_name", type="string"),
 *   @OA\Property(property="bank_account_number", type="string"),
 *   @OA\Property(property="status", type="string", enum={"active","flagged","blocked","suspended"}),
 *   @OA\Property(property="enrolled_at", type="string", format="date-time"),
 *   @OA\Property(property="last_verified_at", type="string", format="date-time")
 * )
 */
class Worker extends Model
{
    use HasFactory;

    protected $fillable = [
        'ippis_id', 'full_name', 'date_of_birth', 'gender', 'employment_date',
        'nin', 'bvn', 'phone', 'email',
        'mda_id', 'department_id', 'job_title', 'employment_type',
        'grade_level', 'step', 'state_of_posting', 'lga',
        'office_address', 'home_address',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
        'salary_amount', 'bank_name', 'bank_code', 'bank_account_number', 'bank_account_name',
        'status', 'onboarding_status', 'onboarding_token',
        'enrolled_at', 'last_verified_at',
        'face_template_url', 'face_enrolled', 'face_embedding',
        'voice_template_url', 'voice_enrolled',
        'voice_embedding_ecapa', 'voice_embedding_campplus',
        'verification_channel',
    ];

    protected $casts = [
        'enrolled_at'              => 'datetime',
        'last_verified_at'         => 'datetime',
        'employment_date'          => 'date',
        'date_of_birth'            => 'date',
        'salary_amount'            => 'decimal:2',
        'face_enrolled'            => 'boolean',
        'voice_enrolled'           => 'boolean',
        'face_embedding'           => 'array',
        'voice_embedding_ecapa'    => 'array',
        'voice_embedding_campplus' => 'array',
    ];

    public function mda()
    {
        return $this->belongsTo(Mda::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function verifications()
    {
        return $this->hasMany(Verification::class);
    }

    public function alerts()
    {
        return $this->hasMany(GhostWorkerAlert::class);
    }

    public function payments()
    {
        return $this->hasMany(SquadPayment::class);
    }

    public function virtualAccount()
    {
        return $this->hasOne(VirtualAccount::class);
    }
}
