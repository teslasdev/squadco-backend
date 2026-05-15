<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\TriggerSquadDisbursementJob;
use App\Models\FaceVerificationSession;
use App\Models\Verification;
use App\Models\VerificationCycle;
use App\Models\Worker;
use App\Services\AiVerificationService;
use App\Services\AlertService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Face Verification', description: 'Persona-style 3-frame face verification (identity + 2 head-turn liveness checks)')]
class FaceVerificationController extends Controller
{
    /** A face verification session is considered abandoned after this many minutes. */
    private const SESSION_TTL_MINUTES = 10;

    public function __construct(
        private AiVerificationService $ai,
        private AlertService $alert,
        private AuditService $audit
    ) {}

    // ─── POST /face-verification/start ──────────────────────────────────────

    #[OA\Post(
        path: '/face-verification/start',
        operationId: 'faceVerificationStart',
        tags: ['Face Verification'],
        summary: 'Begin a face verification session for a worker',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['worker_id'],
                properties: [new OA\Property(property: 'worker_id', type: 'integer', example: 1)]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Session created — proceed to frame1'),
            new OA\Response(response: 422, description: 'Worker not eligible for face verification'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => 'required|integer|exists:workers,id',
        ]);

        $worker = Worker::findOrFail($data['worker_id']);

        if (!in_array($worker->verification_channel, ['web'], true)) {
            return $this->errorResponse(
                'Worker is not enrolled for web verification (channel=' . $worker->verification_channel . ').',
                422
            );
        }

        if (!$worker->face_enrolled || empty($worker->face_embedding)) {
            return $this->errorResponse('Worker has not completed face enrolment.', 422);
        }

        // Abandon any stale in-flight session for this worker.
        FaceVerificationSession::where('worker_id', $worker->id)
            ->whereIn('status', ['identity_pending', 'pose_right_pending', 'pose_left_pending'])
            ->update(['status' => 'expired']);

        $session = FaceVerificationSession::create([
            'worker_id'     => $worker->id,
            'admin_user_id' => optional($request->user())->id,
            'status'        => 'identity_pending',
            'started_at'    => now(),
        ]);

        $this->audit->log('face_verify_session_started', 'FaceVerificationSession', $session->id, [], [
            'worker_id' => $worker->id,
        ], $request);

        return $this->successResponse([
            'session_id'         => $session->id,
            'worker_id'          => $worker->id,
            'status'             => $session->status,
            'next_step'          => 'frame1',
            'expected_direction' => null,
        ], 'Face verification session started.', 201);
    }

    // ─── POST /face-verification/{id}/frame1 ────────────────────────────────

    #[OA\Post(
        path: '/face-verification/{session_id}/frame1',
        operationId: 'faceVerificationFrame1',
        tags: ['Face Verification'],
        summary: 'Submit frame 1 (look straight) — runs identity check',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'session_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['image'],
                    properties: [new OA\Property(property: 'image', type: 'string', format: 'binary')]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Identity check passed — proceed to frame2 (turn right)'),
            new OA\Response(response: 422, description: 'Identity FAIL or bad image — session ended'),
            new OA\Response(response: 503, description: 'AI service unreachable'),
        ]
    )]
    public function frame1(Request $request, int $session_id): JsonResponse
    {
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);

        $session = $this->loadActiveSession($session_id, 'identity_pending');
        if ($session instanceof JsonResponse) return $session;

        $worker = $session->worker;

        // Store image
        [$absPath, $url] = $this->storeFrame($request->file('image'), $session->id, 1);

        // Call AI for identity + passive liveness
        $start = microtime(true);
        $result = $this->ai->verifyFace($absPath, $worker->face_embedding);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if (!$result['success']) {
            $this->markSessionFailed($session, 'ai_failed_frame1', $request, $latency, [
                'error'  => $result['message'],
                'status' => $result['status'],
            ]);
            $httpStatus = $result['status'] === 422 ? 422 : 503;
            return $this->errorResponse($result['message'], $httpStatus, ['ai_error' => $result]);
        }

        $data = $result['data'];
        $identityVerdict = $data['verdict'] ?? 'INCONCLUSIVE';
        $identityScore   = (int) ($data['score'] ?? 0);
        $spoofProb       = (float) ($data['spoof_prob'] ?? 0);

        $session->update([
            'frame1_url'         => $url,
            'identity_score'     => $identityScore,
            'identity_verdict'   => $identityVerdict,
            'identity_spoof_prob' => $spoofProb,
            'latency_ms'         => $latency,
        ]);

        // FAIL on frame1 = different person. End session immediately, write Verification row.
        if ($identityVerdict === 'FAIL') {
            $verification = $this->writeVerification($worker, $session, $identityScore, 'FAIL', 'identity_mismatch');
            $this->alert->createFromVerification($verification);

            $session->update([
                'status'         => 'failed',
                'verdict'        => 'FAIL',
                'failure_reason' => 'identity_mismatch',
                'completed_at'   => now(),
                'verification_id' => $verification->id,
            ]);

            $this->audit->log('face_verify_identity_fail', 'FaceVerificationSession', $session->id, [], [
                'worker_id' => $worker->id, 'score' => $identityScore,
            ], $request);

            return $this->errorResponse('Identity verification failed — face does not match enrolment.', 422, [
                'session_id'      => $session->id,
                'verification_id' => $verification->id,
                'verdict'         => 'FAIL',
                'score'           => $identityScore,
            ]);
        }

        // PASS or INCONCLUSIVE → proceed to head-turn check
        $session->update(['status' => 'pose_right_pending']);

        $this->audit->log('face_verify_identity_passed', 'FaceVerificationSession', $session->id, [], [
            'verdict' => $identityVerdict, 'score' => $identityScore,
        ], $request);

        return $this->successResponse([
            'session_id'         => $session->id,
            'status'             => $session->status,
            'next_step'          => 'frame2',
            'expected_direction' => 'right',
            'identity'           => [
                'verdict' => $identityVerdict,
                'score'   => $identityScore,
            ],
        ], 'Identity check passed — capture frame 2 (turn head right).');
    }

    // ─── POST /face-verification/{id}/frame2 ────────────────────────────────

    #[OA\Post(
        path: '/face-verification/{session_id}/frame2',
        operationId: 'faceVerificationFrame2',
        tags: ['Face Verification'],
        summary: 'Submit frame 2 (turn right) — runs head-turn liveness check',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'session_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Right turn passed — proceed to frame3 (turn left)'),
            new OA\Response(response: 422, description: 'Right turn failed or bad image — session ended'),
        ]
    )]
    public function frame2(Request $request, int $session_id): JsonResponse
    {
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);

        $session = $this->loadActiveSession($session_id, 'pose_right_pending');
        if ($session instanceof JsonResponse) return $session;

        $worker = $session->worker;

        [$absPath, $url] = $this->storeFrame($request->file('image'), $session->id, 2);

        // Use frame1 as the reference, frame2 as the pose
        $refPath = Storage::disk('public')->path(ltrim(str_replace('/storage/', '', $session->frame1_url), '/'));

        $start = microtime(true);
        $result = $this->ai->verifyFacePose($refPath, $absPath, 'right');
        $latency = (int) round((microtime(true) - $start) * 1000);

        if (!$result['success']) {
            $this->markSessionFailed($session, 'ai_failed_frame2', $request, $latency, [
                'error' => $result['message'], 'status' => $result['status'],
            ]);
            $httpStatus = $result['status'] === 422 ? 422 : 503;
            return $this->errorResponse($result['message'], $httpStatus, ['ai_error' => $result]);
        }

        $passed = (bool) ($result['data']['passed'] ?? false);
        $deltaDeg = (float) ($result['data']['delta_degrees'] ?? 0);

        $session->update([
            'frame2_url'           => $url,
            'pose_right_passed'    => $passed,
            'pose_right_delta_deg' => $deltaDeg,
            'latency_ms'           => ($session->latency_ms ?? 0) + $latency,
        ]);

        if (!$passed) {
            $verification = $this->writeVerification($worker, $session, $session->identity_score, 'FAIL', 'pose_right_failed');
            $this->alert->createFromVerification($verification);

            $session->update([
                'status'          => 'failed',
                'verdict'         => 'FAIL',
                'failure_reason'  => 'pose_right_failed',
                'completed_at'    => now(),
                'verification_id' => $verification->id,
            ]);

            $this->audit->log('face_verify_pose_right_fail', 'FaceVerificationSession', $session->id, [], [
                'delta_degrees' => $deltaDeg,
            ], $request);

            return $this->errorResponse('Head-turn challenge failed — please turn your head clearly to the right.', 422, [
                'session_id'      => $session->id,
                'verification_id' => $verification->id,
                'verdict'         => 'FAIL',
                'delta_degrees'   => $deltaDeg,
            ]);
        }

        $session->update(['status' => 'pose_left_pending']);

        return $this->successResponse([
            'session_id'         => $session->id,
            'status'             => $session->status,
            'next_step'          => 'frame3',
            'expected_direction' => 'left',
            'pose_right'         => [
                'passed'        => true,
                'delta_degrees' => $deltaDeg,
            ],
        ], 'Right-turn passed — capture frame 3 (turn head left).');
    }

    // ─── POST /face-verification/{id}/frame3 ────────────────────────────────

    #[OA\Post(
        path: '/face-verification/{session_id}/frame3',
        operationId: 'faceVerificationFrame3',
        tags: ['Face Verification'],
        summary: 'Submit frame 3 (turn left) — final liveness check; produces verdict',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'session_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Session complete — verdict in response'),
            new OA\Response(response: 422, description: 'Left turn failed or bad image — session ended'),
        ]
    )]
    public function frame3(Request $request, int $session_id): JsonResponse
    {
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);

        $session = $this->loadActiveSession($session_id, 'pose_left_pending');
        if ($session instanceof JsonResponse) return $session;

        $worker = $session->worker;

        [$absPath, $url] = $this->storeFrame($request->file('image'), $session->id, 3);

        $refPath = Storage::disk('public')->path(ltrim(str_replace('/storage/', '', $session->frame1_url), '/'));

        $start = microtime(true);
        $result = $this->ai->verifyFacePose($refPath, $absPath, 'left');
        $latency = (int) round((microtime(true) - $start) * 1000);

        if (!$result['success']) {
            $this->markSessionFailed($session, 'ai_failed_frame3', $request, $latency, [
                'error' => $result['message'], 'status' => $result['status'],
            ]);
            $httpStatus = $result['status'] === 422 ? 422 : 503;
            return $this->errorResponse($result['message'], $httpStatus, ['ai_error' => $result]);
        }

        $passed = (bool) ($result['data']['passed'] ?? false);
        $deltaDeg = (float) ($result['data']['delta_degrees'] ?? 0);

        $session->update([
            'frame3_url'          => $url,
            'pose_left_passed'    => $passed,
            'pose_left_delta_deg' => $deltaDeg,
            'latency_ms'          => ($session->latency_ms ?? 0) + $latency,
        ]);

        // Compute final verdict
        $allPassed = $session->identity_verdict === 'PASS'
                  && $session->pose_right_passed
                  && $passed;

        if ($allPassed) {
            $finalVerdict = 'PASS';
            $failureReason = null;
        } elseif (!$passed) {
            $finalVerdict = 'FAIL';
            $failureReason = 'pose_left_failed';
        } elseif ($session->identity_verdict === 'INCONCLUSIVE') {
            $finalVerdict = 'INCONCLUSIVE';
            $failureReason = 'identity_inconclusive';
        } else {
            $finalVerdict = 'FAIL';
            $failureReason = 'unknown';
        }

        $verification = $this->writeVerification($worker, $session, $session->identity_score, $finalVerdict, $failureReason);

        if ($finalVerdict === 'PASS') {
            TriggerSquadDisbursementJob::dispatch($verification);
            $sessionStatus = 'completed';
        } else {
            $this->alert->createFromVerification($verification);
            $sessionStatus = $finalVerdict === 'FAIL' ? 'failed' : 'completed';
        }

        $session->update([
            'status'          => $sessionStatus,
            'verdict'         => $finalVerdict,
            'failure_reason'  => $failureReason,
            'completed_at'    => now(),
            'verification_id' => $verification->id,
        ]);

        $worker->update(['last_verified_at' => now()]);

        $this->audit->log('face_verify_completed', 'Verification', $verification->id, [], [
            'worker_id' => $worker->id,
            'session_id' => $session->id,
            'verdict' => $finalVerdict,
            'score' => $session->identity_score,
        ], $request);

        $http = $finalVerdict === 'PASS' ? 200 : 422;
        return $finalVerdict === 'PASS'
            ? $this->successResponse([
                'session_id'      => $session->id,
                'verification_id' => $verification->id,
                'verdict'         => $finalVerdict,
                'score'           => $session->identity_score,
                'pose_left'       => ['passed' => true, 'delta_degrees' => $deltaDeg],
            ], 'Face verification completed.', 200)
            : $this->errorResponse(
                $failureReason === 'pose_left_failed'
                    ? 'Head-turn challenge failed — please turn your head clearly to the left.'
                    : 'Face verification was inconclusive.',
                $http,
                [
                    'session_id'      => $session->id,
                    'verification_id' => $verification->id,
                    'verdict'         => $finalVerdict,
                    'failure_reason'  => $failureReason,
                ]
            );
    }

    // ─── GET /face-verification/{id} ────────────────────────────────────────

    #[OA\Get(
        path: '/face-verification/{session_id}',
        operationId: 'faceVerificationShow',
        tags: ['Face Verification'],
        summary: 'Inspect the state of a face verification session (for polling/debug)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'session_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Session detail')]
    )]
    public function show(int $session_id): JsonResponse
    {
        $session = FaceVerificationSession::with('worker', 'verification')->findOrFail($session_id);
        return $this->successResponse($session);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Loads the session if it's in the expected pending status and not expired.
     * Returns the model — or a JsonResponse if the caller should short-circuit.
     */
    private function loadActiveSession(int $sessionId, string $expectedStatus): FaceVerificationSession|JsonResponse
    {
        $session = FaceVerificationSession::with('worker')->find($sessionId);
        if (!$session) {
            return $this->errorResponse('Face verification session not found.', 404);
        }

        if ($session->started_at && $session->started_at->lt(now()->subMinutes(self::SESSION_TTL_MINUTES))) {
            $session->update(['status' => 'expired', 'failure_reason' => 'session_expired']);
            return $this->errorResponse('Session expired. Please start a new face verification.', 410);
        }

        if ($session->status !== $expectedStatus) {
            return $this->errorResponse(
                "Session is in '{$session->status}' state, expected '{$expectedStatus}'.",
                422
            );
        }

        return $session;
    }

    /**
     * Stores an uploaded frame to public storage and returns [absPath, publicUrl].
     */
    private function storeFrame($file, int $sessionId, int $frameNumber): array
    {
        $ext      = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = "frame{$frameNumber}.{$ext}";
        $path     = $file->storeAs("face-sessions/{$sessionId}", $filename, 'public');
        $absPath  = Storage::disk('public')->path($path);
        $url      = Storage::url($path);
        return [$absPath, $url];
    }

    /**
     * Records the face verification result.
     *
     * If a cycle seeded a PENDING row for this worker (verdict NULL,
     * verified_at NULL — see RunVerificationCycleJob), we FILL THAT ROW IN so
     * the completion attaches to the cycle that requested it and the cycle's
     * tallies are accurate. Otherwise (ad-hoc admin check with no pending
     * cycle row) we create a fresh row on the "Continuous" cycle as before.
     */
    private function writeVerification(Worker $worker, FaceVerificationSession $session, int $score, string $verdict, ?string $failureReason): Verification
    {
        $pending = Verification::where('worker_id', $worker->id)
            ->whereNull('verdict')
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if ($pending) {
            $pending->update([
                'channel'                  => 'app',
                'trust_score'              => $score,
                'verdict'                  => $verdict,
                'face_liveness_score'      => $score,
                'speaker_biometric_score'  => null,
                'anti_spoof_score'         => null,
                'challenge_response_score' => null,
                'replay_detection_score'   => null,
                'latency_ms'               => $session->latency_ms,
                'verified_at'              => now(),
            ]);
            return $pending->fresh();
        }

        $cycle = VerificationCycle::firstOrCreate(
            ['name' => 'Continuous'],
            [
                'cycle_month' => now()->startOfMonth()->toDateString(),
                'status'      => 'running',
                'started_at'  => now(),
            ]
        );

        return Verification::create([
            'worker_id'                => $worker->id,
            'cycle_id'                 => $cycle->id,
            'channel'                  => 'app',
            'trust_score'              => $score,
            'verdict'                  => $verdict,
            'face_liveness_score'      => $score,
            'speaker_biometric_score'  => null,
            'anti_spoof_score'         => null,
            'challenge_response_score' => null,
            'replay_detection_score'   => null,
            'latency_ms'               => $session->latency_ms,
            'language'                 => null,
            'verified_at'              => now(),
        ]);
    }

    private function markSessionFailed(FaceVerificationSession $session, string $reason, Request $request, int $latency, array $extra = []): void
    {
        $session->update([
            'status'         => 'failed',
            'failure_reason' => $reason,
            'completed_at'   => now(),
            'latency_ms'     => ($session->latency_ms ?? 0) + $latency,
        ]);

        $this->audit->log('face_verify_' . $reason, 'FaceVerificationSession', $session->id, [], $extra, $request);
    }
}
