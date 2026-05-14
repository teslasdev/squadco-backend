<?php

namespace Database\Seeders;

use App\Models\SquadPayment;
use App\Models\Worker;
use App\Models\VerificationCycle;
use Illuminate\Database\Seeder;

class SquadPaymentSeeder extends Seeder
{
    public function run(): void
    {
        $cycle   = VerificationCycle::first();
        $workers = Worker::limit(10)->get();

        if (!$cycle) return;

        $statuses = ['released', 'released', 'released', 'blocked', 'pending'];

        foreach ($workers as $i => $worker) {
            $status = $statuses[$i % count($statuses)];
            SquadPayment::firstOrCreate(
                ['worker_id' => $worker->id, 'cycle_id' => $cycle->id],
                [
                    'worker_id'       => $worker->id,
                    'cycle_id'        => $cycle->id,
                    'amount'          => $worker->salary_amount ?? rand(80_000, 400_000),
                    'status'          => $status,
                    'squad_reference' => 'REF-' . strtoupper(uniqid()),
                    'disbursed_at'    => $status === 'released' ? now()->subDays(rand(1, 10)) : null,
                ]
            );
        }
    }
}
