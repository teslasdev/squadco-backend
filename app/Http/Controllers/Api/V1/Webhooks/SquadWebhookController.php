<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Settlement;
use App\Models\SquadPayment;
use App\Models\WorkerMandate;
use App\Services\SquadPaymentService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Webhooks', description: 'Inbound webhook endpoints')]
class SquadWebhookController extends Controller
{
    public function __construct(private SquadPaymentService $squad, private AuditService $audit) {}

    #[OA\Post(
        path: '/webhooks/squad',
        operationId: 'squadWebhook',
        tags: ['Webhooks'],
        summary: 'Receive Squad payment webhook events',
        description: 'HMAC-validated endpoint. Squad sends `transaction.successful`, `transaction.failed`, and `transaction.reversed` events.',
        parameters: [
            new OA\Parameter(name: 'x-squad-signature', in: 'header', required: true, schema: new OA\Schema(type: 'string'), description: 'HMAC-SHA512 signature from Squad'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'Event', type: 'string', example: 'transaction.successful'),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [new OA\Property(property: 'transaction_reference', type: 'string', example: 'SQUAD-TXN-12345')]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Webhook received'),
            new OA\Response(response: 401, description: 'Invalid signature'),
        ]
    )]
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = $request->header('x-squad-signature', '');

        if (!$this->squad->verifyWebhookSignature($payload, $signature)) {
            return $this->errorResponse('Invalid webhook signature.', 401);
        }

        $data = $request->json()->all();

        // Handle payment/mandate status updates from Squad
        $event = strtolower((string) ($data['Event'] ?? $data['event'] ?? ''));
        $transactionRef = $data['TransactionRef']
            ?? data_get($data, 'data.transaction_reference')
            ?? data_get($data, 'Body.reference')
            ?? data_get($data, 'Body.transaction_ref');

        $body = $data['Body'] ?? $data['data'] ?? [];

        if ($event === 'transaction.successful' && $transactionRef) {
            $payment = SquadPayment::where('squad_reference', $transactionRef)->first();
            if ($payment && $payment->status !== 'released') {
                $old = $payment->toArray();
                $payment->update(['status' => 'released', 'disbursed_at' => now()]);
                $this->audit->log('squad_webhook_payment_released', 'SquadPayment', $payment->id, $old, ['status' => 'released']);
            }
        } elseif (in_array($event, ['transaction.failed', 'transaction.reversed'], true) && $transactionRef) {
            $payment = SquadPayment::where('squad_reference', $transactionRef)->first();
            if ($payment) {
                $old = $payment->toArray();
                $payment->update(['status' => 'failed']);
                $this->audit->log('squad_webhook_payment_failed', 'SquadPayment', $payment->id, $old, ['status' => 'failed']);
            }
        }

        if (in_array($event, ['mandates.approved', 'mandates.ready'], true) && $transactionRef) {
            $this->handleMandateLifecycleEvent($event, (string) $transactionRef, (array) $body);
        }

        if ($event === 'charge_successful' && $transactionRef) {
            $this->handleMandateChargeSuccess((string) $transactionRef, (array) $body);
        }

        return $this->successResponse([], 'Webhook received.');
    }

    private function handleMandateLifecycleEvent(string $event, string $transactionRef, array $body): void
    {
        $mandate = WorkerMandate::query()
            ->where('transaction_reference', $transactionRef)
            ->first();

        if (!$mandate) {
            return;
        }

        $isReady = (bool) ($body['ready_to_debit'] ?? false);
        $isApproved = (bool) ($body['approved'] ?? false);

        $mandateStatus = $event === 'mandates.ready' ? 'ready' : 'approved';
        $mandate->update([
            'status' => $mandateStatus,
            'approved' => $isApproved,
            'ready_to_debit' => $isReady,
            'squad_mandate_reference' => $body['mandate_id'] ?? $mandate->squad_mandate_reference,
            'last_webhook_event' => $event,
            'response_payload' => $body,
        ]);

        $settlement = $mandate->settlement
            ?? Settlement::query()->where('mandate_transaction_reference', $transactionRef)->first();

        if ($settlement) {
            $settlement->update([
                'squad_batch_id' => $transactionRef,
                'mandate_transaction_reference' => $transactionRef,
                'mandate_id' => $body['mandate_id'] ?? $settlement->mandate_id,
                'mandate_status' => $mandateStatus,
                'mandate_ready_to_debit' => $isReady,
                'mandate_last_event' => $event,
                'mandate_last_payload' => $body,
            ]);
        }

        if ($event === 'mandates.ready' && $isReady && !empty($mandate->squad_mandate_reference) && empty($mandate->debit_transaction_reference)) {
            $debitReference = 'debit_' . $transactionRef . '_' . Str::lower(Str::random(6));

            $debitResult = $this->squad->debitMandate([
                'amount' => (int) round(((float) $mandate->amount) * 100),
                'mandate_id' => $mandate->squad_mandate_reference,
                'transaction_reference' => $debitReference,
                'narration' => $mandate->description ?? 'Payroll mandate debit',
                'pass_charge' => false,
                'customer_email' => $mandate->customer_email,
            ]);

            $mandate->update([
                'debit_transaction_reference' => $debitReference,
                'status' => $debitResult['success'] ? 'debiting' : 'failed',
                'last_webhook_event' => $debitResult['success'] ? 'mandate.debit_initiated' : 'mandate.debit_failed',
                'response_payload' => $debitResult['raw'] ?? $debitResult,
                'failed_at' => $debitResult['success'] ? null : now(),
            ]);

            if ($settlement) {
                $settlement->update([
                    'mandate_debit_reference' => $debitReference,
                    'mandate_status' => $debitResult['success'] ? 'debiting' : 'failed',
                    'mandate_last_event' => $debitResult['success'] ? 'mandate.debit_initiated' : 'mandate.debit_failed',
                    'mandate_last_payload' => $debitResult['raw'] ?? $debitResult,
                    'status' => $debitResult['success'] ? $settlement->status : 'failed',
                ]);
            }
        }
    }

    private function handleMandateChargeSuccess(string $transactionRef, array $body): void
    {
        $mandate = WorkerMandate::query()
            ->where('debit_transaction_reference', $transactionRef)
            ->first();

        if (!$mandate) {
            return;
        }

        $alreadyDebited = $mandate->status === 'debited';

        $mandate->update([
            'status' => 'debited',
            'last_webhook_event' => 'charge_successful',
            'response_payload' => $body,
            'debited_at' => $mandate->debited_at ?? now(),
            'failed_at' => null,
        ]);

        $settlement = $mandate->settlement
            ?? Settlement::query()->where('mandate_debit_reference', $transactionRef)->first();

        if ($settlement) {
            $newTotal = (float) $settlement->total_disbursed;
            if (!$alreadyDebited) {
                $newTotal += (float) $mandate->amount;
            }

            $settlement->update([
                'status' => 'settled',
                'settled_at' => $settlement->settled_at ?? now(),
                'mandate_status' => 'debited',
                'mandate_ready_to_debit' => true,
                'mandate_last_event' => 'charge_successful',
                'mandate_last_payload' => $body,
                'mandate_debit_reference' => $transactionRef,
                'mandate_debited_at' => $settlement->mandate_debited_at ?? now(),
                'total_disbursed' => $newTotal,
            ]);
        }
    }
}
