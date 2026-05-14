<?php

namespace Database\Seeders;

use App\Models\GhostWorkerAlert;
use App\Models\Worker;
use Illuminate\Database\Seeder;

class GhostAlertSeeder extends Seeder
{
    public function run(): void
    {
        $workers  = Worker::inRandomOrder()->limit(4)->get();
        $severities = ['low', 'medium', 'high', 'critical'];

        $alertTypes = ['biometric_mismatch', 'synthetic_voice', 'face_mismatch', 'replay_attack'];

        foreach ($workers as $i => $worker) {
            GhostWorkerAlert::firstOrCreate(
                ['worker_id' => $worker->id],
                [
                    'worker_id'      => $worker->id,
                    'alert_type'     => $alertTypes[$i % count($alertTypes)],
                    'severity'       => $severities[$i % count($severities)],
                    'ai_confidence'  => rand(60, 99),
                    'salary_at_risk' => $worker->salary_amount ?? 150000,
                    'status'         => $i < 2 ? 'open' : 'resolved',
                ]
            );
        }
    }
}
