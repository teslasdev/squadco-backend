<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\TriggerSquadDisbursementJob;
use App\Models\Verification;
use App\Models\VerificationCycle;
use App\Models\Worker;
use App\Services\AiVerificationService;
use App\Services\AlertService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Webhooks', description: 'Inbound webhook endpoints')]
class VapiWebhookController extends Controller
{
    public function __construct(
        private AiVerificationService $ai,
        private AlertService $alert,
        private AuditService $audit
    ) {}

    #[OA\Post(
        path: '/webhooks/vapi',
        operationId: 'vapiWebhook',
        tags: ['Webhooks'],
        summary: 'Receive Vapi end-of-call recordings',
        description: 'Public endpoint authenticated by X-Vapi-Secret header. Routes to enrol or verify based on metadata.intent.',
        parameters: [
            new OA\Parameter(name: 'X-Vapi-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Event processed (success or absorbed failure)'),
            new OA\Response(response: 401, description: 'Invalid secret'),
        ]
    )]
    public function handle(Request $request): JsonResponse
    {
        $expectedSecret = (string) config('services.vapi.webhook_secret', '');
        if ($expectedSecret === '' || $request->header('X-Vapi-Secret') !== $expectedSecret) {
            return $this->errorResponse('Invalid webhook secret.', 401);
        }

        $payload      = $request->json()->all();
        $messageType  = data_get($payload, 'message.type');
        $metadata     = data_get($payload, 'message.call.metadata', []) ?: [];

        // Prefer customer-only mono so the AI service never sees the TTS prompt.
        // AASIST anti-spoof can flag synthesized speech, so feeding it the
        // assistant track inflates spoof_prob. Fall back to combined if the
        // split track isn't available (older Vapi calls, recordingPlan disabled).
        $recordingUrl = data_get($payload, 'message.artifact.recording.mono.customerUrl')
            ?? data_get($payload, 'message.recordingUrl')
            ?? data_get($payload, 'message.artifact.recordingUrl');
        $workerId     = isset($metadata['worker_id']) ? (int) $metadata['worker_id'] : null;
        $intent       = $metadata['intent'] ?? null;
        // Set when the call was dispatched by a verification cycle so the
        // resulting Verification attaches to that cycle (not the ad-hoc
        // "Vapi Continuous" bucket used for one-off verify-voice calls).
        $cycleId      = isset($metadata['cycle_id']) ? (int) $metadata['cycle_id'] : null;

        // Capture call metadata we want to audit on the verification row.
        $callMeta = [
            'call_id'    => data_get($payload, 'message.call.id'),
            'cost'       => data_get($payload, 'message.call.cost'),
            'transcript' => data_get($payload, 'message.transcript')
                         ?? data_get($payload, 'message.artifact.transcript'),
        ];

        // Vapi sends many event types; we only act on end-of-call-report (the one
        // that carries the recording). Anything else: ack + ignore.
        if ($messageType !== 'end-of-call-report') {
            return $this->successResponse(['ignored' => $messageType], 'Event ignored.');
        }

        if (!$workerId || !$intent) {
            $this->audit->log('vapi_missing_metadata', 'VapiWebhook', null, [], ['payload_keys' => array_keys($payload)]);
            return $this->successResponse(['ok' => false, 'reason' => 'missing_metadata']);
        }

        if (!$recordingUrl) {
            $this->audit->log('vapi_missing_recording_url', 'VapiWebhook', $workerId, [], ['intent' => $intent]);
            return $this->successResponse(['ok' => false, 'reason' => 'missing_recording_url']);
        }

        $worker = Worker::find($workerId);
        if (!$worker) {
            $this->audit->log('vapi_unknown_worker', 'VapiWebhook', $workerId, [], ['intent' => $intent]);
            return $this->successResponse(['ok' => false, 'reason' => 'unknown_worker']);
        }

        if ($intent === 'enrol') {
            return $this->handleEnrol($worker, $recordingUrl, $callMeta);
        }

        if ($intent !== 'verify') {
            return $this->absorb('unsupported_intent', $worker->id, ['intent' => $intent]);
        }

        return $this->handleVerify($worker, $recordingUrl, $callMeta, $cycleId);
    }

    /**
     * Phone-channel voice enrolment. Mirrors handleVerify's recording
     * download, but calls /embed (not /verify) and stores the resulting
     * voiceprint on the worker. Enrolling over the phone — the same channel
     * verification uses — eliminates the web/phone mismatch that was failing
     * genuine workers at verification.
     *
     * @param array{call_id: ?string, cost: ?float, transcript: ?string} $callMeta
     */
    private function handleEnrol(Worker $worker, string $recordingUrl, array $callMeta): JsonResponse
    {
        $persisted = $this->persistRecording($recordingUrl, $worker->id);
        if (!$persisted) {
            return $this->absorb('audio_download_failed', $worker->id, ['url' => $recordingUrl, 'intent' => 'enrol']);
        }

        [$absPath, $publicUrl] = $persisted;

        $start  = microtime(true);
        $result = $this->ai->embedVoice($absPath);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$result['success']) {
            $this->audit->log('vapi_enrol_ai_failed', 'Worker', $worker->id, [], [
                'error'  => $result['message'],
                'status' => $result['status'],
            ]);
            return $this->successResponse(['ok' => false, 'reason' => 'ai_failed', 'message' => $result['message']]);
        }

        $ecapa    = data_get($result, 'data.embeddings.ecapa');
        $campplus = data_get($result, 'data.embeddings.campplus');

        if (!is_array($ecapa) || !is_array($campplus)) {
            $this->audit->log('vapi_enrol_bad_response', 'Worker', $worker->id, [], ['data' => $result['data'] ?? null]);
            return $this->successResponse(['ok' => false, 'reason' => 'bad_ai_response']);
        }

        $worker->update([
            'voice_template_url'       => $publicUrl,
            'voice_embedding_ecapa'    => $ecapa,
            'voice_embedding_campplus' => $campplus,
            'voice_enrolled'           => true,
        ]);

        $this->audit->log('vapi_enrol_completed', 'Worker', $worker->id, [], [
            'voice_template_url' => $publicUrl,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
            'duration_sec'       => data_get($result, 'data.quality.duration_sec'),
            'latency_ms'         => $latencyMs,
            'call_id'            => $callMeta['call_id'],
        ]);

        return $this->successResponse([
            'ok'             => true,
            'voice_enrolled' => true,
            'worker_id'      => $worker->id,
        ], 'Voice enrolment (phone) completed.');
    }

    /**
     * @param array{call_id: ?string, cost: ?float, transcript: ?string} $callMeta
     * @param int|null $cycleId Cycle that dispatched this call, if any.
     */
    private function handleVerify(Worker $worker, string $recordingUrl, array $callMeta, ?int $cycleId = null): JsonResponse
    {
        if (!$worker->voice_enrolled || empty($worker->voice_embedding_ecapa) || empty($worker->voice_embedding_campplus)) {
            $this->audit->log('vapi_verify_not_enrolled', 'Worker', $worker->id, [], []);
            return $this->successResponse(['ok' => false, 'reason' => 'not_enrolled']);
        }

        // Persist the recording to public storage so it's playable from the
        // dashboard and survives Vapi's CDN expiry. Stored under
        // verifications/{worker_id}/{uuid}.wav. We work with the absolute
        // path for the AI call and keep the public URL on the row.
        $persisted = $this->persistRecording($recordingUrl, $worker->id);
        if (!$persisted) {
            return $this->absorb('audio_download_failed', $worker->id, ['url' => $recordingUrl]);
        }

        [$absPath, $publicUrl] = $persisted;

        $start = microtime(true);
        $result = $this->ai->verifyVoice(
            $absPath,
            $worker->voice_embedding_ecapa,
            $worker->voice_embedding_campplus
        );
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$result['success']) {
            $this->audit->log('vapi_verify_ai_failed', 'Worker', $worker->id, [], [
                'error'  => $result['message'],
                'status' => $result['status'],
            ]);
            return $this->successResponse(['ok' => false, 'reason' => 'ai_failed', 'message' => $result['message']]);
        }

        $data = $result['data'] ?? [];

        // Cycle-dispatched calls carry their cycle_id in metadata — attach the
        // result to that cycle so its tallies are accurate. One-off
        // verify-voice calls (no cycle_id) keep landing in "Vapi Continuous".
        $cycle = ($cycleId ? VerificationCycle::find($cycleId) : null)
            ?? VerificationCycle::firstOrCreate(
                ['name' => 'Vapi Continuous'],
                [
                    'cycle_month' => now()->startOfMonth()->toDateString(),
                    'status'      => 'running',
                    'started_at'  => now(),
                ]
            );

        $verification = Verification::create([
            'worker_id'                => $worker->id,
            'cycle_id'                 => $cycle->id,
            'channel'                  => 'ivr',
            'trust_score'              => (int) ($data['score'] ?? 0),
            'verdict'                  => $data['verdict'] ?? 'INCONCLUSIVE',
            'speaker_biometric_score'  => data_get($data, 'layers.biometric_combined'),
            'anti_spoof_score'         => data_get($data, 'layers.anti_spoof'),
            'challenge_response_score' => null,
            'replay_detection_score'   => null,
            'face_liveness_score'      => null,
            'latency_ms'               => $latencyMs,
            'language'                 => null,
            'verified_at'              => now(),
            'recording_url'            => $publicUrl,
            'vapi_call_id'             => $callMeta['call_id'],
            'call_cost'                => $callMeta['cost'],
            'transcript'               => $callMeta['transcript'],
        ]);

        $worker->update(['last_verified_at' => now()]);

        if ($verification->verdict === 'PASS') {
            TriggerSquadDisbursementJob::dispatch($verification);
        } else {
            $this->alert->createFromVerification($verification);
        }

        $this->audit->log('vapi_verify_completed', 'Verification', $verification->id, [], [
            'worker_id' => $worker->id,
            'verdict'   => $verification->verdict,
            'score'     => $verification->trust_score,
            'call_id'   => $callMeta['call_id'],
        ]);

        return $this->successResponse([
            'ok'              => true,
            'verification_id' => $verification->id,
            'verdict'         => $verification->verdict,
            'score'           => $verification->trust_score,
            'recording_url'   => $publicUrl,
        ], 'Verification completed.');
    }

    /**
     * Download the recording from Vapi's CDN and persist it to public storage
     * (so it survives Vapi's URL expiry and is reachable from the dashboard).
     *
     * @return ?array{0: string, 1: string} [absolutePath, publicUrl] or null on failure
     */
    private function persistRecording(string $url, int $workerId): ?array
    {
        $relativeDir = "verifications/{$workerId}";
        $filename    = Str::uuid()->toString() . '.wav';
        $relativePath = $relativeDir . '/' . $filename;

        // Ensure the directory exists on the public disk.
        Storage::disk('public')->makeDirectory($relativeDir);
        $absPath = Storage::disk('public')->path($relativePath);

        try {
            $response = Http::timeout(60)->sink($absPath)->get($url);
            if (!$response->successful() || filesize($absPath) === 0) {
                @unlink($absPath);
                return null;
            }
        } catch (\Throwable $e) {
            @unlink($absPath);
            return null;
        }

        return [$absPath, Storage::url($relativePath)];
    }

    /**
     * Audit + 200-ack helper for handled-but-failed branches. Vapi must always
     * see 200 (except 401 auth) or it retries indefinitely.
     */
    private function absorb(string $reason, ?int $workerId, array $extra = []): JsonResponse
    {
        $this->audit->log('vapi_' . $reason, 'Worker', $workerId, [], $extra);
        return $this->successResponse(['ok' => false, 'reason' => $reason]);
    }
}
