<?php

namespace Database\Seeders;

use App\Models\Mda;
use Illuminate\Database\Seeder;

class MdaSeeder extends Seeder
{
    public function run(): void
    {
        $mdas = [
            ['name' => 'Ministry of Finance',       'code' => 'MOF',  'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@finance.gov.ng',    'head_name' => 'Zainab Ahmed'],
            ['name' => 'Ministry of Education',     'code' => 'MOE',  'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@education.gov.ng',  'head_name' => 'Adamu Adamu'],
            ['name' => 'NNPC',                      'code' => 'NNPC', 'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@nnpc.gov.ng',       'head_name' => 'Mele Kyari'],
            ['name' => 'NCC',                       'code' => 'NCC',  'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@ncc.gov.ng',        'head_name' => 'Umar Danbatta'],
            ['name' => 'FIRS',                      'code' => 'FIRS', 'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@firs.gov.ng',       'head_name' => 'Muhammad Nami'],
            ['name' => 'Ministry of Health',        'code' => 'MOH',  'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@health.gov.ng',     'head_name' => 'Osagie Ehanire'],
            ['name' => 'Ministry of Works',         'code' => 'MOW',  'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@works.gov.ng',      'head_name' => 'Dave Umahi'],
            ['name' => 'Central Bank of Nigeria',   'code' => 'CBN',  'state' => 'FCT', 'ministry_type' => 'federal', 'contact_email' => 'info@cbn.gov.ng',        'head_name' => 'Olayemi Cardoso'],
        ];

        foreach ($mdas as $mda) {
            Mda::firstOrCreate(['code' => $mda['code']], $mda);
        }
    }
}
