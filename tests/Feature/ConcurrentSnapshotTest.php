<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentSnapshotTest extends TestCase
{
    use DatabaseMigrations;

    public function test_creation_waits_for_concurrent_price_updates_and_saves_one_consistent_snapshot(): void
    {
        $this->seed();
        $patient = $this->postJson('/api/patients', ['full_name' => 'Snapshot Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown'])->assertCreated()->json('data.id');
        $connection = config('database.connections.pgsql');
        config(['database.connections.observer' => $connection]);
        $observer = DB::connection('observer');
        $label = 'snapshot-'.Str::uuid();
        $body = json_encode(['patient_id' => $patient, 'items' => [
            ['service_code' => 'LAB-001', 'quantity' => 1], ['service_code' => 'RAD-001', 'quantity' => 1],
        ]], JSON_THROW_ON_ERROR);
        $process = new Process([PHP_BINARY, base_path('scripts/order-worker.php'), $body, $label], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => false,
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'LOG_LEVEL' => 'critical',
        ]);
        $process->setTimeout(20);
        DB::beginTransaction();
        try {
            DB::table('services')->where('service_code', 'LAB-001')->update(['unit_price' => '900.10']);
            DB::table('services')->where('service_code', 'RAD-001')->update(['unit_price' => '1300.20']);
            $process->start();
            $deadline = microtime(true) + 10;
            $blocked = false;
            do {
                $blocked = $observer->table('pg_stat_activity')->where('application_name', $label)->where('wait_event_type', 'Lock')->exists();
                if ($blocked || !$process->isRunning()) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            // Commit only after PostgreSQL reports the reader's lock wait.
            $this->assertTrue($blocked, 'Creation did not wait on the uncommitted price editor: '.$process->getErrorOutput());
            $this->assertDatabaseCount('orders', 0);
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(201, $result['status'], $process->getOutput());
            $this->assertSame('2200.30', $result['body']['data']['total']);
            $this->assertDatabaseHas('order_items', ['service_code' => 'LAB-001', 'unit_price' => '900.10']);
            $this->assertDatabaseHas('order_items', ['service_code' => 'RAD-001', 'unit_price' => '1300.20']);
        } finally {
            if (DB::transactionLevel() > 0) { DB::rollBack(); }
            if ($process->isRunning()) { $process->stop(1); }
            DB::disconnect('observer');
        }
    }
}
