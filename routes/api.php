<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\{
    DashboardController,
    WorkerController,
    MdaController,
    DepartmentController,
    VerificationCycleController,
    VerificationController,
    GhostAlertController,
    FieldAgentController,
    DispatchController,
    PaymentController,
    VirtualAccountController,
    SettlementController,
    ReportController,
    AuditLogController,
    SettingsController,
    UserController,
    OnboardingController,
};
use App\Http\Controllers\Api\V1\Webhooks\SquadWebhookController;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes — Alive Ghost Worker Verification System (/api/v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ─── Public: Squad Webhook (HMAC validated inside controller) ────────────
    Route::post('/webhooks/squad', [SquadWebhookController::class, 'handle']);

    // ─── Public: Onboarding resume by token (no auth) ─────────────────────────
    Route::prefix('onboarding')->group(function () {
        Route::get('/resume/{token}', [OnboardingController::class, 'resume']);
    });

    // ─── Auth ─────────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me',      [AuthController::class, 'me']);
        });
    });

    // ─── Protected Routes ─────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {

        // Dashboard
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Worker Onboarding
        Route::prefix('onboarding')->group(function () {
            Route::post('/step1',                   [OnboardingController::class, 'step1']);
            Route::put('/{worker_id}/step2',        [OnboardingController::class, 'step2']);
            Route::put('/{worker_id}/step3',        [OnboardingController::class, 'step3']);
            Route::post('/{worker_id}/step4',       [OnboardingController::class, 'step4']);
            Route::post('/{worker_id}/step5',       [OnboardingController::class, 'step5']);
            Route::post('/{worker_id}/step6',       [OnboardingController::class, 'step6']);
            Route::post('/{worker_id}/complete',    [OnboardingController::class, 'complete']);
            Route::get('/{worker_id}/status',       [OnboardingController::class, 'status']);
        });

        // Workers
        Route::post('/workers/import', [WorkerController::class, 'import']);
        Route::apiResource('/workers', WorkerController::class);
        Route::get('/workers/{id}/verifications', [WorkerController::class, 'verifications']);
        Route::get('/workers/{id}/alerts',        [WorkerController::class, 'alerts']);
        Route::post('/workers/{id}/block',        [WorkerController::class, 'block']);
        Route::post('/workers/{id}/unblock',      [WorkerController::class, 'unblock']);

        // MDAs
        Route::apiResource('/mdas', MdaController::class);
        Route::get('/mdas/{id}/workers', [MdaController::class, 'workers']);
        Route::get('/mdas/{id}/stats',   [MdaController::class, 'stats']);

        // Departments
        Route::get('/mdas/{mda_id}/departments',  [DepartmentController::class, 'index']);
        Route::post('/mdas/{mda_id}/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{id}',           [DepartmentController::class, 'update']);
        Route::delete('/departments/{id}',        [DepartmentController::class, 'destroy']);

        // Verification Cycles
        Route::apiResource('/cycles', VerificationCycleController::class)->except(['update', 'destroy']);
        Route::post('/cycles/{id}/run',    [VerificationCycleController::class, 'run']);
        Route::get('/cycles/{id}/results', [VerificationCycleController::class, 'results']);
        Route::get('/cycles/{id}/summary', [VerificationCycleController::class, 'summary']);

        // Verifications
        Route::apiResource('/verifications', VerificationController::class)->only(['index', 'store', 'show']);
        Route::post('/verifications/{id}/override', [VerificationController::class, 'override']);

        // Ghost Worker Alerts
        Route::get('/alerts',                      [GhostAlertController::class, 'index']);
        Route::get('/alerts/{id}',                 [GhostAlertController::class, 'show']);
        Route::post('/alerts/{id}/block',          [GhostAlertController::class, 'block']);
        Route::post('/alerts/{id}/dispatch',       [GhostAlertController::class, 'dispatch']);
        Route::post('/alerts/{id}/refer-icpc',     [GhostAlertController::class, 'referIcpc']);
        Route::post('/alerts/{id}/false-positive', [GhostAlertController::class, 'falsePositive']);
        Route::post('/alerts/{id}/resolve',        [GhostAlertController::class, 'resolve']);

        // Field Agents
        Route::apiResource('/agents', FieldAgentController::class)->except(['destroy']);
        Route::get('/agents/{id}/dispatches',        [FieldAgentController::class, 'dispatches']);
        Route::post('/agents/{id}/update-location',  [FieldAgentController::class, 'updateLocation']);

        // Dispatches
        Route::get('/dispatches',              [DispatchController::class, 'index']);
        Route::get('/dispatches/{id}',         [DispatchController::class, 'show']);
        Route::put('/dispatches/{id}/complete', [DispatchController::class, 'complete']);
        Route::put('/dispatches/{id}/fail',     [DispatchController::class, 'fail']);

        // Payments
        Route::get('/payments',                     [PaymentController::class, 'index']);
        Route::get('/payments/{id}',                [PaymentController::class, 'show']);
        Route::post('/payments/release',            [PaymentController::class, 'release']);
        Route::post('/payments/block/{worker_id}',  [PaymentController::class, 'blockWorker']);

        // Virtual Accounts
        Route::apiResource('/virtual-accounts', VirtualAccountController::class)->except(['update']);

        // Settlements
        Route::get('/settlements',                      [SettlementController::class, 'index']);
        Route::get('/settlements/{id}',                 [SettlementController::class, 'show']);
        Route::post('/settlements/{cycle_id}/initiate', [SettlementController::class, 'initiate']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/ghost-savings',            [ReportController::class, 'ghostSavings']);
            Route::get('/verification-rates',       [ReportController::class, 'verificationRates']);
            Route::get('/cycle-summary/{cycle_id}', [ReportController::class, 'cycleSummary']);
            Route::get('/at-risk-mdas',             [ReportController::class, 'atRiskMdas']);
            Route::get('/ai-layer-performance',     [ReportController::class, 'aiLayerPerformance']);
        });

        // Audit Logs
        Route::get('/audit-logs',      [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{id}', [AuditLogController::class, 'show']);

        // Settings
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);

        // Users (admin)
        Route::apiResource('/users', UserController::class);
    });
});
