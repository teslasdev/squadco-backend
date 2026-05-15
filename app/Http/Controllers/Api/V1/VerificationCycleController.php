<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\VerificationCycle;
use App\Models\Worker;
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
    /**
     * Internal fallback cycles created by the webhook / ad-hoc face flow when
     * a result has no real cycle to attach to. They are NOT payroll cycles
     * and must never be a "Run cycle" target (running them would dispatch the
     * whole roster against a bucket that's perpetually `running`).
     */
    private const FALLBACK_CYCLE_NAMES = ['Vapi Continuous', 'Continuous'];

    public function index(): JsonResponse
    {
        $paginator = VerificationCycle::with('creator')
            ->whereNotIn('name', self::FALLBACK_CYCLE_NAMES)
            ->latest()
            ->paginate(20);

        // Overlay live counts so the dashboard never shows stale tallies for
        // an in-flight async cycle. syncCompletion() is idempotent.
        $paginator->getCollection()->transform(function (VerificationCycle $cycle) {
            $cycle->syncCompletion();
            $stats = $cycle->liveStats();
            $cycle->verified_count     = $stats['verified'];
            $cycle->failed_count       = $stats['failed'];
            $cycle->inconclusive_count = $stats['inconclusive'];
            if ($stats['total'] > (int) $cycle->total_workers) {
                $cycle->total_workers = $stats['total'];
            }
            $cycle->setAttribute('pending_count', $stats['pending']);
            $cycle->setAttribute('resolved_count', $stats['resolved']);
            return $cycle;
        });

        return $this->successResponse($paginator);
    }

    #[OA\Get(
        path: '/cycles/settings/interval',
        operationId: 'cycleIntervalSettingsShow',
        tags: ['Verification Cycles'],
        summary: 'Get verification cycle interval settings',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Cycle interval settings')]
    )]
    public function intervalSettings(): JsonResponse
    {
        $values = Setting::query()
            ->whereIn('key', ['verification_cycle_interval_days', 'verification_cycle_interval_description'])
            ->pluck('value', 'key');

        $intervalDays = $values->get('verification_cycle_interval_days');
        $description = $values->get('verification_cycle_interval_description');

        return $this->successResponse([
            'interval_days' => $intervalDays !== null ? (int) $intervalDays : null,
            'description' => $description,
        ]);
    }

    #[OA\Put(
        path: '/cycles/settings/interval',
        operationId: 'cycleIntervalSettingsUpdate',
        tags: ['Verification Cycles'],
        summary: 'Set mandatory verification cycle interval (in days)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Cycle interval updated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateIntervalSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interval_days' => 'required|integer|min:1|max:365',
            'description' => 'nullable|string|max:255',
        ]);

        Setting::updateOrCreate(
            ['key' => 'verification_cycle_interval_days'],
            ['value' => (string) $data['interval_days'], 'description' => 'Number of days between mandatory verification cycles.']
        );

        Setting::updateOrCreate(
            ['key' => 'verification_cycle_interval_description'],
            ['value' => $data['description'] ?? null, 'description' => 'Optional description for verification cycle interval.']
        );

        $this->audit->log('cycle_interval_updated', 'Setting', null, [], $data, $request);

        return $this->successResponse([
            'interval_days' => (int) $data['interval_days'],
            'description' => $data['description'] ?? null,
        ], 'Verification cycle interval updated.');
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
        $cycle = VerificationCycle::with('creator')->findOrFail($id);
        $cycle->syncCompletion();
        $cycle->refresh();
        $stats = $cycle->liveStats();
        $cycle->verified_count     = $stats['verified'];
        $cycle->failed_count       = $stats['failed'];
        $cycle->inconclusive_count = $stats['inconclusive'];
        if ($stats['total'] > (int) $cycle->total_workers) {
            $cycle->total_workers = $stats['total'];
        }
        $cycle->setAttribute('pending_count', $stats['pending']);
        $cycle->setAttribute('resolved_count', $stats['resolved']);
        return $this->successResponse($cycle);
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
        // Atomic dedupe: flip status to `running` in a single UPDATE and only
        // dispatch if the row was actually changed. Two concurrent calls land
        // on the same row; only the one that wins the UPDATE proceeds. This
        // protects against StrictMode double-fires from the frontend and any
        // accidental double-click on the Run button.
        $cycle = VerificationCycle::findOrFail($id);

        // Fallback buckets are not payroll cycles — refuse to run them.
        if (in_array($cycle->name, self::FALLBACK_CYCLE_NAMES, true)) {
            return $this->errorResponse(
                'This is an internal continuous-verification bucket, not a runnable payroll cycle.',
                422
            );
        }

        $updated = VerificationCycle::where('id', $id)
            ->whereIn('status', ['pending', 'completed', 'failed'])
            ->update(['status' => 'running']);

        if ($updated === 0) {
            return $this->errorResponse('Cycle is already running or not in a runnable state.', 409);
        }

        $cycle->refresh();
        RunVerificationCycleJob::dispatch($cycle);

        // The job runs async; surface the EXPECTED scope now so the UI can
        // message accurately ("N workers being called, M face checks queued")
        // and size its progress bar before any results land.
        $phoneExpected = Worker::where('status', 'active')
            ->whereIn('verification_channel', ['phone', 'both'])
            ->where('voice_enrolled', true)
            ->whereNotNull('phone')
            ->count();
        $faceExpected = Worker::where('status', 'active')
            ->where('verification_channel', 'web')
            ->where('face_enrolled', true)
            ->count();

        $scope = [
            'phone_calls'   => $phoneExpected,
            'face_pending'  => $faceExpected,
            'total_expected' => $phoneExpected + $faceExpected,
        ];

        $this->audit->log('cycle_run_dispatched', 'VerificationCycle', $id, [], $scope, $request);

        return $this->successResponse([
            'cycle_id' => $cycle->id,
            'scope'    => $scope,
        ], 'Verification cycle queued.', 202);
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
        // Async cycle: derive counts live, then write-through completion.
        $cycle->syncCompletion();
        $cycle->refresh();
        $stats = $cycle->liveStats();
        $total = max($cycle->total_workers, $stats['total']);

        return $this->successResponse([
            'cycle'              => $cycle->name,
            'status'             => $cycle->status,
            'total_workers'      => $total,
            'verified_count'     => $stats['verified'],
            'failed_count'       => $stats['failed'],
            'inconclusive_count' => $stats['inconclusive'],
            'pending_count'      => $stats['pending'],
            'resolved_count'     => $stats['resolved'],
            'payroll_released'   => $cycle->payroll_released,
            'payroll_blocked'    => $cycle->payroll_blocked,
            'pass_rate'          => $total > 0
                ? round(($stats['verified'] / $total) * 100, 2)
                : 0,
        ]);
    }
}
