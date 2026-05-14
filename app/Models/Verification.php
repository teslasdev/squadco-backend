<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *   schema="Verification",
 *   @OA\Property(property="id", type="integer"),
 *   @OA\Property(property="worker_id", type="integer"),
 *   @OA\Property(property="cycle_id", type="integer"),
 *   @OA\Property(property="channel", type="string", enum={"ivr","app","agent"}),
 *   @OA\Property(property="trust_score", type="integer"),
 *   @OA\Property(property="verdict", type="string", enum={"PASS","FAIL","INCONCLUSIVE"}),
 *   @OA\Property(property="challenge_response_score", type="integer"),
 *   @OA\Property(property="speaker_biometric_score", type="integer"),
 *   @OA\Property(property="anti_spoof_score", type="integer"),
 *   @OA\Property(property="replay_detection_score", type="integer"),
 *   @OA\Property(property="face_liveness_score", type="integer"),
 *   @OA\Property(property="latency_ms", type="integer"),
 *   @OA\Property(property="language", type="string"),
 *   @OA\Property(property="salary_released", type="boolean"),
 *   @OA\Property(property="squad_reference", type="string"),
 *   @OA\Property(property="verified_at", type="string", format="date-time")
 * )
 */
class Verification extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id', 'cycle_id', 'channel', 'trust_score', 'verdict',
        'challenge_response_score', 'speaker_biometric_score', 'anti_spoof_score',
        'replay_detection_score', 'face_liveness_score', 'latency_ms', 'language',
        'salary_released', 'squad_reference', 'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'salary_released' => 'boolean',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function cycle()
    {
        return $this->belongsTo(VerificationCycle::class, 'cycle_id');
    }

    public function alerts()
    {
        return $this->hasMany(GhostWorkerAlert::class, 'verification_id');
    }
}
