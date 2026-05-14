<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Mda;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'MOF'  => ['Budget & Planning', 'Revenue Management', 'Treasury'],
            'MOE'  => ['Primary Education', 'Tertiary Education', 'Technical Education'],
            'NNPC' => ['Exploration', 'Downstream', 'Gas & Power'],
            'NCC'  => ['Licensing', 'Spectrum Management', 'Consumer Affairs'],
            'FIRS' => ['Tax Assessment', 'Audit', 'IT & Innovation'],
            'MOH'  => ['Primary Healthcare', 'Epidemiology', 'Medical Supplies'],
            'MOW'  => ['Roads & Bridges', 'Housing', 'Urban Renewal'],
            'CBN'  => ['Monetary Policy', 'Financial Stability', 'IT & Payments'],
        ];

        foreach ($map as $code => $departments) {
            $mda = Mda::where('code', $code)->first();
            if (!$mda) continue;
            foreach ($departments as $name) {
                Department::firstOrCreate(['mda_id' => $mda->id, 'name' => $name], [
                    'code' => strtoupper(str_replace(' ', '_', substr($name, 0, 10))) . '_' . $mda->code,
                ]);
            }
        }
    }
}
