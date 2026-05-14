<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SquadPayment;
use App\Models\Mda;
use App\Models\Verification;
use App\Models\GhostWorkerAlert;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reports', description: 'Analytics and reporting endpoints')]
class ReportController extends Controller
{
    
    #[OA\Get(
        path: '/reports/ghost-savings',
        operationId: 'reportGhostSavings',
        tags: ['Reports'],
        summary: 'Year-to-date ghost worker salary savings',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'YTD blocked amounts with monthly breakdown')]
    )]
    public function ghostSavings(): JsonResponse
    {
        $year       = now()->year;
        $ytdBlocked = SquadPayment::where('status', 'blocked')
            ->whereYear('created_at', $year)
            ->sum('amount');

        $monthlyBreakdown = SquadPayment::where('status', 'blocked')
            ->whereYear('created_at', $year)
            ->selectRaw('MONTH(created_at) as month, SUM(amount) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->successResponse([
            'ytd_blocked'      => $ytdBlocked,
            'year'             => $year,
            'monthly_breakdown' => $monthlyBreakdown,
        ]);
    }

    #[OA\Get(
        path: '/reports/verification-rates',
        operationId: 'reportVerificationRates',
        tags: ['Reports'],
        summary: 'Verification pass rates by MDA and month',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Pass rates by MDA and monthly trend')]
    )]
    public function verificationRates(): JsonResponse
    {
        $byMda = Mda::withCount([
            'workers as total_workers',
            'workers as verified_workers' => fn($q) => $q->whereNotNull('last_verified_at'),
        ])->get()->map(fn($mda) => [
            'mda'           => $mda->name,
            'total_workers' => $mda->total_workers,
            'verified'      => $mda->verified_workers,
            'pass_rate'     => $mda->total_workers > 0
                ? round(($mda->verified_workers / $mda->total_workers) * 100, 2)
                : 0,
        ]);

        $byMonth = Verification::selectRaw('MONTH(verified_at) as month, YEAR(verified_at) as year, verdict, COUNT(*) as count')
            ->whereNotNull('verified_at')
            ->groupBy('month', 'year', 'verdict')
            ->orderBy('year')->orderBy('month')
            ->get();

        return $this->successResponse(['by_mda' => $byMda, 'by_month' => $byMonth]);
    }

    #[OA\Get(
        path: '/reports/cycle-summary/{cycle_id}',
        operationId: 'reportCycleSummary',
        tags: ['Reports'],
        summary: 'Full summary report for a verification cycle',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cycle_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Cycle summary with settlements')]
    )]
    public function cycleSummary(int $cycle_id): JsonResponse
    {
        $cycle = \App\Models\VerificationCycle::with('settlements')->findOrFail($cycle_id);

        return $this->successResponse([
            'cycle'             => $cycle,
            'pass_rate'         => $cycle->total_workers > 0
                ? round(($cycle->verified_count / $cycle->total_workers) * 100, 2)
                : 0,
            'payroll_released'  => $cycle->payroll_released,
            'payroll_blocked'   => $cycle->payroll_blocked,
            'settlements_count' => $cycle->settlements->count(),
        ]);
    }

    #[OA\Get(
        path: '/reports/at-risk-mdas',
        operationId: 'reportAtRiskMdas',
        tags: ['Reports'],
        summary: 'Top 10 MDAs ranked by ghost worker risk score',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Top 10 at-risk MDAs')]
    )]
    public function atRiskMdas(): JsonResponse
    {
        $mdas = Mda::withCount([
            'workers as open_alerts' => fn($q) => $q->whereHas(
                'alerts', fn($q) => $q->where('status', 'open')
            ),
        ])->orderByDesc('risk_score')->limit(10)->get();

        return $this->successResponse($mdas);
    }

    #[OA\Get(
        path: '/reports/ai-layer-performance',
        operationId: 'reportAiLayerPerformance',
        tags: ['Reports'],
        summary: 'Average scores per AI verification layer',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Average scores across all 5 AI layers')]
    )]
    public function aiLayerPerformance(): JsonResponse
    {
        $stats = Verification::selectRaw(
            'AVG(challenge_response_score) as avg_challenge_response,
             AVG(speaker_biometric_score) as avg_speaker_biometric,
             AVG(anti_spoof_score) as avg_anti_spoof,
             AVG(replay_detection_score) as avg_replay_detection,
             AVG(face_liveness_score) as avg_face_liveness,
             AVG(trust_score) as avg_trust_score,
             COUNT(*) as total_verifications'
        )->first();

        return $this->successResponse($stats);
    }
}
