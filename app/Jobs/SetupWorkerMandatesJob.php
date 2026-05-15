<?php

namespace App\Jobs;

use App\Models\MandateAccount;
use App\Models\Settlement;
use App\Models\SquadPayment;
use App\Models\Worker;
use App\Models\WorkerMandate;
use App\Models\VerificationCycle;
use App\Services\AuditService;
use App\Services\SquadPaymentService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SetupWorkerMandatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(SquadPaymentService $squad, AuditService $audit): void
    {
        $mandateAccount = $this->getPrimaryMandateAccount();

        if (!$mandateAccount) {
            Log::warning('SetupWorkerMandatesJob skipped: no active primary mandate account configured.');
            return;
        }

        Worker::query()
            ->where('status', 'active')
            ->whereDoesntHave('mandate', function ($query) {
                $query->whereIn('status', ['pending', 'created']);
            })
            ->chunkById(100, function ($workers) use ($squad, $audit, $mandateAccount) {
                foreach ($workers as $worker) {
                    $resolvedAmount = (float) $worker->salary_amount;
                    if ($resolvedAmount <= 0) {
                        Log::warning('SetupWorkerMandatesJob skipped worker ' . $worker->id . ': amount is not configured.');
                        continue;
                    }

                    $resolvedStartDate = $this->rollDateToCurrentMonth($mandateAccount->start_date?->toDateString() ?? now()->toDateString());
                    $resolvedEndDate = $this->rollDateToCurrentMonth($mandateAccount->end_date?->toDateString() ?? now()->addMonth()->toDateString());
                    if (Carbon::parse($resolvedEndDate)->lessThanOrEqualTo(Carbon::parse($resolvedStartDate))) {
                        $resolvedEndDate = Carbon::parse($resolvedStartDate)->addMonthNoOverflow()->toDateString();
                    }
                    $resolvedDescription = $mandateAccount->description ?? ('Payroll mandate for worker ' . $worker->id);

                    $alreadyPaid = SquadPayment::where('worker_id', $worker->id)
                        ->where('status', 'released')
                        ->exists();

                    if ($alreadyPaid) {
                        WorkerMandate::updateOrCreate(
                            ['worker_id' => $worker->id],
                            [
                                'transaction_reference' => 'SKIPPED-PAID-' . $worker->id,
                                'mandate_type' => 'emandate',
                                'amount' => $resolvedAmount,
                                'bank_code' => (string) $mandateAccount->bank_code,
                                'account_number' => (string) $mandateAccount->account_number,
                                'account_name' => null,
                                'customer_email' => $worker->email ?? ('worker' . $worker->id . '@example.com'),
                                'start_date' => $resolvedStartDate,
                                'end_date' => $resolvedEndDate,
                                'status' => 'skipped_paid',
                                'description' => 'Skipped mandate creation because worker already has a released payment.',
                                'response_payload' => ['reason' => 'already_paid'],
                            ]
                        );

                        continue;
                    }

                    $reference = 'mandate_' . $worker->id . '_' . Str::lower(Str::random(10));
                    $settlement = $this->resolveSettlementForMandate($worker, $reference);
                    [$firstName, $lastName] = $this->splitName($worker->full_name);

                    $payload = [
                        'mandate_type' => 'emandate',
                        'amount' => (string) ((int) round($resolvedAmount * 100)),
                        'account_number' => (string) $mandateAccount->account_number,
                        'bank_code' => (string) $mandateAccount->bank_code,
                        'description' => $resolvedDescription,
                        'start_date' => $resolvedStartDate,
                        'end_date' => $resolvedEndDate,
                        'customer_email' => $worker->email ?? ('worker' . $worker->id . '@example.com'),
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

                    $mandate = WorkerMandate::updateOrCreate(
                        ['worker_id' => $worker->id],
                        [
                            'settlement_id' => $settlement?->id,
                            'transaction_reference' => $reference,
                            'mandate_type' => 'emandate',
                            'amount' => $resolvedAmount,
                            'bank_code' => (string) $mandateAccount->bank_code,
                            'account_number' => (string) $mandateAccount->account_number,
                            'account_name' => null,
                            'customer_email' => $payload['customer_email'],
                            'start_date' => $payload['start_date'],
                            'end_date' => $payload['end_date'],
                            'status' => 'pending',
                            'approved' => false,
                            'ready_to_debit' => false,
                            'description' => $payload['description'],
                            'request_payload' => $payload,
                            'initiated_at' => now(),
                        ]
                    );

                    $result = $squad->createMandate($payload);

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

                        $audit->log('worker_mandate_created', 'Worker', $worker->id, [], [
                            'transaction_reference' => $reference,
                            'mandate_reference' => $result['mandate_reference'] ?? null,
                        ]);
                    } else {
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

                        Log::warning('Mandate creation failed for worker ' . $worker->id . ': ' . $result['message']);
                    }
                }
            });
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

    private function rollDateToCurrentMonth(string $date): string
    {
        $source = Carbon::parse($date);
        $now = now();
        $day = min($source->day, $now->copy()->endOfMonth()->day);

        return Carbon::create($now->year, $now->month, $day)->toDateString();
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
