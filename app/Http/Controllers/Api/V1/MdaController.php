<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Mda;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'MDAs', description: 'Ministries, Departments and Agencies')]
class MdaController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/mdas',
        operationId: 'mdaIndex',
        tags: ['MDAs'],
        summary: 'List all MDAs (paginated)',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated MDA list')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(Mda::withCount('workers')->paginate(25));
    }

    #[OA\Post(
        path: '/mdas',
        operationId: 'mdaStore',
        tags: ['MDAs'],
        summary: 'Create a new MDA',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'code', 'state', 'ministry_type', 'contact_email', 'head_name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Federal Ministry of Finance'),
                    new OA\Property(property: 'code', type: 'string', example: 'FMF'),
                    new OA\Property(property: 'state', type: 'string', example: 'Abuja'),
                    new OA\Property(property: 'ministry_type', type: 'string', enum: ['federal', 'state']),
                    new OA\Property(property: 'contact_email', type: 'string', example: 'hr@fmf.gov.ng'),
                    new OA\Property(property: 'head_name', type: 'string', example: 'Dr. Ngozi Okonjo-Iweala'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'MDA created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string',
            'code'          => 'required|string|unique:mdas,code',
            'state'         => 'required|string',
            'ministry_type' => 'required|in:federal,state',
            'contact_email' => 'required|email',
            'head_name'     => 'required|string',
        ]);

        $mda = Mda::create($data);
        $this->audit->log('mda_created', 'Mda', $mda->id, [], $data, $request);
        return $this->successResponse($mda, 'MDA created.', 201);
    }

    #[OA\Get(
        path: '/mdas/{id}',
        operationId: 'mdaShow',
        tags: ['MDAs'],
        summary: 'Get a single MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'MDA detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(Mda::withCount('workers')->with('departments')->findOrFail($id));
    }

    #[OA\Put(
        path: '/mdas/{id}',
        operationId: 'mdaUpdate',
        tags: ['MDAs'],
        summary: 'Update an MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'state', type: 'string'),
                    new OA\Property(property: 'ministry_type', type: 'string', enum: ['federal', 'state']),
                    new OA\Property(property: 'contact_email', type: 'string'),
                    new OA\Property(property: 'head_name', type: 'string'),
                    new OA\Property(property: 'risk_score', type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'MDA updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $mda  = Mda::findOrFail($id);
        $old  = $mda->toArray();
        $data = $request->validate([
            'name'          => 'sometimes|string',
            'state'         => 'sometimes|string',
            'ministry_type' => 'sometimes|in:federal,state',
            'contact_email' => 'sometimes|email',
            'head_name'     => 'sometimes|string',
            'risk_score'    => 'sometimes|numeric',
        ]);
        $mda->update($data);
        $this->audit->log('mda_updated', 'Mda', $id, $old, $data, $request);
        return $this->successResponse($mda->fresh());
    }

    #[OA\Delete(
        path: '/mdas/{id}',
        operationId: 'mdaDestroy',
        tags: ['MDAs'],
        summary: 'Delete an MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'MDA deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $mda = Mda::findOrFail($id);
        $this->audit->log('mda_deleted', 'Mda', $id, $mda->toArray(), [], $request);
        $mda->delete();
        return $this->successResponse([], 'MDA deleted.');
    }

    #[OA\Get(
        path: '/mdas/{id}/workers',
        operationId: 'mdaWorkers',
        tags: ['MDAs'],
        summary: 'List workers belonging to an MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated workers')]
    )]
    public function workers(int $id): JsonResponse
    {
        $mda = Mda::findOrFail($id);
        return $this->successResponse($mda->workers()->with('department')->paginate(25));
    }

    #[OA\Get(
        path: '/mdas/{id}/stats',
        operationId: 'mdaStats',
        tags: ['MDAs'],
        summary: 'Get statistics for an MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'MDA statistics')]
    )]
    public function stats(int $id): JsonResponse
    {
        $mda         = Mda::findOrFail($id);
        $total       = $mda->workers()->count();
        $verified    = $mda->workers()->whereNotNull('last_verified_at')->count();
        $ghostCount  = $mda->workers()->whereHas('alerts', fn($q) => $q->where('status', 'open'))->count();

        return $this->successResponse([
            'mda'           => $mda->name,
            'risk_score'    => $mda->risk_score,
            'total_workers' => $total,
            'verified_pct'  => $total > 0 ? round(($verified / $total) * 100, 2) : 0,
            'ghost_count'   => $ghostCount,
        ]);
    }
}
