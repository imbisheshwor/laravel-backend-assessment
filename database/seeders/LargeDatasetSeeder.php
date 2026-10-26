<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class LargeDatasetSeeder extends Seeder
{
    /**
     * Seeds base master data and immediately generates the 10M+ heavy dataset.
     *
     * Usage:
     *   php artisan db:seed --class=LargeDatasetSeeder
     */
    public function run(): void
    {
        // 1. Ensure base departments and services are seeded first
        $this->call(DatabaseSeeder::class);

        // 2. Trigger the high-speed dataset generator
        $this->command?->info('Triggering high-performance bulk dataset generator …');

        if ($this->command) {
            $this->command->call('dataset:generate', [
                '--patients' => 500000,
                '--turbo'    => true,
            ]);
        } else {
            Artisan::call('dataset:generate', [
                '--patients' => 500000,
                '--turbo'    => true,
            ]);
        }
    }
}
