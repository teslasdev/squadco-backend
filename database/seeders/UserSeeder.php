<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'adamu.bello@ippis.gov.ng'],
            [
                'name'     => 'Adamu Bello',
                'email'    => 'adamu.bello@ippis.gov.ng',
                'password' => Hash::make('secret'),
                'role'     => 'super_admin',
            ]
        );

        User::firstOrCreate(
            ['email' => 'payroll@finance.gov.ng'],
            [
                'name'     => 'Payroll Officer',
                'email'    => 'payroll@finance.gov.ng',
                'password' => Hash::make('secret'),
                'role'     => 'payroll_officer',
            ]
        );
    }
}
