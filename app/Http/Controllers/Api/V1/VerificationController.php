<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Services\TrustScoreService;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Jobs\TriggerSquadDisbursementJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Verifications', description: 'AI proof-of-life verification results')]
class VerificationController extends Controller
{
    public function __construct(
        private TrustScoreService $trustScore,
        private AlertService $alert,
        private AuditService $audit
    ) {}

    #[OA\Get(
        path: '/verifications',
        operationId: 'verificationIndex',
        tags: ['Verifications'],
        summary: 'List verifications',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cycle_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'worker_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'verdict', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['PASS', 'FAIL', 'INCONCLUSIVE'])),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ivr', 'app', 'agent'])),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated verifications')]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = Verification::with('worker', 'cycle')
            ->when($request->cycle_id,  fn($q) => $q->where('cycle_id', $request->cycle_id))
            ->when($request->worker_id, fn($q) => $q->where('worker_id', $request->worker_id))
            ->when($request->verdict,   fn($q) => $q->where('verdict', $request->verdict))
            ->when($request->channel,   fn($q) => $q->where('channel', $request->channel));

        return $this->successResponse($q->latest()->paginate(25));
    }

    #[OA\Post(
        path: '/verifications',
        operationId: 'verificationStore',
        tags: ['Verifications'],
        summary: 'Submit a new verification result',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['worker_id', 'cycle_id', 'channel', 'challenge_response_score', 'speaker_biometric_score', 'anti_spoof_score', 'replay_detection_score', 'face_liveness_score'],
                properties: [
                    new OA\Property(property: 'worker_id', type: 'integer', example: 1),
                    new OA\Property(property: 'cycle_id', type: 'integer', example: 1),
                    new OA\Property(property: 'channel', type: 'string', enum: ['ivr', 'app', 'agent']),
                    new OA\Property(property: 'challenge_response_score', type: 'integer', minimum: 0, maximum: 100, example: 85),
                    new OA\Property(property: 'speaker_biometric_score', type: 'integer', minimum: 0, maximum: 100, example: 90),
                    new OA\Property(property: 'anti_spoof_score', type: 'integer', minimum: 0, maximum: 100, example: 88),
                    new OA\Property(property: 'replay_detection_score', type: 'integer', minimum: 0, maximum: 100, example: 92),
                    new OA\Property(property: 'face_liveness_score', type: 'integer', minimum: 0, maximum: 100, example: 80),
                    new OA\Property(property: 'latency_ms', type: 'integer', example: 340),
                    new OA\Property(property: 'language', type: 'string', enum: ['yoruba', 'hausa', 'igbo', 'pidgin', 'english']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Verification recorded, trust score computed'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'               => 'required|exists:workers,id',
            'cycle_id'                => 'required|exists:verification_cycles,id',
            'channel'                 => 'required|in:ivr,app,agent',
            'challenge_response_score' => 'required|integer|min:0|max:100',
            'speaker_biometric_score' => 'required|integer|min:0|max:100',
            'anti_spoof_score'        => 'required|integer|min:0|max:100',
            'replay_detection_score'  => 'required|integer|min:0|max:100',
            'face_liveness_score'     => 'required|integer|min:0|max:100',
            'latency_ms'              => 'nullable|integer',
            'language'                => 'nullable|in:yoruba,hausa,igbo,pidgin,english',
        ]);

        $result = $this->trustScore->calculate(
            $data['challenge_response_score'],
            $data['speaker_biometric_score'],
            $data['anti_spoof_score'],
            $data['replay_detection_score'],
            $data['face_liveness_score']
        );

        $data['trust_score']  = $result['score'];
        $data['verdict']      = $result['verdict'];
        $data['verified_at']  = now();

        $verification = Verification::create($data);

        if ($result['verdict'] === 'PASS') {
            TriggerSquadDisbursementJob::dispatch($verification);
        } else {
            $this->alert->createFromVerification($verification);
        }

        $this->audit->log('verification_submitted', 'Verification', $verification->id, [], $data, $request);

        return $this->successResponse($verification, 'Verification recorded.', 201);
    }

    #[OA\Get(
        path: '/verifications/{id}',
        operationId: 'verificationShow',
        tags: ['Verifications'],
        summary: 'Get a single verification',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Verification detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(Verification::with('worker', 'cycle', 'alerts')->findOrFail($id));
    }

    #[OA\Post(
        path: '/verifications/{id}/override',
        operationId: 'verificationOverride',
        tags: ['Verifications'],
        summary: 'Manually override a verification verdict',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['verdict', 'reason'],
                properties: [
                    new OA\Property(property: 'verdict', type: 'string', enum: ['PASS', 'FAIL', 'INCONCLUSIVE']),
                    new OA\Property(property: 'reason', type: 'string', example: 'Corrected after biometric re-test'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Verdict overridden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function override(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'verdict' => 'required|in:PASS,FAIL,INCONCLUSIVE',
            'reason'  => 'required|string',
        ]);

        $verification = Verification::findOrFail($id);
        $old          = $verification->toArray();

        $verification->update(['verdict' => $data['verdict']]);

        $this->audit->log('verification_overridden', 'Verification', $id, $old, $data, $request);

        return $this->successResponse(['verification' => $verification->fresh()], 'Verdict overridden.');
    }
}
