<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE departments (
 id bigserial PRIMARY KEY, code varchar(32) NOT NULL UNIQUE, name varchar(150) NOT NULL,
 is_active boolean NOT NULL DEFAULT true, created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE services (
 id bigserial PRIMARY KEY, service_code varchar(32) NOT NULL UNIQUE, name varchar(150) NOT NULL,
 department_id bigint NOT NULL REFERENCES departments(id), unit_price numeric(12,2) NOT NULL CHECK (unit_price > 0 AND unit_price <= 99999999.99),
 is_active boolean NOT NULL DEFAULT true, created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE INDEX services_department_active_idx ON services(department_id, is_active, id);
CREATE TABLE patients (
 id bigserial PRIMARY KEY, full_name varchar(150) NOT NULL, date_of_birth date NOT NULL,
 sex varchar(16) NOT NULL CHECK (sex IN ('female','male','other','unknown')), phone varchar(30),
 created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE orders (
 id bigserial PRIMARY KEY, patient_id bigint NOT NULL REFERENCES patients(id),
 status varchar(16) NOT NULL DEFAULT 'unpaid' CHECK (status IN ('unpaid','paid')),
 total numeric(16,2) NOT NULL CHECK (total > 0), paid_at timestamp,
 created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
 CHECK ((status = 'paid') = (paid_at IS NOT NULL))
);
CREATE INDEX orders_status_created_idx ON orders(status, created_at DESC, id DESC);
CREATE INDEX orders_created_idx ON orders(created_at DESC, id DESC);
CREATE INDEX orders_patient_created_idx ON orders(patient_id, created_at DESC, id DESC);
CREATE TABLE order_items (
 id bigserial PRIMARY KEY, order_id bigint NOT NULL REFERENCES orders(id),
 service_id bigint NOT NULL REFERENCES services(id), department_id bigint NOT NULL REFERENCES departments(id),
 service_code varchar(32) NOT NULL, service_name varchar(150) NOT NULL,
 unit_price numeric(12,2) NOT NULL CHECK (unit_price > 0),
 quantity smallint NOT NULL CHECK (quantity BETWEEN 1 AND 10),
 line_total numeric(16,2) NOT NULL CHECK (line_total = unit_price * quantity),
 created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(order_id, service_id)
);
CREATE INDEX items_service_code_order_idx ON order_items(service_code, order_id);
CREATE INDEX items_department_order_idx ON order_items(department_id, order_id);
CREATE INDEX items_service_id_idx ON order_items(service_id);
CREATE TABLE payments (
 id bigserial PRIMARY KEY, order_id bigint NOT NULL UNIQUE REFERENCES orders(id),
 idempotency_key varchar(128) NOT NULL UNIQUE, amount numeric(16,2) NOT NULL CHECK (amount > 0),
 status varchar(16) NOT NULL DEFAULT 'succeeded' CHECK (status = 'succeeded'),
 created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE TABLE audit_logs (
 id bigserial PRIMARY KEY, event varchar(80) NOT NULL, entity_type varchar(40) NOT NULL,
 entity_id bigint NOT NULL, old_values jsonb, new_values jsonb, request_id uuid NOT NULL,
 actor varchar(100) NOT NULL, ip_address inet, created_at timestamp NOT NULL
);
CREATE INDEX audit_entity_idx ON audit_logs(entity_type, entity_id, id);
CREATE OR REPLACE FUNCTION prevent_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'audit_logs is append-only'; END; $$;
CREATE TRIGGER audit_no_mutation BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();
CREATE TRIGGER audit_no_truncate BEFORE TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION prevent_audit_mutation();
CREATE TABLE processing_outbox (
 id bigserial PRIMARY KEY, order_id bigint NOT NULL UNIQUE REFERENCES orders(id),
 processed_at timestamp, created_at timestamp NOT NULL, updated_at timestamp NOT NULL
);
CREATE INDEX outbox_pending_idx ON processing_outbox(id) WHERE processed_at IS NULL;
CREATE TABLE department_order_processings (
 id bigserial PRIMARY KEY, order_id bigint NOT NULL REFERENCES orders(id),
 department_id bigint NOT NULL REFERENCES departments(id), status varchar(20) NOT NULL DEFAULT 'handed_off' CHECK (status = 'handed_off'),
 created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(order_id, department_id)
);
CREATE TABLE jobs (
 id bigserial PRIMARY KEY, queue varchar(255) NOT NULL, payload text NOT NULL,
 attempts smallint NOT NULL, reserved_at integer, available_at integer NOT NULL, created_at integer NOT NULL
);
CREATE INDEX jobs_queue_idx ON jobs(queue);
CREATE TABLE failed_jobs (
 id bigserial PRIMARY KEY, uuid varchar(255) NOT NULL UNIQUE, connection text NOT NULL, queue text NOT NULL,
 payload text NOT NULL, exception text NOT NULL, failed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS failed_jobs, jobs, department_order_processings, processing_outbox, audit_logs, payments, order_items, orders, patients, services, departments CASCADE; DROP FUNCTION IF EXISTS prevent_audit_mutation();');
    }
};
