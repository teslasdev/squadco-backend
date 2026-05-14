<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Models\VerificationCycle;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Settlements', description: 'Per-MDA payroll settlement records')]
class SettlementController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/settlements',
        operationId: 'settlementIndex',
        tags: ['Settlements'],
        summary: 'List all settlements',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated settlements')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(Settlement::with('mda', 'cycle')->latest()->paginate(25));
    }

    #[OA\Get(
        path: '/settlements/{id}',
        operationId: 'settlementShow',
        tags: ['Settlements'],
        summary: 'Get a single settlement',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Settlement detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(Settlement::with('mda', 'cycle')->findOrFail($id));
    }

    #[OA\Post(
        path: '/settlements/{cycle_id}/initiate',
        operationId: 'settlementInitiate',
        tags: ['Settlements'],
        summary: 'Initiate settlements for all MDAs in a cycle',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cycle_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'Settlements created per MDA'),
            new OA\Response(response: 404, description: 'Cycle not found'),
        ]
    )]
    public function initiate(Request $request, int $cycle_id): JsonResponse
    {
        $cycle = VerificationCycle::findOrFail($cycle_id);

        $payments = \App\Models\SquadPayment::where('cycle_id', $cycle_id)
            ->with('worker.mda')
            ->get()
            ->groupBy(fn($p) => $p->worker->mda_id);

        $created = [];
        foreach ($payments as $mda_id => $mdaPayments) {
            $released = $mdaPayments->where('status', 'released')->sum('amount');
            $blocked  = $mdaPayments->where('status', 'blocked')->sum('amount');

            $settlement = Settlement::create([
                'mda_id'          => $mda_id,
                'cycle_id'        => $cycle_id,
                'total_disbursed' => $released,
                'total_blocked'   => $blocked,
                'status'          => 'settled',
                'settled_at'      => now(),
            ]);
            $created[] = $settlement;
        }

        $this->audit->log('settlements_initiated', 'VerificationCycle', $cycle_id, [], ['count' => count($created)], $request);
        return $this->successResponse(['settlements' => $created], count($created) . ' settlements created.', 201);
    }
}
