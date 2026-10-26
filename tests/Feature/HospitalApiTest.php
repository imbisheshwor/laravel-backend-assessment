<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrderDepartments;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HospitalApiTest extends TestCase
{
    // Subprocesses and after-commit callbacks need committed rows.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    private function patient(): int
    {
        return $this->postJson('/api/patients', ['full_name' => 'Test Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown'])
            ->assertCreated()->json('data.id');
    }

    private function order(array $items = []): int
    {
        return $this->postJson('/api/orders', ['patient_id' => $this->patient(), 'items' => $items ?: [
            ['service_code' => 'LAB-001', 'quantity' => 2], ['service_code' => 'RAD-001', 'quantity' => 1],
        ]])->assertCreated()->json('data.id');
    }

    private function pay(int $id, string $key = 'test-key', array $body = [])
    {
        return $this->postJson('/api/orders/'.$id.'/pay', $body, ['Idempotency-Key' => $key]);
    }

    public function test_department_and_dynamic_service_crud_and_unique_codes(): void
    {
        $department = $this->postJson('/api/departments', ['code' => 'DENTAL', 'name' => 'Dental'])->assertCreated()->json('data.id');
        $this->postJson('/api/departments', ['code' => 'DENTAL', 'name' => 'Duplicate'])->assertUnprocessable();
        $data = ['service_code' => 'DENT-1', 'name' => 'Dental Review', 'department_id' => $department, 'unit_price' => '0.10'];
        $id = $this->postJson('/api/services', $data)->assertCreated()->json('data.id');
        $this->postJson('/api/services', $data)->assertUnprocessable()->assertJsonValidationErrors('service_code');
        $this->patchJson('/api/services/'.$id, ['service_code' => 'LAB-001'])->assertUnprocessable();
        $this->patchJson('/api/services/'.$id, ['unit_price' => '20.50'])->assertOk()->assertJsonPath('data.unit_price', '20.50');
        $this->getJson('/api/services?department_code=DENTAL&is_active=1')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/services/'.$id, ['is_active' => false])->assertOk();
        $this->getJson('/api/services?department_code=DENTAL&is_active=0')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/departments/'.$department, ['is_active' => false])->assertOk();
        $this->postJson('/api/services', array_replace($data, ['service_code' => 'DENT-2']))->assertUnprocessable();
        $this->postJson('/api/services', array_replace($data, ['service_code' => 'ZERO', 'unit_price' => '0.00']))->assertUnprocessable();
    }

    public function test_rejects_unknown_inactive_duplicate_and_inactive_department_items_without_partial_orders(): void
    {
        $patient = $this->patient();
        foreach ([
            [['service_code' => 'UNKNOWN', 'quantity' => 1]],
            [['service_code' => 'LAB-001', 'quantity' => 1], ['service_code' => 'LAB-001', 'quantity' => 1]],
            [['service_code' => 'LAB-001', 'quantity' => 0]],
            [['service_code' => 'LAB-001', 'quantity' => 11]],
            [['service_code' => 'LAB-001', 'quantity' => 1.5]],
            [['service_code' => 'LAB-001', 'quantity' => 1, 'unit_price' => '1.00']],
        ] as $items) {
            $this->postJson('/api/orders', ['patient_id' => $patient, 'items' => $items])->assertUnprocessable();
        }
        DB::table('services')->where('service_code', 'RAD-001')->update(['is_active' => false]);
        $items = [['service_code' => 'LAB-001', 'quantity' => 1], ['service_code' => 'RAD-001', 'quantity' => 1]];
        $this->postJson('/api/orders', ['patient_id' => $patient, 'items' => $items])->assertUnprocessable();
        DB::table('departments')->where('code', 'PATHO')->update(['is_active' => false]);
        $this->postJson('/api/orders', ['patient_id' => $patient, 'items' => [$items[0]]])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_prices_and_department_snapshots_survive_master_edits(): void
    {
        $id = $this->order();
        $this->assertDatabaseHas('orders', ['id' => $id, 'total' => '2200.00']);
        $this->assertDatabaseCount('order_items', 2);
        $lab = DB::table('services')->where('service_code', 'LAB-001')->value('id');
        $radioDepartment = DB::table('departments')->where('code', 'RADIO')->value('id');
        $this->patchJson('/api/services/'.$lab, ['unit_price' => '900.10', 'department_id' => $radioDepartment])->assertOk();
        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'service_id' => $lab, 'unit_price' => '500.00', 'line_total' => '1000.00']);
        $new = $this->order([['service_code' => 'LAB-001', 'quantity' => 3]]);
        $this->assertDatabaseHas('orders', ['id' => $new, 'total' => '2700.30']);
        $this->pay($id)->assertCreated()->assertJsonPath('data.amount', '2200.00');
        (new ProcessOrderDepartments($id))->handle();
        $this->assertDatabaseCount('department_order_processings', 2);
    }

    public function test_order_and_items_roll_back_when_insert_fails_halfway(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_item() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.service_code='RAD-001' THEN RAISE EXCEPTION 'test second-item insert failure'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER reject_item BEFORE INSERT ON order_items FOR EACH ROW EXECUTE FUNCTION reject_item();
SQL);
        try {
            $this->postJson('/api/orders', ['patient_id' => $this->patient(), 'items' => [
                ['service_code' => 'LAB-001', 'quantity' => 1], ['service_code' => 'RAD-001', 'quantity' => 1],
            ]])->assertStatus(500);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
        } finally {
            DB::unprepared('DROP FUNCTION reject_item() CASCADE');
        }
    }

    public function test_payment_is_atomic_audited_and_only_dispatched_after_commit(): void
    {
        $id = $this->order();
        $this->assertDatabaseCount('department_order_processings', 0);
        Queue::assertNothingPushed();
        $this->pay($id)->assertCreated()->assertJsonPath('replayed', false);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'paid']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('processing_outbox', 1);
        Queue::assertPushed(ProcessOrderDepartments::class, 1);
        foreach (['order.created', 'payment.attempted', 'payment.succeeded', 'order.status_changed'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'event' => $event, 'actor' => 'anonymous']);
        }
        $log = DB::table('audit_logs')->where('event', 'payment.succeeded')->first();
        $this->assertNotEmpty($log->request_id);
        $this->assertNotEmpty($log->new_values);
    }

    public function test_failure_rolls_back_all_business_changes_and_same_key_can_retry(): void
    {
        $id = $this->order();
        $this->pay($id, 'retry-key', ['simulate_failure' => true])->assertStatus(500);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'unpaid', 'paid_at' => null]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('processing_outbox', 0);
        $this->assertDatabaseCount('department_order_processings', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'payment.succeeded']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.failed']);
        Queue::assertNothingPushed();
        $this->pay($id, 'retry-key')->assertCreated();
    }

    public function test_dispatch_waits_for_outer_commit_and_is_discarded_on_rollback(): void
    {
        $id = $this->order();
        DB::beginTransaction();
        try {
            $this->pay($id, 'outer-rollback')->assertCreated();
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'unpaid']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('processing_outbox', 0);
        Queue::assertNothingPushed();
        $this->pay($id, 'outer-rollback')->assertCreated();
        Queue::assertPushed(ProcessOrderDepartments::class, 1);
    }

    public function test_audit_insert_failure_also_rolls_back_payment_and_paid_status(): void
    {
        $id = $this->order();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_success_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.event='payment.succeeded' THEN RAISE EXCEPTION 'test audit insert failure'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER reject_success_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_success_audit();
SQL);
        try {
            $this->pay($id)->assertStatus(500);
            $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'unpaid', 'paid_at' => null]);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('processing_outbox', 0);
            $this->assertDatabaseHas('audit_logs', ['event' => 'payment.failed']);
            Queue::assertNothingPushed();
        } finally {
            DB::unprepared('DROP FUNCTION reject_success_audit() CASCADE');
        }
    }

    public function test_idempotency_replays_same_payment_and_conflicts_other_keys_or_orders(): void
    {
        $id = $this->order();
        $payment = $this->pay($id, 'stable-key')->assertCreated()->json('data');
        $this->pay($id, 'stable-key')->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data', $payment);
        $this->pay($id, 'another-key')->assertConflict();
        $other = $this->order();
        $this->pay($other, 'stable-key')->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $other, 'status' => 'unpaid']);
        $this->assertDatabaseCount('payments', 1);
        Queue::assertPushed(ProcessOrderDepartments::class, 1);
    }

    public function test_department_job_retries_are_safe_and_unpaid_orders_do_nothing(): void
    {
        $id = $this->order();
        (new ProcessOrderDepartments($id))->handle();
        $this->assertDatabaseCount('department_order_processings', 0);
        $this->pay($id)->assertCreated();
        (new ProcessOrderDepartments($id))->handle();
        (new ProcessOrderDepartments($id))->handle();
        $this->assertDatabaseCount('department_order_processings', 2);
        $this->assertNotNull(DB::table('processing_outbox')->where('order_id', $id)->value('processed_at'));
    }

    public function test_outbox_recovers_a_missed_dispatch(): void
    {
        $id = $this->order();
        $this->pay($id)->assertCreated();
        Queue::fake();
        $this->artisan('outbox:publish')->assertSuccessful();
        Queue::assertPushed(ProcessOrderDepartments::class, 1);
        (new ProcessOrderDepartments($id))->handle();
        Queue::fake();
        $this->artisan('outbox:publish')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_search_filters_through_items_without_duplicates_and_paginates(): void
    {
        $id = $this->order();
        $this->pay($id)->assertCreated();
        $this->order();
        $this->getJson('/api/orders?status=paid&service_code=LAB-001&department_code=PATHO&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/orders?department_code=UNKNOWN')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/orders?from=2000-01-01&to=2000-01-31')->assertOk()->assertJsonCount(0, 'data');
        $patient = Order::findOrFail($id)->patient_id;
        $this->getJson('/api/patients/'.$patient)->assertOk();
        $this->getJson('/api/patients/'.$patient.'/orders?per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/orders?per_page=101')->assertUnprocessable();
        $this->getJson('/api/orders?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    }

    public function test_payment_requires_key_and_cannot_accept_client_amount(): void
    {
        $id = $this->order();
        $this->postJson('/api/orders/'.$id.'/pay')->assertUnprocessable();
        $this->pay($id, 'valid-key', ['amount' => '0.01'])->assertUnprocessable();
        config(['payments.failure_simulation' => false]);
        $this->pay($id, 'valid-key', ['simulate_failure' => true])->assertUnprocessable();
    }

    public function test_audit_history_is_append_only_even_with_direct_sql(): void
    {
        $this->order();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audit_logs')->update(['actor' => 'tampered']);
    }

    public function test_small_bulk_dataset_has_consistent_totals_and_no_missing_payments(): void
    {
        $this->artisan('dataset:generate', ['--patients' => 3, '--batch' => 2])->assertSuccessful();
        $this->assertDatabaseCount('patients', 3);
        $this->assertDatabaseCount('orders', 12);
        $this->assertDatabaseCount('order_items', 48);
        $this->assertDatabaseCount('payments', 6);
        $this->assertSame(0, DB::table('orders')->where('status', 'paid')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments')->whereColumn('payments.order_id', 'orders.id'))->count());
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM orders o WHERE total <> (SELECT sum(line_total) FROM order_items i WHERE i.order_id=o.id)'));
        $this->artisan('dataset:verify', ['--output' => 'storage/app/dataset-integrity-test.json'])->assertSuccessful();
        $unpaid = DB::table('orders')->where('status', 'unpaid')->value('id');
        DB::table('orders')->where('id', $unpaid)->update(['total' => '0.01']);
        $this->artisan('dataset:verify', ['--output' => 'storage/app/dataset-integrity-test.json'])->assertFailed();
    }
}
