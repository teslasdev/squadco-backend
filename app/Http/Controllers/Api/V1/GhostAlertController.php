<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GhostWorkerAlert;
use App\Services\AlertService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Ghost Worker Alerts', description: 'Ghost worker detection and escalation')]
class GhostAlertController extends Controller
{
    public function __construct(private AlertService $alert, private AuditService $audit) {}

    #[OA\Get(
        path: '/alerts',
        operationId: 'alertIndex',
        tags: ['Ghost Worker Alerts'],
        summary: 'List ghost worker alerts',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'critical'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'mda_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated alerts')]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = GhostWorkerAlert::with('worker.mda', 'verification')
            ->when($request->severity, fn($q) => $q->where('severity', $request->severity))
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->mda_id,   fn($q) => $q->whereHas('worker', fn($q) => $q->where('mda_id', $request->mda_id)));

        return $this->successResponse($q->latest()->paginate(25));
    }

    #[OA\Get(
        path: '/alerts/{id}',
        operationId: 'alertShow',
        tags: ['Ghost Worker Alerts'],
        summary: 'Get a single alert with dispatch history',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Alert detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(
            GhostWorkerAlert::with('worker.mda', 'verification', 'dispatches.agent')->findOrFail($id)
        );
    }

    #[OA\Post(
        path: '/alerts/{id}/block',
        operationId: 'alertBlock',
        tags: ['Ghost Worker Alerts'],
        summary: 'Block salary for a ghost alert',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Salary blocked')]
    )]
    public function block(Request $request, int $id): JsonResponse
    {
        $alert = GhostWorkerAlert::findOrFail($id);
        $this->alert->blockSalary($alert);
        $this->audit->log('alert_salary_blocked', 'GhostWorkerAlert', $id, [], ['status' => 'blocked'], $request);
        return $this->successResponse([], 'Salary blocked.');
    }

    #[OA\Post(
        path: '/alerts/{id}/dispatch',
        operationId: 'alertDispatch',
        tags: ['Ghost Worker Alerts'],
        summary: 'Dispatch nearest field agent to investigate',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Agent dispatched'),
            new OA\Response(response: 422, description: 'No available agent'),
        ]
    )]
    public function dispatch(Request $request, int $id): JsonResponse
    {
        $alert    = GhostWorkerAlert::with('worker')->findOrFail($id);
        $dispatch = $this->alert->dispatchNearestAgent($alert, $alert->worker);

        if (!$dispatch) {
            return $this->errorResponse('No available field agent found.', 422);
        }

        $this->audit->log('agent_dispatched', 'GhostWorkerAlert', $id, [], [], $request);
        return $this->successResponse(['dispatch' => $dispatch], 'Agent dispatched.');
    }

    #[OA\Post(
        path: '/alerts/{id}/refer-icpc',
        operationId: 'alertReferIcpc',
        tags: ['Ghost Worker Alerts'],
        summary: 'Refer an alert to the ICPC',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Alert referred to ICPC')]
    )]
    public function referIcpc(Request $request, int $id): JsonResponse
    {
        $alert = GhostWorkerAlert::findOrFail($id);
        $old   = $alert->toArray();
        $alert->update(['status' => 'referred_icpc']);
        $this->audit->log('alert_referred_icpc', 'GhostWorkerAlert', $id, $old, ['status' => 'referred_icpc'], $request);
        return $this->successResponse([], 'Alert referred to ICPC.');
    }

    #[OA\Post(
        path: '/alerts/{id}/false-positive',
        operationId: 'alertFalsePositive',
        tags: ['Ghost Worker Alerts'],
        summary: 'Mark an alert as a false positive',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Alert marked as false positive')]
    )]
    public function falsePositive(Request $request, int $id): JsonResponse
    {
        $alert = GhostWorkerAlert::findOrFail($id);
        $old   = $alert->toArray();
        $alert->update(['status' => 'false_positive', 'resolved_at' => now(), 'resolved_by' => $request->user()->id]);
        $this->audit->log('alert_false_positive', 'GhostWorkerAlert', $id, $old, ['status' => 'false_positive'], $request);
        return $this->successResponse([], 'Alert marked as false positive.');
    }

    #[OA\Post(
        path: '/alerts/{id}/resolve',
        operationId: 'alertResolve',
        tags: ['Ghost Worker Alerts'],
        summary: 'Resolve a ghost worker alert',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'notes', type: 'string', example: 'Worker physically verified at duty post')]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Alert resolved')]
    )]
    public function resolve(Request $request, int $id): JsonResponse
    {
        $alert = GhostWorkerAlert::findOrFail($id);
        $notes = $request->input('notes', '');
        $this->alert->resolve($alert, $request->user()->id, $notes);
        $this->audit->log('alert_resolved', 'GhostWorkerAlert', $id, [], ['status' => 'resolved'], $request);
        return $this->successResponse([], 'Alert resolved.');
    }
}
