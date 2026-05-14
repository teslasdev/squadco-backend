<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Departments', description: 'Departments within an MDA')]
class DepartmentController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/mdas/{mda_id}/departments',
        operationId: 'departmentIndex',
        tags: ['Departments'],
        summary: 'List departments in an MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'mda_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'List of departments with worker counts')]
    )]
    public function index(int $mda_id): JsonResponse
    {
        return $this->successResponse(Department::where('mda_id', $mda_id)->withCount('workers')->get());
    }

    #[OA\Post(
        path: '/mdas/{mda_id}/departments',
        operationId: 'departmentStore',
        tags: ['Departments'],
        summary: 'Create a department in an MDA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'mda_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'code'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Human Resources'),
                    new OA\Property(property: 'code', type: 'string', example: 'HR-001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Department created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, int $mda_id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'required|string',
        ]);
        $data['mda_id'] = $mda_id;
        $dept = Department::create($data);
        $this->audit->log('department_created', 'Department', $dept->id, [], $data, $request);
        return $this->successResponse($dept, 'Department created.', 201);
    }

    #[OA\Put(
        path: '/departments/{id}',
        operationId: 'departmentUpdate',
        tags: ['Departments'],
        summary: 'Update a department',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Department updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $dept = Department::findOrFail($id);
        $old  = $dept->toArray();
        $data = $request->validate(['name' => 'sometimes|string', 'code' => 'sometimes|string']);
        $dept->update($data);
        $this->audit->log('department_updated', 'Department', $id, $old, $data, $request);
        return $this->successResponse($dept->fresh());
    }

    #[OA\Delete(
        path: '/departments/{id}',
        operationId: 'departmentDestroy',
        tags: ['Departments'],
        summary: 'Delete a department',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Department deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $dept = Department::findOrFail($id);
        $this->audit->log('department_deleted', 'Department', $id, $dept->toArray(), [], $request);
        $dept->delete();
        return $this->successResponse([], 'Department deleted.');
    }
}
