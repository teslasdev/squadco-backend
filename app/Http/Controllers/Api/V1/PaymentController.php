<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MandateAccount;
use App\Models\Settlement;
use App\Models\SquadPayment;
use App\Models\WorkerMandate;
use App\Models\Worker;
use App\Models\VerificationCycle;
use App\Services\SquadPaymentService;
use App\Services\AuditService;
use App\Jobs\TriggerSquadDisbursementJob;
use App\Jobs\SetupWorkerMandatesJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Payments', description: 'Squad payroll disbursement management')]
class PaymentController extends Controller
{
    public function __construct(private SquadPaymentService $squad, private AuditService $audit) {}

    #[OA\Get(
        path: '/payments',
        operationId: 'paymentIndex',
        tags: ['Payments'],
        summary: 'List payments',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'released', 'blocked', 'failed'])),
            new OA\Parameter(name: 'cycle_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'mda_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated payments')]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = SquadPayment::with('worker.mda', 'cycle')
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->cycle_id, fn($q) => $q->where('cycle_id', $request->cycle_id))
            ->when($request->mda_id,   fn($q) => $q->whereHas('worker', fn($q) => $q->where('mda_id', $request->mda_id)));

        return $this->successResponse($q->latest()->paginate(25));
    }

    #[OA\Get(
        path: '/payments/{id}',
        operationId: 'paymentShow',
        tags: ['Payments'],
        summary: 'Get a single payment record',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Payment detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(SquadPayment::with('worker', 'cycle', 'verification')->findOrFail($id));
    }

    #[OA\Post(
        path: '/payments/release',
        operationId: 'paymentRelease',
        tags: ['Payments'],
        summary: 'Bulk-release payments for all PASS verifications in a cycle',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cycle_id'],
                properties: [new OA\Property(property: 'cycle_id', type: 'integer', example: 1)]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: 'Disbursement jobs queued'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function release(Request $request): JsonResponse
    {
        $data  = $request->validate(['cycle_id' => 'required|exists:verification_cycles,id']);
        $count = 0;

        $verifications = \App\Models\Verification::where('cycle_id', $data['cycle_id'])
            ->where('verdict', 'PASS')
            ->where('salary_released', false)
            ->get();

        foreach ($verifications as $v) {
            TriggerSquadDisbursementJob::dispatch($v);
            $count++;
        }

        $this->audit->log('payments_bulk_released', 'VerificationCycle', $data['cycle_id'], [], ['count' => $count], $request);
        return $this->successResponse([], "{$count} disbursement(s) queued.", 202);
    }

    #[OA\Post(
        path: '/payments/block/{worker_id}',
        operationId: 'paymentBlockWorker',
        tags: ['Payments'],
        summary: 'Block all pending payments for a worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker payments blocked'),
            new OA\Response(response: 404, description: 'Worker not found'),
        ]
    )]
    public function blockWorker(Request $request, int $worker_id): JsonResponse
    {
        Worker::findOrFail($worker_id)->update(['status' => 'blocked']);
        SquadPayment::where('worker_id', $worker_id)->where('status', 'pending')
            ->update(['status' => 'blocked']);
        $this->audit->log('payment_blocked', 'Worker', $worker_id, [], ['status' => 'blocked'], $request);
        return $this->successResponse([], 'Worker payments blocked.');
    }

    public function verifyPaymentDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_number' => 'required|string',
            'bank_code' => 'required|string',
        ]);

        $verificationResult = $this->squad->verifyPaymentDetails($data['account_number'], $data['bank_code']);
        return $this->successResponse($verificationResult);
    }

    #[OA\Get(
        path: '/payments/mandates/banks',
        operationId: 'paymentMandateBanks',
        tags: ['Payments'],
        summary: 'Fetch mandate-compatible bank list from Squad',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Bank list response')]
    )]
    public function mandateBanks(): JsonResponse
    {
        $result = $this->squad->fetchMandateBankList();

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 422, ['raw' => $result['raw'] ?? []]);
        }

        return $this->successResponse([
            'banks' => $result['banks'],
            'raw' => $result['raw'] ?? [],
        ], $result['message']);
    }

    #[OA\Get(
        path: '/payments/mandates/settings',
        operationId: 'paymentMandateSettingsShow',
        tags: ['Payments'],
        summary: 'Get saved mandate source account settings',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Mandate settings response')]
    )]
    public function mandateSettings(): JsonResponse
    {
        $accounts = MandateAccount::query()->latest()->get();
        $primary = MandateAccount::query()
            ->where('is_primary', true)
            ->where('is_active', true)
            ->first();

        return $this->successResponse([
            'mandate_accounts' => $accounts,
            'primary_mandate_account' => $primary,
        ]);
    }

    #[OA\Put(
        path: '/payments/mandates/settings',
        operationId: 'paymentMandateSettingsUpdate',
        tags: ['Payments'],
        summary: 'Save mandate source account settings used for mandate creation',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Mandate settings saved')]
    )]
    public function updateMandateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'nullable|exists:mandate_accounts,id',
            'account_number' => 'required|string|max:20',
            'bank_code' => 'required|string|max:20',
            'description' => 'nullable|string|max:255',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'is_primary' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $account = isset($data['id'])
            ? MandateAccount::findOrFail((int) $data['id'])
            : new MandateAccount();

        $account->fill([
            'account_number' => $data['account_number'],
            'bank_code' => $data['bank_code'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $account->save();

        $setAsPrimary = (bool) ($data['is_primary'] ?? false);
        if ($setAsPrimary) {
            MandateAccount::query()->where('id', '!=', $account->id)->update(['is_primary' => false]);
            $account->update(['is_primary' => true]);
        } elseif (!MandateAccount::query()->where('is_primary', true)->exists()) {
            $account->update(['is_primary' => true]);
        }

        $this->audit->log('mandate_settings_updated', 'Setting', null, [], $data, $request);

        return $this->successResponse([
            'mandate_account' => $account->fresh(),
            'mandate_accounts' => MandateAccount::query()->latest()->get(),
            'primary_mandate_account' => MandateAccount::query()->where('is_primary', true)->where('is_active', true)->first(),
        ], 'Mandate settings saved.');
    }

    #[OA\Put(
        path: '/payments/mandates/settings/{id}/primary',
        operationId: 'paymentMandateSettingsSetPrimary',
        tags: ['Payments'],
        summary: 'Set one mandate account as primary',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Primary mandate account updated')]
    )]
    public function setPrimaryMandateAccount(int $id): JsonResponse
    {
        $account = MandateAccount::query()->findOrFail($id);

        if (!$account->is_active) {
            return $this->errorResponse('Inactive mandate account cannot be set as primary.', 422);
        }

        MandateAccount::query()->where('id', '!=', $account->id)->update(['is_primary' => false]);
        $account->update(['is_primary' => true]);

        return $this->successResponse([
            'primary_mandate_account' => $account->fresh(),
            'mandate_accounts' => MandateAccount::query()->latest()->get(),
        ], 'Primary mandate account updated.');
    }

    #[OA\Post(
        path: '/payments/mandates/initiate',
        operationId: 'paymentMandateInitiate',
        tags: ['Payments'],
        summary: 'Create a worker mandate with Squad and persist mandate status',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Mandate created'),
            new OA\Response(response: 422, description: 'Validation/API error'),
        ]
    )]
    public function initiateMandate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => 'required|exists:workers,id',
            'mandate_type' => ['nullable', 'string', Rule::in(['emandate'])],
            'amount' => 'nullable|numeric|min:1',
            'mandate_account_id' => 'nullable|exists:mandate_accounts,id',
            'description' => 'nullable|string|max:255',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'customer_email' => 'nullable|email',
            'transaction_reference' => 'nullable|string|max:100',
        ]);

        $worker = Worker::findOrFail((int) $data['worker_id']);
        $mandateAccount = isset($data['mandate_account_id'])
            ? MandateAccount::query()->where('is_active', true)->findOrFail((int) $data['mandate_account_id'])
            : $this->getPrimaryMandateAccount();

        if (in_array($worker->status, ['blocked', 'flagged', 'suspended'], true)) {
            return $this->errorResponse('Worker is not eligible for mandate setup due to current status.', 422);
        }

        if (!$mandateAccount) {
            return $this->errorResponse('No primary mandate account configured. Add mandate accounts and set one as primary.', 422);
        }

        $resolvedAccountNumber = $mandateAccount->account_number;
        $resolvedBankCode = $mandateAccount->bank_code;
        $resolvedAmount = (float) ($data['amount'] ?? $worker->salary_amount);
        $resolvedDescription = $data['description'] ?? ($mandateAccount->description ?? ('Payroll mandate for worker ' . $worker->id));
        $resolvedStartDate = $data['start_date'] ?? (($mandateAccount->start_date?->toDateString()) ?? now()->toDateString());
        $resolvedEndDate = $data['end_date'] ?? (($mandateAccount->end_date?->toDateString()) ?? now()->addYear()->toDateString());

        if ($resolvedAmount <= 0) {
            return $this->errorResponse('Mandate amount must be greater than zero.', 422);
        }

        if (Carbon::parse($resolvedEndDate)->lessThanOrEqualTo(Carbon::parse($resolvedStartDate))) {
            return $this->errorResponse('Mandate end_date must be after start_date.', 422);
        }

        $alreadyPaid = SquadPayment::where('worker_id', $worker->id)
            ->where('status', 'released')
            ->exists();
        if ($alreadyPaid) {
            return $this->errorResponse('Worker already has released payment. Mandate creation skipped.', 422);
        }

        $existingMandate = WorkerMandate::where('worker_id', $worker->id)
            ->whereIn('status', ['pending', 'created'])
            ->first();
        if ($existingMandate) {
            return $this->errorResponse('Worker already has a mandate in progress or created.', 422, [
                'mandate_id' => $existingMandate->id,
                'status' => $existingMandate->status,
                'transaction_reference' => $existingMandate->transaction_reference,
            ]);
        }

        [$firstName, $lastName] = $this->splitName($worker->full_name);
        $reference = $data['transaction_reference'] ?? ('mandate_' . $worker->id . '_' . Str::lower(Str::random(10)));
        $settlement = $this->resolveSettlementForMandate($worker, $reference);

        $payload = [
            'mandate_type' => $data['mandate_type'] ?? 'emandate',
            'amount' => (string) ((int) round($resolvedAmount * 100)),
            'account_number' => (string) $resolvedAccountNumber,
            'bank_code' => (string) $resolvedBankCode,
            'description' => $resolvedDescription,
            'start_date' => $resolvedStartDate,
            'end_date' => $resolvedEndDate,
            'customer_email' => $data['customer_email'] ?? $worker->email ?? ('worker' . $worker->id . '@example.com'),
            'transaction_reference' => $reference,
            'customerInformation' => [
                'identity' => [
                    'type' => !empty($worker->bvn) ? 'bvn' : 'nin',
                    'number' => !empty($worker->bvn) ? $worker->bvn : ($worker->nin ?? ''),
                ],
                'firstName' => $firstName,
                'lastName' => $lastName,
                'address' => $worker->home_address ?? $worker->office_address ?? 'N/A',
                'phone' => $worker->phone ?? 'N/A',
            ],
        ];

        $mandate = WorkerMandate::create([
            'worker_id' => $worker->id,
            'settlement_id' => $settlement?->id,
            'transaction_reference' => $reference,
            'mandate_type' => $payload['mandate_type'],
            'amount' => $resolvedAmount,
            'bank_code' => (string) $resolvedBankCode,
            'account_number' => (string) $resolvedAccountNumber,
            'account_name' => null,
            'customer_email' => $payload['customer_email'],
            'start_date' => $resolvedStartDate,
            'end_date' => $resolvedEndDate,
            'status' => 'pending',
            'approved' => false,
            'ready_to_debit' => false,
            'description' => $resolvedDescription,
            'request_payload' => $payload,
            'initiated_at' => now(),
        ]);

        $result = $this->squad->createMandate($payload);

        if ($result['success']) {
            $mandate->update([
                'status' => 'created',
                'squad_mandate_reference' => $result['mandate_reference'] ?? null,
                'last_webhook_event' => 'mandate.created',
                'response_payload' => $result['raw'] ?? $result,
                'failed_at' => null,
            ]);

            if ($settlement) {
                $settlement->update([
                    'squad_batch_id' => $reference,
                    'mandate_transaction_reference' => $reference,
                    'mandate_id' => $result['mandate_reference'] ?? null,
                    'mandate_status' => 'created',
                    'mandate_last_event' => 'mandate.created',
                    'mandate_last_payload' => $result['raw'] ?? $result,
                ]);
            }

            $this->audit->log('worker_mandate_created_manual', 'Worker', $worker->id, [], [
                'mandate_id' => $mandate->id,
                'transaction_reference' => $reference,
            ], $request);

            return $this->successResponse([
                'mandate' => $mandate->fresh(),
                'provider_response' => $result['raw'] ?? [],
            ], 'Mandate created successfully.', 201);
        }

        $mandate->update([
            'status' => 'failed',
            'last_webhook_event' => 'mandate.create_failed',
            'response_payload' => $result['raw'] ?? $result,
            'failed_at' => now(),
        ]);

        if ($settlement) {
            $settlement->update([
                'status' => 'failed',
                'squad_batch_id' => $reference,
                'mandate_transaction_reference' => $reference,
                'mandate_status' => 'failed',
                'mandate_last_event' => 'mandate.create_failed',
                'mandate_last_payload' => $result['raw'] ?? $result,
            ]);
        }

        return $this->errorResponse($result['message'], 422, [
            'mandate' => $mandate->fresh(),
            'provider_response' => $result['raw'] ?? [],
        ]);
    }

    #[OA\Post(
        path: '/payments/mandates/initiate-active-workers',
        operationId: 'paymentMandateInitiateActiveWorkers',
        tags: ['Payments'],
        summary: 'Queue mandate creation for all active workers (test/admin trigger)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 202, description: 'Bulk mandate setup queued'),
        ]
    )]
    public function initiateMandatesForActiveWorkers(Request $request): JsonResponse
    {
        SetupWorkerMandatesJob::dispatch();

        $this->audit->log('worker_mandates_bulk_setup_requested', 'Worker', null, [], [
            'scope' => 'active_workers',
            'trigger' => 'manual_api',
        ], $request);

        return $this->successResponse([], 'Mandate setup queued for all active workers.', 202);
    }

    private function splitName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return ['Worker', 'Unknown'];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $firstName = $parts[0] ?? 'Worker';
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Unknown';

        return [$firstName, $lastName];
    }

    private function getPrimaryMandateAccount(): ?MandateAccount
    {
        return MandateAccount::query()
            ->where('is_primary', true)
            ->where('is_active', true)
            ->first();
    }

    private function resolveSettlementForMandate(Worker $worker, string $transactionReference): ?Settlement
    {
        $cycle = VerificationCycle::query()->latest('id')->first();
        if (!$cycle) {
            return null;
        }

        return Settlement::updateOrCreate(
            [
                'mda_id' => $worker->mda_id,
                'cycle_id' => $cycle->id,
            ],
            [
                'status' => 'pending',
                'squad_batch_id' => $transactionReference,
                'mandate_transaction_reference' => $transactionReference,
            ]
        );
    }
}
