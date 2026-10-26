<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** These requests fail before any database query and need no migrations. */
class RequestValidationTest extends TestCase
{
    #[DataProvider('invalidSearches')]
    public function test_search_input_is_validated_before_querying(string $query, string $field): void
    {
        $this->getJson('/api/orders?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidSearches(): array
    {
        return [
            ['per_page=0', 'per_page'], ['per_page=101', 'per_page'], ['page=0', 'page'],
            ['status=not-a-status', 'status'], ['patient_id=-1', 'patient_id'],
            ['from=invalid', 'from'], ['to=2026-02-30', 'to'],
            ['from=2026-02-01&to=2026-01-01', 'to'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantity_requires_a_real_json_integer(mixed $quantity): void
    {
        // Intentionally omit patient_id: validation must finish without DB access.
        $this->postJson('/api/orders', ['items' => [['service_code' => 'ANY-CODE', 'quantity' => $quantity]]], [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
    }

    public static function invalidQuantities(): array
    {
        return [[true], [false], [1.0], [1.5], ['1'], [0], [11], [null]];
    }

    public function test_order_requires_patient_items_and_rejects_client_prices_even_null(): void
    {
        $this->postJson('/api/orders', [])->assertUnprocessable()->assertJsonValidationErrors(['patient_id', 'items']);
        $this->postJson('/api/orders', ['total' => null, 'unit_price' => null, 'items' => [
            ['service_code' => 'ANY-CODE', 'quantity' => 1, 'unit_price' => null],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['total', 'unit_price', 'items.0.unit_price']);
    }

    public function test_duplicate_service_codes_fail_before_order_creation(): void
    {
        $this->postJson('/api/orders', ['items' => [
            ['service_code' => 'ANY-CODE', 'quantity' => 1], ['service_code' => 'ANY-CODE', 'quantity' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.service_code', 'items.1.service_code']);
    }

    public function test_payment_validates_key_and_rejects_client_amount_before_lookup(): void
    {
        $this->postJson('/api/orders/1/pay')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/orders/1/pay', [], ['Idempotency-Key' => 'invalid key'])->assertUnprocessable();
        $this->postJson('/api/orders/1/pay', ['amount' => '0.01'], ['Idempotency-Key' => 'valid-key'])->assertUnprocessable();
        config(['payments.failure_simulation' => false]);
        $this->postJson('/api/orders/1/pay', ['simulate_failure' => true], ['Idempotency-Key' => 'valid-key'])->assertUnprocessable();
    }

    public function test_required_patient_and_master_fields_are_validated(): void
    {
        $this->postJson('/api/patients', [])->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'date_of_birth', 'sex']);
        $this->postJson('/api/departments', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
        $this->postJson('/api/services', [])->assertUnprocessable()->assertJsonValidationErrors(['service_code', 'name', 'department_id', 'unit_price']);
    }
}
