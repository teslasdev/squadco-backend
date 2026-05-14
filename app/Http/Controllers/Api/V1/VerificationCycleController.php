<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VerificationCycle;
use App\Jobs\RunVerificationCycleJob;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Verification Cycles', description: 'Payroll verification cycle management')]
class VerificationCycleController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/cycles',
        operationId: 'cycleIndex',
        tags: ['Verification Cycles'],
        summary: 'List all verification cycles',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated cycles')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(VerificationCycle::with('creator')->latest()->paginate(20));
    }

    #[OA\Post(
        path: '/cycles',
        operationId: 'cycleStore',
        tags: ['Verification Cycles'],
        summary: 'Create a new verification cycle',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'cycle_month'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'May 2026 Verification'),
                    new OA\Property(property: 'cycle_month', type: 'string', format: 'date', example: '2026-05-01'),
                    new OA\Property(property: 'federation', type: 'string', example: 'Federal'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Cycle created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string',
            'cycle_month' => 'required|date',
            'federation'  => 'nullable|string',
        ]);
        $data['created_by'] = $request->user()->id;
        $data['status']     = 'pending';

        $cycle = VerificationCycle::create($data);
        $this->audit->log('cycle_created', 'VerificationCycle', $cycle->id, [], $data, $request);
        return $this->successResponse($cycle, 'Cycle created.', 201);
    }

    #[OA\Get(
        path: '/cycles/{id}',
        operationId: 'cycleShow',
        tags: ['Verification Cycles'],
        summary: 'Get a single verification cycle',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Cycle detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(VerificationCycle::with('creator')->findOrFail($id));
    }

    #[OA\Post(
        path: '/cycles/{id}/run',
        operationId: 'cycleRun',
        tags: ['Verification Cycles'],
        summary: 'Dispatch a verification cycle job',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 202, description: 'Cycle queued'),
            new OA\Response(response: 409, description: 'Cycle already running'),
        ]
    )]
    public function run(Request $request, int $id): JsonResponse
    {
        $cycle = VerificationCycle::findOrFail($id);

        if ($cycle->status === 'running') {
            return $this->errorResponse('Cycle is already running.', 409);
        }

        RunVerificationCycleJob::dispatch($cycle);
        $this->audit->log('cycle_run_dispatched', 'VerificationCycle', $id, [], [], $request);

        return $this->successResponse([], 'Verification cycle queued.', 202);
    }

    #[OA\Get(
        path: '/cycles/{id}/results',
        operationId: 'cycleResults',
        tags: ['Verification Cycles'],
        summary: 'Get verification results for a cycle',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated verification results')]
    )]
    public function results(int $id): JsonResponse
    {
        $cycle = VerificationCycle::findOrFail($id);
        return $this->successResponse($cycle->verifications()->with('worker')->paginate(25));
    }

    #[OA\Get(
        path: '/cycles/{id}/summary',
        operationId: 'cycleSummary',
        tags: ['Verification Cycles'],
        summary: 'Get aggregate summary for a cycle',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Cycle summary with pass-rate and payroll totals')]
    )]
    public function summary(int $id): JsonResponse
    {
        $cycle = VerificationCycle::findOrFail($id);
        return $this->successResponse([
            'cycle'             => $cycle->name,
            'status'            => $cycle->status,
            'total_workers'     => $cycle->total_workers,
            'verified_count'    => $cycle->verified_count,
            'failed_count'      => $cycle->failed_count,
            'inconclusive_count' => $cycle->inconclusive_count,
            'payroll_released'  => $cycle->payroll_released,
            'payroll_blocked'   => $cycle->payroll_blocked,
            'pass_rate'         => $cycle->total_workers > 0
                ? round(($cycle->verified_count / $cycle->total_workers) * 100, 2)
                : 0,
        ]);
    }
}
