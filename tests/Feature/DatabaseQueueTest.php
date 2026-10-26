<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrderDepartments;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseQueueTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_real_database_queue_worker_hands_off_departments_and_retries_safely(): void
    {
        $this->seed();
        $patient = $this->postJson('/api/patients', ['full_name' => 'Queue Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown'])->assertCreated()->json('data.id');
        $order = $this->postJson('/api/orders', ['patient_id' => $patient, 'items' => [
            ['service_code' => 'LAB-001', 'quantity' => 1], ['service_code' => 'RAD-001', 'quantity' => 1],
        ]])->assertCreated()->json('data.id');
        $this->postJson('/api/orders/'.$order.'/pay', [], ['Idempotency-Key' => 'real-worker'])->assertCreated();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('department_order_processings', 0);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 10])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('department_order_processings', 2);
        $this->assertNotNull(DB::table('processing_outbox')->where('order_id', $order)->value('processed_at'));
        ProcessOrderDepartments::dispatch($order);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 10])->assertSuccessful();
        $this->assertDatabaseCount('department_order_processings', 2);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
