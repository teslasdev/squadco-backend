<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SquadPayment;
use App\Services\SquadPaymentService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        // Handle payment status updates from Squad
        $event     = $data['Event'] ?? $data['event'] ?? null;
        $reference = $data['data']['transaction_reference'] ?? null;

        if ($event === 'transaction.successful' && $reference) {
            $payment = SquadPayment::where('squad_reference', $reference)->first();
            if ($payment && $payment->status !== 'released') {
                $old = $payment->toArray();
                $payment->update(['status' => 'released', 'disbursed_at' => now()]);
                $this->audit->log('squad_webhook_payment_released', 'SquadPayment', $payment->id, $old, ['status' => 'released']);
            }
        } elseif (in_array($event, ['transaction.failed', 'transaction.reversed']) && $reference) {
            $payment = SquadPayment::where('squad_reference', $reference)->first();
            if ($payment) {
                $old = $payment->toArray();
                $payment->update(['status' => 'failed']);
                $this->audit->log('squad_webhook_payment_failed', 'SquadPayment', $payment->id, $old, ['status' => 'failed']);
            }
        }

        return $this->successResponse([], 'Webhook received.');
    }
}
