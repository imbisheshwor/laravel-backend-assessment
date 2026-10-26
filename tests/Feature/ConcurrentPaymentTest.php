<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentPaymentTest extends TestCase
{
    use DatabaseMigrations;

    public function test_twenty_simultaneous_requests_with_the_same_key(): void
    {
        $this->race('same');
    }

    public function test_twenty_simultaneous_requests_with_different_keys(): void
    {
        $this->race('different');
    }

    private function race(string $mode): void
    {
        $this->seed();
        $patient = $this->postJson('/api/patients', ['full_name' => 'Concurrent Patient', 'date_of_birth' => '1980-01-01', 'sex' => 'unknown'])->assertCreated()->json('data.id');
        $order = $this->postJson('/api/orders', ['patient_id' => $patient, 'items' => [
            ['service_code' => 'LAB-001', 'quantity' => 1], ['service_code' => 'RAD-001', 'quantity' => 1],
        ]])->assertCreated()->json('data.id');
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'), 'QUEUE_CONNECTION' => 'database',
        ];
        $process = new Process([PHP_BINARY, base_path('scripts/concurrent-payments.php'), '--order='.$order, '--workers=20', '--mode='.$mode], base_path(), $env);
        $process->setTimeout(110)->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['passed']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('processing_outbox', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(20, DB::table('audit_logs')->where('event', 'payment.attempted')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'payment.succeeded')->count());
    }
}
