<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Workers', description: 'Government worker management')]
class WorkerController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/workers',
        operationId: 'workerIndex',
        tags: ['Workers'],
        summary: 'List all workers (paginated)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'flagged', 'blocked', 'suspended'])),
            new OA\Parameter(name: 'mda_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of workers'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = Worker::with('mda', 'department')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->mda_id, fn($q) => $q->where('mda_id', $request->mda_id))
            ->when($request->state,  fn($q) => $q->where('state_of_posting', $request->state))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('full_name', 'like', "%{$request->search}%")
                  ->orWhere('ippis_id', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }));

        return $this->successResponse($q->paginate(25));
    }

    #[OA\Post(
        path: '/workers',
        operationId: 'workerStore',
        tags: ['Workers'],
        summary: 'Create a new worker',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ippis_id', 'full_name', 'mda_id', 'salary_amount'],
                properties: [
                    new OA\Property(property: 'ippis_id', type: 'string', example: 'IPPIS-001'),
                    new OA\Property(property: 'full_name', type: 'string', example: 'Adamu Bello'),
                    new OA\Property(property: 'nin', type: 'string', example: '12345678901'),
                    new OA\Property(property: 'bvn', type: 'string', example: '12345678901'),
                    new OA\Property(property: 'phone', type: 'string', example: '08012345678'),
                    new OA\Property(property: 'email', type: 'string', example: 'worker@gov.ng'),
                    new OA\Property(property: 'mda_id', type: 'integer', example: 1),
                    new OA\Property(property: 'department_id', type: 'integer', example: 2),
                    new OA\Property(property: 'grade_level', type: 'string', example: 'GL-07'),
                    new OA\Property(property: 'step', type: 'string', example: '5'),
                    new OA\Property(property: 'state_of_posting', type: 'string', example: 'Lagos'),
                    new OA\Property(property: 'salary_amount', type: 'number', example: 85000),
                    new OA\Property(property: 'bank_name', type: 'string', example: 'First Bank'),
                    new OA\Property(property: 'bank_account_number', type: 'string', example: '1234567890'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Worker created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ippis_id'          => 'required|string|unique:workers,ippis_id',
            'full_name'         => 'required|string',
            'nin'               => 'nullable|string|size:11',
            'bvn'               => 'nullable|string|size:11',
            'phone'             => 'nullable|string',
            'email'             => 'nullable|email',
            'mda_id'            => 'required|exists:mdas,id',
            'department_id'     => 'nullable|exists:departments,id',
            'grade_level'       => 'nullable|string',
            'step'              => 'nullable|string',
            'state_of_posting'  => 'nullable|string',
            'salary_amount'     => 'required|numeric|min:0',
            'bank_name'         => 'nullable|string',
            'bank_account_number' => 'nullable|string',
        ]);

        $data['status']      = 'active';
        $data['enrolled_at'] = now();

        $worker = Worker::create($data);

        $this->audit->log('worker_enrolled', 'Worker', $worker->id, [], $data, $request);

        return $this->successResponse($worker, 'Worker created.', 201);
    }

    #[OA\Get(
        path: '/workers/{id}',
        operationId: 'workerShow',
        tags: ['Workers'],
        summary: 'Get a single worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $worker = Worker::with('mda', 'department', 'virtualAccount')->findOrFail($id);
        return $this->successResponse($worker);
    }

    #[OA\Put(
        path: '/workers/{id}',
        operationId: 'workerUpdate',
        tags: ['Workers'],
        summary: 'Update a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'full_name', type: 'string'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'mda_id', type: 'integer'),
                    new OA\Property(property: 'department_id', type: 'integer'),
                    new OA\Property(property: 'grade_level', type: 'string'),
                    new OA\Property(property: 'step', type: 'string'),
                    new OA\Property(property: 'state_of_posting', type: 'string'),
                    new OA\Property(property: 'salary_amount', type: 'number'),
                    new OA\Property(property: 'bank_name', type: 'string'),
                    new OA\Property(property: 'bank_account_number', type: 'string'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'flagged', 'blocked', 'suspended']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Worker updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);
        $old    = $worker->toArray();

        $data = $request->validate([
            'full_name'         => 'sometimes|string',
            'phone'             => 'sometimes|string',
            'email'             => 'sometimes|email',
            'mda_id'            => 'sometimes|exists:mdas,id',
            'department_id'     => 'sometimes|nullable|exists:departments,id',
            'grade_level'       => 'sometimes|string',
            'step'              => 'sometimes|string',
            'state_of_posting'  => 'sometimes|string',
            'salary_amount'     => 'sometimes|numeric|min:0',
            'bank_name'         => 'sometimes|string',
            'bank_account_number' => 'sometimes|string',
            'status'            => 'sometimes|in:active,flagged,blocked,suspended',
        ]);

        $worker->update($data);
        $this->audit->log('worker_updated', 'Worker', $worker->id, $old, $data, $request);

        return $this->successResponse($worker->fresh('mda', 'department'));
    }

    #[OA\Delete(
        path: '/workers/{id}',
        operationId: 'workerDestroy',
        tags: ['Workers'],
        summary: 'Delete a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);
        $this->audit->log('worker_deleted', 'Worker', $id, $worker->toArray(), [], $request);
        $worker->delete();
        return $this->successResponse([], 'Worker deleted.');
    }

    #[OA\Get(
        path: '/workers/{id}/verifications',
        operationId: 'workerVerifications',
        tags: ['Workers'],
        summary: 'List verifications for a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated verifications')]
    )]
    public function verifications(int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);
        return $this->successResponse($worker->verifications()->with('cycle')->latest()->paginate(20));
    }

    #[OA\Get(
        path: '/workers/{id}/alerts',
        operationId: 'workerAlerts',
        tags: ['Workers'],
        summary: 'List ghost worker alerts for a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated alerts')]
    )]
    public function alerts(int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);
        return $this->successResponse($worker->alerts()->latest()->paginate(20));
    }

    #[OA\Post(
        path: '/workers/{id}/block',
        operationId: 'workerBlock',
        tags: ['Workers'],
        summary: 'Block a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Worker blocked')]
    )]
    public function block(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);
        $old    = $worker->toArray();
        $worker->update(['status' => 'blocked']);
        $this->audit->log('worker_blocked', 'Worker', $id, $old, ['status' => 'blocked'], $request);
        return $this->successResponse([], 'Worker blocked.');
    }

    #[OA\Post(
        path: '/workers/{id}/unblock',
        operationId: 'workerUnblock',
        tags: ['Workers'],
        summary: 'Unblock a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Worker unblocked')]
    )]
    public function unblock(Request $request, int $id): JsonResponse
    {
        $worker = Worker::findOrFail($id);
        $old    = $worker->toArray();
        $worker->update(['status' => 'active']);
        $this->audit->log('worker_unblocked', 'Worker', $id, $old, ['status' => 'active'], $request);
        return $this->successResponse([], 'Worker unblocked.');
    }

    #[OA\Post(
        path: '/workers/import',
        operationId: 'workerImport',
        tags: ['Workers'],
        summary: 'Bulk import workers via CSV',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['csv'],
                    properties: [new OA\Property(property: 'csv', type: 'string', format: 'binary', description: 'CSV file with worker data')]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Import result with counts'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function import(Request $request): JsonResponse
    {
        $request->validate(['csv' => 'required|file|mimes:csv,txt']);

        $path   = $request->file('csv')->getRealPath();
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $imported = $errors = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            $v = Validator::make($data, [
                'ippis_id'     => 'required|unique:workers,ippis_id',
                'full_name'    => 'required',
                'mda_id'       => 'required|exists:mdas,id',
                'salary_amount' => 'required|numeric',
            ]);

            if ($v->fails()) { $errors++; continue; }

            Worker::create(array_merge($data, ['status' => 'active', 'enrolled_at' => now()]));
            $imported++;
        }

        fclose($handle);

        return $this->successResponse(['imported' => $imported, 'errors' => $errors]);
    }
}
