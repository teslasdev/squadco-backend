<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FieldAgent;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Field Agents', description: 'Physical verification field agents')]
class FieldAgentController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/agents',
        operationId: 'agentIndex',
        tags: ['Field Agents'],
        summary: 'List all field agents',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated agents')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(FieldAgent::with('user')->paginate(25));
    }

    #[OA\Post(
        path: '/agents',
        operationId: 'agentStore',
        tags: ['Field Agents'],
        summary: 'Register a new field agent',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id', 'agent_code', 'name', 'phone', 'state', 'lga'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', example: 5),
                    new OA\Property(property: 'agent_code', type: 'string', example: 'AGT-001'),
                    new OA\Property(property: 'name', type: 'string', example: 'Emeka Obi'),
                    new OA\Property(property: 'phone', type: 'string', example: '08090001234'),
                    new OA\Property(property: 'state', type: 'string', example: 'Anambra'),
                    new OA\Property(property: 'lga', type: 'string', example: 'Awka South'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Agent registered'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'agent_code' => 'required|string|unique:field_agents,agent_code',
            'name'       => 'required|string',
            'phone'      => 'required|string',
            'state'      => 'required|string',
            'lga'        => 'required|string',
        ]);
        $agent = FieldAgent::create($data);
        $this->audit->log('agent_created', 'FieldAgent', $agent->id, [], $data, $request);
        return $this->successResponse($agent, 'Agent registered.', 201);
    }

    #[OA\Get(
        path: '/agents/{id}',
        operationId: 'agentShow',
        tags: ['Field Agents'],
        summary: 'Get a single field agent',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Agent detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(FieldAgent::with('user')->findOrFail($id));
    }

    #[OA\Put(
        path: '/agents/{id}',
        operationId: 'agentUpdate',
        tags: ['Field Agents'],
        summary: 'Update a field agent',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'state', type: 'string'),
                    new OA\Property(property: 'lga', type: 'string'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Agent updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $agent = FieldAgent::findOrFail($id);
        $old   = $agent->toArray();
        $data  = $request->validate([
            'name'   => 'sometimes|string',
            'phone'  => 'sometimes|string',
            'state'  => 'sometimes|string',
            'lga'    => 'sometimes|string',
            'status' => 'sometimes|in:active,inactive',
        ]);
        $agent->update($data);
        $this->audit->log('agent_updated', 'FieldAgent', $id, $old, $data, $request);
        return $this->successResponse($agent->fresh());
    }

    #[OA\Get(
        path: '/agents/{id}/dispatches',
        operationId: 'agentDispatches',
        tags: ['Field Agents'],
        summary: 'List dispatches for a field agent',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated dispatches')]
    )]
    public function dispatches(int $id): JsonResponse
    {
        $agent = FieldAgent::findOrFail($id);
        return $this->successResponse($agent->dispatches()->with('worker', 'alert')->paginate(20));
    }

    #[OA\Post(
        path: '/agents/{id}/update-location',
        operationId: 'agentUpdateLocation',
        tags: ['Field Agents'],
        summary: 'Update GPS location of a field agent',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['lat', 'lng'],
                properties: [
                    new OA\Property(property: 'lat', type: 'number', format: 'float', example: 6.5244),
                    new OA\Property(property: 'lng', type: 'number', format: 'float', example: 3.3792),
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Location updated')]
    )]
    public function updateLocation(Request $request, int $id): JsonResponse
    {
        $data  = $request->validate(['lat' => 'required|numeric', 'lng' => 'required|numeric']);
        $agent = FieldAgent::findOrFail($id);
        $agent->update(['gps_lat' => $data['lat'], 'gps_lng' => $data['lng']]);
        return $this->successResponse([], 'Location updated.');
    }
}
