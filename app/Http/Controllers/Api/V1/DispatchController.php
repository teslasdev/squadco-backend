<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentDispatch;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dispatches', description: 'Field agent dispatch management')]
class DispatchController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/dispatches',
        operationId: 'dispatchIndex',
        tags: ['Dispatches'],
        summary: 'List all dispatches',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated dispatches')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(AgentDispatch::with('agent', 'worker', 'alert')->latest()->paginate(25));
    }

    #[OA\Get(
        path: '/dispatches/{id}',
        operationId: 'dispatchShow',
        tags: ['Dispatches'],
        summary: 'Get a single dispatch',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Dispatch detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(AgentDispatch::with('agent', 'worker', 'alert')->findOrFail($id));
    }

    #[OA\Put(
        path: '/dispatches/{id}/complete',
        operationId: 'dispatchComplete',
        tags: ['Dispatches'],
        summary: 'Mark a dispatch as completed',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'notes', type: 'string', example: 'Worker confirmed present and alive')]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Dispatch completed')]
    )]
    public function complete(Request $request, int $id): JsonResponse
    {
        $dispatch = AgentDispatch::findOrFail($id);
        $old      = $dispatch->toArray();
        $dispatch->update([
            'status'       => 'completed',
            'notes'        => $request->input('notes'),
            'completed_at' => now(),
        ]);
        $this->audit->log('dispatch_completed', 'AgentDispatch', $id, $old, ['status' => 'completed'], $request);
        return $this->successResponse([], 'Dispatch marked as completed.');
    }

    #[OA\Put(
        path: '/dispatches/{id}/fail',
        operationId: 'dispatchFail',
        tags: ['Dispatches'],
        summary: 'Mark a dispatch as failed',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'notes', type: 'string', example: 'Address does not exist')]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Dispatch marked as failed')]
    )]
    public function fail(Request $request, int $id): JsonResponse
    {
        $dispatch = AgentDispatch::findOrFail($id);
        $old      = $dispatch->toArray();
        $dispatch->update([
            'status' => 'failed',
            'notes'  => $request->input('notes'),
        ]);
        $this->audit->log('dispatch_failed', 'AgentDispatch', $id, $old, ['status' => 'failed'], $request);
        return $this->successResponse([], 'Dispatch marked as failed.');
    }
}
