<?php

namespace Database\Seeders;

use App\Models\MandateAccount;
use App\Models\Mda;
use App\Models\Settlement;
use App\Models\VerificationCycle;
use App\Models\Worker;
use App\Models\WorkerMandate;
use Illuminate\Database\Seeder;

class MandateDisbursementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $mda = Mda::firstOrCreate(
            ['code' => 'MDA-DEMO'],
            [
                'name' => 'Demo MDA',
                'state' => 'Lagos',
                'ministry_type' => 'federal',
                'contact_email' => 'demo-mda@example.com',
                'head_name' => 'Demo Head',
                'worker_count' => 1,
                'risk_score' => 0,
            ]
        );

        $worker = Worker::firstOrCreate(
            ['ippis_id' => 'IPPIS-DEMO-0001'],
            [
                'full_name' => 'William Udousoro',
                'mda_id' => $mda->id,
                'status' => 'active',
                'salary_amount' => 50000,
                'email' => 'willia@gmail.com',
                'phone' => '08132448008',
                'bvn' => '22984135000',
                'bank_name' => 'GTB TESTING',
                'bank_account_number' => '0179088393',
                'bank_code' => '050',
                'home_address' => 'No 11 Claytus Street, Sabo Yaba',
            ]
        );

        $cycle = VerificationCycle::firstOrCreate(
            ['name' => 'Demo Mandate Cycle'],
            [
                'cycle_month' => now()->startOfMonth()->toDateString(),
                'status' => 'pending',
                'federation' => 'Federal',
                'created_by' => null,
            ]
        );

        $settlement = Settlement::updateOrCreate(
            [
                'mda_id' => $mda->id,
                'cycle_id' => $cycle->id,
            ],
            [
                'status' => 'pending',
                'squad_batch_id' => 'livepilot0260118',
                'mandate_transaction_reference' => 'livepilot0260118',
                'mandate_id' => 'sqaudDDa27chviz8nwhv3d6w4gy',
                'mandate_status' => 'ready',
                'mandate_ready_to_debit' => true,
                'mandate_last_event' => 'mandates.ready',
                'mandate_debit_reference' => 'super32333',
            ]
        );

        WorkerMandate::updateOrCreate(
            ['worker_id' => $worker->id],
            [
                'settlement_id' => $settlement->id,
                'transaction_reference' => 'livepilot0260118',
                'debit_transaction_reference' => 'super32333',
                'squad_mandate_reference' => 'sqaudDDa27chviz8nwhv3d6w4gy',
                'mandate_type' => 'emandate',
                'amount' => 50000,
                'bank_code' => '050',
                'account_number' => '0179088393',
                'account_name' => 'william udousoro',
                'customer_email' => 'willia@gmail.com',
                'start_date' => now()->startOfMonth()->addDays(2)->toDateString(),
                'end_date' => now()->addMonths(5)->endOfMonth()->toDateString(),
                'status' => 'debiting',
                'approved' => true,
                'ready_to_debit' => true,
                'description' => 'Demo mandate for webhook flow testing',
                'request_payload' => [
                    'transaction_reference' => 'livepilot0260118',
                    'amount' => 50000,
                ],
                'response_payload' => [
                    'message' => 'Seeded demo mandate for webhook testing.',
                ],
                'initiated_at' => now()->subMinutes(10),
                'failed_at' => null,
            ]
        );

        MandateAccount::firstOrCreate(
            [
                'account_number' => '0179088393',
                'bank_code' => '050',
            ],
            [
                'description' => 'Primary demo mandate account',
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->addMonths(6)->toDateString(),
                'is_primary' => true,
                'is_active' => true,
            ]
        );

        MandateAccount::query()
            ->where('account_number', '!=', '0179088393')
            ->update(['is_primary' => false]);
    }
}
