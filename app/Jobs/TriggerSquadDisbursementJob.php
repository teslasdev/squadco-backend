<?php

namespace App\Jobs;

use App\Models\Verification;
use App\Models\SquadPayment;
use App\Services\SquadPaymentService;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TriggerSquadDisbursementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Verification $verification) {}

    public function handle(SquadPaymentService $squad, AuditService $audit): void
    {
        $worker = $this->verification->worker;

        if ($this->verification->verdict !== 'PASS') {
            Log::info("Disbursement skipped: worker {$worker->id} verdict not PASS.");
            return;
        }

        // Check if a pending payment already exists for this verification
        $existing = SquadPayment::where('verification_id', $this->verification->id)
            ->whereIn('status', ['released', 'pending'])
            ->first();

        if ($existing) return;

        $payment = SquadPayment::create([
            'worker_id'          => $worker->id,
            'cycle_id'           => $this->verification->cycle_id,
            'verification_id'    => $this->verification->id,
            'amount'             => $worker->salary_amount,
            'bank_name'          => $worker->bank_name,
            'bank_account_masked' => $this->maskAccountNumber($worker->bank_account_number),
            'squad_reference'    => 'SQD-' . strtoupper(Str::random(8)),
            'status'             => 'pending',
        ]);

        $result = $squad->disburse([
            'account_number' => $worker->bank_account_number,
            'amount'         => (int)($worker->salary_amount * 100), // convert to kobo
            'narration'      => "Salary: {$worker->ippis_id} - {$worker->full_name}",
            'currency_id'    => 'NGN',
        ]);

        if ($result['success']) {
            $payment->update([
                'status'          => 'released',
                'squad_reference' => $result['reference'],
                'disbursed_at'    => now(),
            ]);

            $this->verification->update([
                'salary_released' => true,
                'squad_reference' => $result['reference'],
            ]);

            $audit->log('salary_released', 'Worker', $worker->id, [], [
                'squad_reference' => $result['reference'],
                'amount'          => $worker->salary_amount,
            ]);
        } else {
            $payment->update(['status' => 'failed']);
            Log::warning("Squad disbursement failed for worker {$worker->id}: {$result['message']}");
        }
    }

    private function maskAccountNumber(?string $account): string
    {
        if (!$account || strlen($account) < 4) return '****';
        return str_repeat('*', strlen($account) - 4) . substr($account, -4);
    }
}
