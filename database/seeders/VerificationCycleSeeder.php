<?php

namespace Database\Seeders;

use App\Models\VerificationCycle;
use App\Models\User;
use Illuminate\Database\Seeder;

class VerificationCycleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'super_admin')->first();

        VerificationCycle::firstOrCreate(
            ['name' => 'May 2026 Cycle'],
            [
                'name'             => 'May 2026 Cycle',
                'cycle_month'      => '2026-05-01',
                'status'           => 'completed',
                'total_workers'    => 20,
                'verified_count'   => 16,
                'failed_count'     => 4,
                'inconclusive_count' => 0,
                'payroll_released' => 5_840_000,
                'payroll_blocked'  => 960_000,
                'created_by'       => $admin?->id,
                'started_at'       => now()->subDays(10),
                'completed_at'     => now()->subDays(5),
            ]
        );
    }
}
