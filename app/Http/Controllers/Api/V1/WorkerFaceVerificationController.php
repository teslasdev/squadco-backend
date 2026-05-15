<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FaceVerificationSession;
use App\Models\Worker;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Worker self-serve face verification (auth:worker).
 *
 * Mirrors the admin FaceVerificationController, but the worker is resolved
 * from their portal token instead of an admin-supplied worker_id, and every
 * frame call is ownership-checked so a worker can only drive their OWN
 * session. The actual frame logic (identity, pose, verdict, pending-row fill)
 * is delegated to FaceVerificationController — its frame handlers are public
 * and keyed purely by session_id, so there's no duplication.
 */
#[OA\Tag(name: 'Worker Face Verification', description: 'Worker self-serve 3-frame face verification')]
class WorkerFaceVerificationController extends Controller
{
    public function __construct(
        private FaceVerificationController $face,
        private AuditService $audit,
    ) {}

    // ─── POST /workers-portal/face-verification/start ───────────────────────
    public function start(Request $request): JsonResponse
    {
        $worker = $request->user('worker');

        if (!in_array($worker->verification_channel, ['web', 'both'], true)) {
            return $this->errorResponse(
                'Your account is not set up for face verification (channel=' . $worker->verification_channel . ').',
                422
            );
        }

        if (!$worker->face_enrolled || empty($worker->face_embedding)) {
            return $this->errorResponse('You have not completed face enrolment yet.', 422);
        }

        // Abandon any stale in-flight session for this worker.
        FaceVerificationSession::where('worker_id', $worker->id)
            ->whereIn('status', ['identity_pending', 'pose_right_pending', 'pose_left_pending'])
            ->update(['status' => 'expired']);

        $session = FaceVerificationSession::create([
            'worker_id'     => $worker->id,
            'admin_user_id' => null,
            'status'        => 'identity_pending',
            'started_at'    => now(),
        ]);

        $this->audit->log('worker_face_verify_started', 'FaceVerificationSession', $session->id, [], [
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

    // ─── POST /workers-portal/face-verification/{session}/frame1|2|3 ─────────

    public function frame1(Request $request, int $session_id): JsonResponse
    {
        if ($resp = $this->guardOwnership($request, $session_id)) return $resp;
        return $this->face->frame1($request, $session_id);
    }

    public function frame2(Request $request, int $session_id): JsonResponse
    {
        if ($resp = $this->guardOwnership($request, $session_id)) return $resp;
        return $this->face->frame2($request, $session_id);
    }

    public function frame3(Request $request, int $session_id): JsonResponse
    {
        if ($resp = $this->guardOwnership($request, $session_id)) return $resp;
        return $this->face->frame3($request, $session_id);
    }

    public function show(Request $request, int $session_id): JsonResponse
    {
        if ($resp = $this->guardOwnership($request, $session_id)) return $resp;
        return $this->face->show($session_id);
    }

    /**
     * A worker may only act on a session that belongs to them. Returns a
     * JsonResponse to short-circuit on violation, or null to proceed.
     */
    private function guardOwnership(Request $request, int $sessionId): ?JsonResponse
    {
        $worker = $request->user('worker');
        $session = FaceVerificationSession::find($sessionId);

        if (!$session) {
            return $this->errorResponse('Face verification session not found.', 404);
        }
        if ((int) $session->worker_id !== (int) $worker->id) {
            return $this->errorResponse('This session does not belong to you.', 403);
        }
        return null;
    }
}
