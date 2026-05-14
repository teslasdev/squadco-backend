<?php

namespace App\Jobs;

use App\Models\VerificationCycle;
use App\Models\Worker;
use App\Models\Verification;
use App\Services\TrustScoreService;
use App\Services\AlertService;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunVerificationCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public VerificationCycle $cycle) {}

    public function handle(
        TrustScoreService $trustScoreService,
        AlertService $alertService,
        AuditService $auditService
    ): void {
        $this->cycle->update(['status' => 'running', 'started_at' => now()]);

        $workers = Worker::where('status', 'active')->get();
        $verified = $failed = $inconclusive = 0;

        foreach ($workers as $worker) {
            try {
                // Stub IVR call — in production this would trigger a real IVR challenge.
                // Simulated scores for demo/dev.
                $challengeResponse = rand(50, 99);
                $speakerBiometric  = rand(50, 99);
                $antiSpoof         = rand(50, 99);
                $replayDetection   = rand(50, 99);
                $faceLiveness      = rand(50, 99);

                $result = $trustScoreService->calculate(
                    $challengeResponse, $speakerBiometric,
                    $antiSpoof, $replayDetection, $faceLiveness
                );

                $verification = Verification::create([
                    'worker_id'               => $worker->id,
                    'cycle_id'                => $this->cycle->id,
                    'channel'                 => 'ivr',
                    'trust_score'             => $result['score'],
                    'verdict'                 => $result['verdict'],
                    'challenge_response_score' => $challengeResponse,
                    'speaker_biometric_score' => $speakerBiometric,
                    'anti_spoof_score'        => $antiSpoof,
                    'replay_detection_score'  => $replayDetection,
                    'face_liveness_score'     => $faceLiveness,
                    'language'                => 'english',
                    'verified_at'             => now(),
                ]);

                $worker->update(['last_verified_at' => now()]);

                if ($result['verdict'] === 'PASS') {
                    $verified++;
                    TriggerSquadDisbursementJob::dispatch($verification);
                } else {
                    if ($result['verdict'] === 'FAIL') $failed++;
                    else $inconclusive++;
                    $alertService->createFromVerification($verification);
                }
            } catch (\Throwable $e) {
                Log::error("RunVerificationCycleJob: worker {$worker->id} failed: {$e->getMessage()}");
            }
        }

        $this->cycle->update([
            'status'            => 'completed',
            'total_workers'     => $workers->count(),
            'verified_count'    => $verified,
            'failed_count'      => $failed,
            'inconclusive_count' => $inconclusive,
            'completed_at'      => now(),
        ]);

        $auditService->log(
            'cycle_run_completed', 'VerificationCycle', $this->cycle->id,
            [], ['verified' => $verified, 'failed' => $failed, 'inconclusive' => $inconclusive]
        );
    }
}
