<?php

namespace App\Jobs;

use App\Models\VerificationCycle;
use App\Models\Worker;
use App\Models\Verification;
use App\Services\AuditService;
use App\Services\VapiCallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs a real, channel-aware verification cycle.
 *
 * This job no longer fakes scores. It is a *dispatcher*:
 *
 *   - phone / both workers → an outbound Vapi IVR call is dispatched. The
 *     real verdict lands asynchronously via VapiWebhookController (which we
 *     tag with this cycle's id via call metadata).
 *   - web workers → a PENDING Verification row (verdict NULL) is seeded and
 *     surfaced as a task on the worker's dashboard; the worker self-completes
 *     a face check that fills the row in (FaceVerificationController).
 *
 * Because results arrive over time, the cycle is NOT marked completed here —
 * it stays `running`. VerificationCycleController derives tallies + completion
 * live from the Verification rows.
 */
class RunVerificationCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public VerificationCycle $cycle) {}

    public function handle(
        VapiCallService $vapi,
        AuditService $auditService
    ): void {
        $this->cycle->update(['status' => 'running', 'started_at' => now()]);

        $assistantId = config('services.vapi.assistant_verify_id');

        $dispatched = 0;        // phone calls successfully placed
        $skippedNoVoice = 0;    // phone-channel but not voice-enrolled/eligible
        $dispatchFailed = 0;    // Vapi dispatch errored
        $facePending = 0;       // web workers seeded with a pending row
        $skippedFaceNoEnrol = 0; // web-channel but not face-enrolled

        // ── Phone / both → real outbound Vapi call ────────────────────────
        $phoneWorkers = Worker::where('status', 'active')
            ->whereIn('verification_channel', ['phone', 'both'])
            ->get();

        foreach ($phoneWorkers as $worker) {
            try {
                // Same eligibility guards as VoiceEnrolmentController::verify().
                if (
                    empty($worker->phone)
                    || empty($worker->full_name)
                    || empty($worker->ippis_id)
                    || !$worker->voice_enrolled
                    || empty($worker->voice_embedding_ecapa)
                    || !$assistantId
                ) {
                    $skippedNoVoice++;
                    continue;
                }

                $worker->loadMissing('mda');
                $firstName = trim(explode(' ', $worker->full_name)[0]) ?: $worker->full_name;
                $now = now();

                $result = $vapi->dispatchCall(
                    $worker->phone,
                    $assistantId,
                    [
                        'worker_id' => $worker->id,
                        'intent'    => 'verify',
                        // Threads through to VapiWebhookController so the
                        // resulting Verification attaches to THIS cycle.
                        'cycle_id'  => $this->cycle->id,
                    ],
                    [
                        'variableValues' => [
                            'worker_first_name' => $firstName,
                            'worker_full_name'  => $worker->full_name,
                            'ippis_id'          => $worker->ippis_id,
                            'mda_name'          => $worker->mda?->name ?? 'your ministry',
                            'today_date'        => $now->format('l, F j, Y'),
                            'today_short'       => $now->format('F j'),
                        ],
                    ]
                );

                if ($result['success']) {
                    $dispatched++;
                } else {
                    $dispatchFailed++;
                    Log::warning("RunVerificationCycleJob: dispatch failed for worker {$worker->id}: {$result['message']}");
                }

                // Gentle pacing so a large roster doesn't hammer Vapi.
                usleep(300_000);
            } catch (\Throwable $e) {
                $dispatchFailed++;
                Log::error("RunVerificationCycleJob: worker {$worker->id} dispatch error: {$e->getMessage()}");
            }
        }

        // ── Web → seed a PENDING verification + dashboard task ────────────
        // `both` workers are handled by the phone path above to avoid double
        // counting; only pure `web` workers are seeded here.
        $webWorkers = Worker::where('status', 'active')
            ->where('verification_channel', 'web')
            ->get();

        foreach ($webWorkers as $worker) {
            try {
                if (!$worker->face_enrolled || empty($worker->face_embedding)) {
                    $skippedFaceNoEnrol++;
                    continue;
                }

                // Don't double-seed if this worker already has an open pending
                // row for this cycle (e.g. cycle re-run).
                $existing = Verification::where('worker_id', $worker->id)
                    ->where('cycle_id', $this->cycle->id)
                    ->whereNull('verdict')
                    ->whereNull('verified_at')
                    ->exists();
                if ($existing) {
                    $facePending++;
                    continue;
                }

                Verification::create([
                    'worker_id'   => $worker->id,
                    'cycle_id'    => $this->cycle->id,
                    'channel'     => 'app',
                    'trust_score' => 0,
                    'verdict'     => null,        // pending — filled when worker completes face check
                    'verified_at' => null,
                    'language'    => null,
                ]);
                $facePending++;
            } catch (\Throwable $e) {
                Log::error("RunVerificationCycleJob: worker {$worker->id} face-seed error: {$e->getMessage()}");
            }
        }

        // total_workers = the set we expect to resolve (phone calls placed +
        // face checks queued). Cycle stays `running`; tallies/completion are
        // derived live by VerificationCycleController as results land.
        $this->cycle->update([
            'total_workers' => $dispatched + $facePending,
        ]);

        $summary = [
            'dispatched'            => $dispatched,
            'face_pending'          => $facePending,
            'skipped_no_voice'      => $skippedNoVoice,
            'skipped_face_no_enrol' => $skippedFaceNoEnrol,
            'dispatch_failed'       => $dispatchFailed,
        ];

        $auditService->log(
            'cycle_run_dispatched',
            'VerificationCycle',
            $this->cycle->id,
            [],
            $summary
        );

        Log::info("RunVerificationCycleJob cycle {$this->cycle->id} dispatched", $summary);
    }
}
