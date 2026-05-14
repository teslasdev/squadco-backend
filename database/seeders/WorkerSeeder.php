<?php

namespace Database\Seeders;

use App\Models\Worker;
use App\Models\Mda;
use App\Models\Department;
use Illuminate\Database\Seeder;

class WorkerSeeder extends Seeder
{
    public function run(): void
    {
        $mdas = Mda::with('departments')->get();

        $workers = [
            ['full_name' => 'Aminu Garba',     'ippis_id' => 'IPPIS-48210', 'grade_level' => '12', 'state_of_posting' => 'Kano'],
            ['full_name' => 'Ngozi Okafor',    'ippis_id' => 'IPPIS-48211', 'grade_level' => '10', 'state_of_posting' => 'Enugu'],
            ['full_name' => 'Emeka Obi',       'ippis_id' => 'IPPIS-48212', 'grade_level' => '9',  'state_of_posting' => 'Anambra'],
            ['full_name' => 'Fatima Bello',    'ippis_id' => 'IPPIS-48213', 'grade_level' => '14', 'state_of_posting' => 'Abuja'],
            ['full_name' => 'Chukwuemeka A',   'ippis_id' => 'IPPIS-48214', 'grade_level' => '8',  'state_of_posting' => 'Imo'],
            ['full_name' => 'Aisha Musa',      'ippis_id' => 'IPPIS-48215', 'grade_level' => '11', 'state_of_posting' => 'Sokoto'],
            ['full_name' => 'Samuel Adeyemi',  'ippis_id' => 'IPPIS-48216', 'grade_level' => '13', 'state_of_posting' => 'Lagos'],
            ['full_name' => 'Hauwa Ibrahim',   'ippis_id' => 'IPPIS-48217', 'grade_level' => '7',  'state_of_posting' => 'Borno'],
            ['full_name' => 'Tunde Fashola',   'ippis_id' => 'IPPIS-48218', 'grade_level' => '15', 'state_of_posting' => 'Ogun'],
            ['full_name' => 'Blessing Nwosu',  'ippis_id' => 'IPPIS-48219', 'grade_level' => '9',  'state_of_posting' => 'Rivers'],
            ['full_name' => 'Yusuf Dankwabo',  'ippis_id' => 'IPPIS-48220', 'grade_level' => '10', 'state_of_posting' => 'Gombe'],
            ['full_name' => 'Chidinma Eze',    'ippis_id' => 'IPPIS-48221', 'grade_level' => '8',  'state_of_posting' => 'Ebonyi'],
            ['full_name' => 'Mohammed Dikko',  'ippis_id' => 'IPPIS-48222', 'grade_level' => '12', 'state_of_posting' => 'Kebbi'],
            ['full_name' => 'Adaeze Okonkwo',  'ippis_id' => 'IPPIS-48223', 'grade_level' => '11', 'state_of_posting' => 'Delta'],
            ['full_name' => 'Lateef Salawu',   'ippis_id' => 'IPPIS-48224', 'grade_level' => '9',  'state_of_posting' => 'Osun'],
            ['full_name' => 'Nneka Chukwu',    'ippis_id' => 'IPPIS-48225', 'grade_level' => '10', 'state_of_posting' => 'Cross River'],
            ['full_name' => 'Hassan Abba',     'ippis_id' => 'IPPIS-48226', 'grade_level' => '13', 'state_of_posting' => 'Yobe'],
            ['full_name' => 'Ifeoma Onuoha',   'ippis_id' => 'IPPIS-48227', 'grade_level' => '7',  'state_of_posting' => 'Edo'],
            ['full_name' => 'Babatunde Ola',   'ippis_id' => 'IPPIS-48228', 'grade_level' => '14', 'state_of_posting' => 'Kwara'],
            ['full_name' => 'Zainab Usman',    'ippis_id' => 'IPPIS-48229', 'grade_level' => '8',  'state_of_posting' => 'Katsina'],
        ];

        foreach ($workers as $i => $workerData) {
            $mda        = $mdas[$i % $mdas->count()];
            $department = $mda->departments->first();

            Worker::firstOrCreate(['ippis_id' => $workerData['ippis_id']], array_merge($workerData, [
                'mda_id'        => $mda->id,
                'department_id' => $department?->id,
                'salary_amount' => rand(80_000, 500_000),
                'status'        => 'active',
            ]));
        }
    }
}
