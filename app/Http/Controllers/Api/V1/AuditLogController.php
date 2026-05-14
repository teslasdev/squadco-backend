<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Audit Logs', description: 'Immutable audit trail of all system actions')]
class AuditLogController extends Controller
{
    
    #[OA\Get(
        path: '/audit-logs',
        operationId: 'auditLogIndex',
        tags: ['Audit Logs'],
        summary: 'List audit logs',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'entity_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated audit logs')]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = AuditLog::with('user')
            ->when($request->action,      fn($q) => $q->where('action', $request->action))
            ->when($request->entity_type, fn($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->user_id,     fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->from,        fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to,          fn($q) => $q->whereDate('created_at', '<=', $request->to));

        return $this->successResponse($q->latest('created_at')->paginate(50));
    }

    #[OA\Get(
        path: '/audit-logs/{id}',
        operationId: 'auditLogShow',
        tags: ['Audit Logs'],
        summary: 'Get a single audit log entry',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Audit log detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(AuditLog::with('user')->findOrFail($id));
    }
}
