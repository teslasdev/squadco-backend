<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaceVerificationSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id', 'admin_user_id', 'status',
        'frame1_url', 'frame2_url', 'frame3_url',
        'identity_score', 'identity_verdict', 'identity_spoof_prob',
        'pose_right_passed', 'pose_left_passed',
        'pose_right_delta_deg', 'pose_left_delta_deg',
        'verdict', 'latency_ms', 'failure_reason',
        'verification_id',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'identity_spoof_prob'  => 'decimal:4',
        'pose_right_delta_deg' => 'decimal:2',
        'pose_left_delta_deg'  => 'decimal:2',
        'pose_right_passed'    => 'boolean',
        'pose_left_passed'     => 'boolean',
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function adminUser()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function verification()
    {
        return $this->belongsTo(Verification::class);
    }
}
