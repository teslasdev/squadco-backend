<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SquadPayment;
use App\Models\Worker;
use App\Services\SquadPaymentService;
use App\Services\AuditService;
use App\Jobs\TriggerSquadDisbursementJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Payments', description: 'Squad payroll disbursement management')]
class PaymentController extends Controller
{
    public function __construct(private SquadPaymentService $squad, private AuditService $audit) {}

    #[OA\Get(
        path: '/payments',
        operationId: 'paymentIndex',
        tags: ['Payments'],
        summary: 'List payments',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'released', 'blocked', 'failed'])),
            new OA\Parameter(name: 'cycle_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'mda_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated payments')]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = SquadPayment::with('worker.mda', 'cycle')
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->cycle_id, fn($q) => $q->where('cycle_id', $request->cycle_id))
            ->when($request->mda_id,   fn($q) => $q->whereHas('worker', fn($q) => $q->where('mda_id', $request->mda_id)));

        return $this->successResponse($q->latest()->paginate(25));
    }

    #[OA\Get(
        path: '/payments/{id}',
        operationId: 'paymentShow',
        tags: ['Payments'],
        summary: 'Get a single payment record',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Payment detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(SquadPayment::with('worker', 'cycle', 'verification')->findOrFail($id));
    }

    #[OA\Post(
        path: '/payments/release',
        operationId: 'paymentRelease',
        tags: ['Payments'],
        summary: 'Bulk-release payments for all PASS verifications in a cycle',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cycle_id'],
                properties: [new OA\Property(property: 'cycle_id', type: 'integer', example: 1)]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: 'Disbursement jobs queued'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function release(Request $request): JsonResponse
    {
        $data  = $request->validate(['cycle_id' => 'required|exists:verification_cycles,id']);
        $count = 0;

        $verifications = \App\Models\Verification::where('cycle_id', $data['cycle_id'])
            ->where('verdict', 'PASS')
            ->where('salary_released', false)
            ->get();

        foreach ($verifications as $v) {
            TriggerSquadDisbursementJob::dispatch($v);
            $count++;
        }

        $this->audit->log('payments_bulk_released', 'VerificationCycle', $data['cycle_id'], [], ['count' => $count], $request);
        return $this->successResponse([], "{$count} disbursement(s) queued.", 202);
    }

    #[OA\Post(
        path: '/payments/block/{worker_id}',
        operationId: 'paymentBlockWorker',
        tags: ['Payments'],
        summary: 'Block all pending payments for a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker payments blocked'),
            new OA\Response(response: 404, description: 'Worker not found'),
        ]
    )]
    public function blockWorker(Request $request, int $worker_id): JsonResponse
    {
        Worker::findOrFail($worker_id)->update(['status' => 'blocked']);
        SquadPayment::where('worker_id', $worker_id)->where('status', 'pending')
            ->update(['status' => 'blocked']);
        $this->audit->log('payment_blocked', 'Worker', $worker_id, [], ['status' => 'blocked'], $request);
        return $this->successResponse([], 'Worker payments blocked.');
    }
}
