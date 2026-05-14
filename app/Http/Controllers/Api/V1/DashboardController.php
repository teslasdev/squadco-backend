<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\GhostWorkerAlert;
use App\Models\VerificationCycle;
use App\Models\FieldAgent;
use App\Models\SquadPayment;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard', description: 'Summary statistics')]
class DashboardController extends Controller
{
    #[OA\Get(
        path: '/dashboard/stats',
        operationId: 'dashboardStats',
        tags: ['Dashboard'],
        summary: 'Get system-wide dashboard statistics',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Dashboard stats')]
    )]
    public function stats(): JsonResponse
    {
        $latestCycle = VerificationCycle::latest()->first();

        return $this->successResponse([
            'enrolled_workers'          => Worker::count(),
            'verified_this_cycle'       => $latestCycle?->verified_count ?? 0,
            'ghost_flags'               => GhostWorkerAlert::where('status', 'open')->count(),
            'payroll_blocked'           => SquadPayment::where('status', 'blocked')->sum('amount'),
            'payroll_released'          => SquadPayment::where('status', 'released')->sum('amount'),
            'active_agents'             => FieldAgent::where('status', 'active')->count(),
            'active_states'             => Worker::distinct('state_of_posting')->count('state_of_posting'),
            'verification_cycle_status' => $latestCycle?->status ?? 'none',
            'ai_layer_status'           => [
                ['layer' => 'Challenge-Response',  'weight' => '20%', 'status' => 'operational'],
                ['layer' => 'Speaker Biometric',   'weight' => '25%', 'status' => 'operational'],
                ['layer' => 'Anti-Spoof (AASIST)', 'weight' => '25%', 'status' => 'operational'],
                ['layer' => 'Replay Detection',    'weight' => '15%', 'status' => 'operational'],
                ['layer' => 'Face Liveness',       'weight' => '15%', 'status' => 'operational'],
            ],
        ]);
    }
}
