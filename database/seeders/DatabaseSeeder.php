<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Insert missing examples only: re-seeding never overwrites master edits.
        foreach ([
            ['PATHO', 'Pathology', 'LAB-001', 'Complete Blood Count (CBC)', '500.00'],
            ['RADIO', 'Radiology', 'RAD-001', 'X-Ray Chest', '1200.00'],
            ['CONSULT', 'Consultation', 'CON-001', 'Doctor Consultation', '800.00'],
            ['CARDIO', 'Cardiology', 'ECG-001', 'ECG', '600.00'],
        ] as [$code, $department, $serviceCode, $name, $price]) {
            DB::table('departments')->insertOrIgnore(['code' => $code, 'name' => $department, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('services')->insertOrIgnore(['service_code' => $serviceCode, 'name' => $name,
                'department_id' => DB::table('departments')->where('code', $code)->value('id'),
                'unit_price' => $price, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
