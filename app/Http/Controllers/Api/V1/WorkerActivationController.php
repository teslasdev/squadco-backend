<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WorkerEnrolledEvent;
use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * Admin-side activation flow: review self-enrolled (or kiosk-onboarded) workers,
 * approve them into the active pool, reject with reason, or re-issue a QR/token
 * if the worker needs to redo enrolment.
 */
#[OA\Tag(name: 'Worker Activation', description: 'Admin review + activation of pending workers')]
class WorkerActivationController extends Controller
{
    public function __construct(private AuditService $audit) {}

    // ─── GET /workers/pending-activation ────────────────────────────────────

    #[OA\Get(
        path: '/workers/pending-activation',
        operationId: 'workersPendingActivation',
        tags: ['Worker Activation'],
        summary: 'List workers awaiting admin review (status=pending_review)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'mda_id',  in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['phone', 'web'])),
            new OA\Parameter(name: 'search',  in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated pending workers')]
    )]
    public function listPending(Request $request): JsonResponse
    {
        $q = Worker::with('mda', 'department')
            ->where('status', 'pending_review')
            ->when($request->mda_id, fn($q) => $q->where('mda_id', $request->mda_id))
            ->when($request->channel, fn($q) => $q->where('verification_channel', $request->channel))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('full_name', 'like', "%{$request->search}%")
                  ->orWhere('ippis_id', 'like', "%{$request->search}%");
            }));

        return $this->successResponse($q->latest('updated_at')->paginate(25));
    }

    // ─── POST /workers/{id}/activate ────────────────────────────────────────

    #[OA\Post(
        path: '/workers/{id}/activate',
        operationId: 'workerActivate',
        tags: ['Worker Activation'],
        summary: 'Approve a pending worker — flips status to active, eligible for automated verification',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker activated'),
            new OA\Response(response: 422, description: 'Worker is not in pending_review state'),
        ]
    )]
    public function activate(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);

        if ($worker->status !== 'pending_review') {
            return $this->errorResponse(
                "Cannot activate worker — current status is '{$worker->status}', expected 'pending_review'.",
                422
            );
        }

        $activationCode = self::generateActivationCode();

        $worker->update([
            'status'                    => 'active',
            'enrolled_at'               => $worker->enrolled_at ?? now(),
            'activation_code'           => $activationCode,
            'activation_code_issued_at' => now(),
        ]);

        WorkerEnrolledEvent::dispatch($worker);

        $this->audit->log('worker_activated', 'Worker', $worker->id, [], [
            'status'      => 'active',
            'enrolled_at' => $worker->enrolled_at,
        ], $request);

        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $signupUrl = $appUrl . '/workers/signup?code=' . $activationCode;

        return $this->successResponse([
            'worker_id'       => $worker->id,
            'status'          => $worker->status,
            'enrolled_at'     => $worker->enrolled_at,
            'activation_code' => $activationCode,
            'signup_url'      => $signupUrl,
        ], 'Worker activated. Share the activation code or signup URL with them.');
    }

    // ─── POST /workers/{id}/issue-activation-code ──────────────────────────
    //
    // Re-issues an activation code for an already-active worker who needs to
    // re-create their portal account (e.g. lost their original code, never
    // signed up, or admin wants to reset their access).

    #[OA\Post(
        path: '/workers/{id}/issue-activation-code',
        operationId: 'workerIssueActivationCode',
        tags: ['Worker Activation'],
        summary: 'Reissue the activation code for an active worker (e.g. they lost the original)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Fresh activation code'),
            new OA\Response(response: 422, description: 'Worker must be active'),
        ]
    )]
    public function issueActivationCode(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);

        if ($worker->status !== 'active') {
            return $this->errorResponse(
                "Activation codes can only be issued for active workers. Current status: '{$worker->status}'.",
                422
            );
        }

        $code = self::generateActivationCode();

        $worker->update([
            'activation_code'           => $code,
            'activation_code_issued_at' => now(),
            // Reset password so worker must re-claim via signup. Keeps old portal
            // session tokens valid until they explicitly log out, but the new
            // signup will revoke them via createToken naming if needed.
            'account_created_at'        => null,
            'password'                  => null,
        ]);

        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $signupUrl = $appUrl . '/workers/signup?code=' . $code;

        $this->audit->log('worker_activation_code_reissued', 'Worker', $worker->id, [], [
            'signup_url' => $signupUrl,
        ], $request);

        return $this->successResponse([
            'worker_id'       => $worker->id,
            'activation_code' => $code,
            'signup_url'      => $signupUrl,
        ], 'Activation code reissued.');
    }

    /**
     * Generate a unique 8-character alphanumeric activation code.
     * Excludes ambiguous characters (0/O, 1/I/L) for printing/reading clarity.
     */
    protected static function generateActivationCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Worker::where('activation_code', $code)->exists());
        return $code;
    }

    // ─── POST /workers/{id}/reject ──────────────────────────────────────────

    #[OA\Post(
        path: '/workers/{id}/reject',
        operationId: 'workerReject',
        tags: ['Worker Activation'],
        summary: 'Reject a pending worker — flips status to rejected with admin reason',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [new OA\Property(property: 'reason', type: 'string', example: 'Face photo does not match employment record')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Worker rejected'),
            new OA\Response(response: 422, description: 'Worker is not in pending_review state'),
        ]
    )]
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $worker = Worker::findOrFail($id);

        if ($worker->status !== 'pending_review') {
            return $this->errorResponse(
                "Cannot reject worker — current status is '{$worker->status}', expected 'pending_review'.",
                422
            );
        }

        $worker->update(['status' => 'rejected']);

        $this->audit->log('worker_rejected', 'Worker', $worker->id, [], [
            'reason' => $data['reason'],
            'status' => 'rejected',
        ], $request);

        return $this->successResponse([
            'worker_id' => $worker->id,
            'status'    => $worker->status,
            'reason'    => $data['reason'],
        ], 'Worker rejected.');
    }

    // ─── POST /workers/{id}/issue-qr ────────────────────────────────────────

    #[OA\Post(
        path: '/workers/{id}/issue-qr',
        operationId: 'workerIssueQr',
        tags: ['Worker Activation'],
        summary: 'Issue (or reissue) a self-enrol token for a worker — returns the URL to encode in a QR code',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Fresh self-enrol URL'),
            new OA\Response(response: 422, description: 'Worker is already active or blocked'),
        ]
    )]
    public function issueQr(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);

        // Only allow QR issuance for workers who haven't been activated yet (or were rejected).
        if (!in_array($worker->status, ['pending_self_enrol', 'pending_review', 'rejected'], true)) {
            return $this->errorResponse(
                "Cannot issue QR for worker in status '{$worker->status}'. QR is only valid for workers awaiting or redoing enrolment.",
                422
            );
        }

        // Reset to pending_self_enrol with a fresh token. Any previous token is voided.
        $newToken = (string) Str::uuid();
        $worker->update([
            'status'           => 'pending_self_enrol',
            'onboarding_token' => $newToken,
        ]);

        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $url = $appUrl . '/self-enrol/' . $newToken;

        $this->audit->log('worker_qr_issued', 'Worker', $worker->id, [], [
            'self_enrol_url' => $url,
        ], $request);

        return $this->successResponse([
            'worker_id'       => $worker->id,
            'self_enrol_url'  => $url,
            'onboarding_token' => $newToken,
            'status'          => $worker->status,
        ], 'Self-enrol QR/URL issued.');
    }
}
