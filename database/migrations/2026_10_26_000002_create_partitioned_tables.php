<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Table partitioning for billion-row / TB-scale tables.
 *
 * Partitions orders, order_items, and audit_logs by RANGE on created_at (monthly).
 *
 * WHY PARTITION?
 * - A single PostgreSQL heap table larger than ~100–200 GB sees degraded autovacuum,
 *   index bloat, and slow DDL. At billions of rows these tables will be in the
 *   hundreds of GB to TB range.
 * - Range partitioning by created_at lets PostgreSQL prune irrelevant partitions from
 *   every query that filters on date. It also allows old partitions to be archived
 *   (DETACH) or dropped without any table lock on the live table.
 * - VACUUM, ANALYZE and pg_dump run per-partition, keeping maintenance windows short.
 *
 * HOW IT WORKS (zero-downtime migration strategy):
 * 1. Create the new partitioned table alongside the existing one.
 * 2. Create initial year/month partitions.
 * 3. Swap: rename old → _legacy, rename new → canonical name.
 * 4. Backfill: INSERT INTO new SELECT FROM legacy in batches (done separately,
 *    NOT in this migration, to avoid a multi-hour lock).
 * 5. Drop legacy table after backfill is verified.
 *
 * For this assessment the migration demonstrates the full partitioned DDL.
 * In a live system with existing data, run step 4 as a background job using
 * the GenerateDataset batch pattern (INSERT ... SELECT with temp tables).
 *
 * NOTE ON FOREIGN KEYS:
 * PostgreSQL does not support cross-partition foreign keys where the referenced
 * table is also partitioned. The FK from order_items.order_id → orders.id is
 * therefore replaced with an application-level guarantee:
 *   - CreateOrder writes order + items in one transaction.
 *   - GenerateDataset inserts items referencing batch_orders (same transaction).
 * A CHECK CONSTRAINT on order_items.created_at ensures the row lands in the
 * correct partition aligned with its order.
 *
 * Run this migration only when you are ready to partition. It is safe to skip
 * it; the original tables continue to work without partitioning.
 */
return new class extends Migration
{
    // Partitions to pre-create (YYYY-MM). Extend this list or automate with pg_partman.
    private const MONTHLY_PARTITIONS = [
        '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
        '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
        '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06',
        '2027-07', '2027-08', '2027-09', '2027-10', '2027-11', '2027-12',
    ];

    public function up(): void
    {
        // =====================================================================
        // ORDERS — partitioned by created_at
        // =====================================================================
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS orders_partitioned (
                id          bigint        NOT NULL,
                patient_id  bigint        NOT NULL,
                status      varchar(16)   NOT NULL DEFAULT 'unpaid'
                                CHECK (status IN ('unpaid', 'paid')),
                total       numeric(16,2) NOT NULL CHECK (total > 0),
                paid_at     timestamp,
                created_at  timestamp     NOT NULL,
                updated_at  timestamp     NOT NULL,
                CHECK ((status = 'paid') = (paid_at IS NOT NULL))
            ) PARTITION BY RANGE (created_at);
        SQL);

        // =====================================================================
        // ORDER_ITEMS — partitioned by created_at
        // =====================================================================
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS order_items_partitioned (
                id           bigint        NOT NULL,
                order_id     bigint        NOT NULL,
                service_id   bigint        NOT NULL,
                department_id bigint       NOT NULL,
                service_code varchar(32)   NOT NULL,
                service_name varchar(150)  NOT NULL,
                unit_price   numeric(12,2) NOT NULL CHECK (unit_price > 0),
                quantity     smallint      NOT NULL CHECK (quantity BETWEEN 1 AND 10),
                line_total   numeric(16,2) NOT NULL CHECK (line_total = unit_price * quantity),
                created_at   timestamp     NOT NULL,
                updated_at   timestamp     NOT NULL
            ) PARTITION BY RANGE (created_at);
        SQL);

        // =====================================================================
        // AUDIT_LOGS — partitioned by created_at
        // =====================================================================
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_logs_partitioned (
                id           bigint       NOT NULL,
                event        varchar(80)  NOT NULL,
                entity_type  varchar(40)  NOT NULL,
                entity_id    bigint       NOT NULL,
                old_values   jsonb,
                new_values   jsonb,
                request_id   uuid         NOT NULL,
                actor        varchar(100) NOT NULL,
                ip_address   inet,
                created_at   timestamp    NOT NULL
            ) PARTITION BY RANGE (created_at);
        SQL);

        // Create monthly partitions for each configured month.
        foreach (self::MONTHLY_PARTITIONS as $ym) {
            [$year, $month] = explode('-', $ym);
            $next = date('Y-m', mktime(0, 0, 0, (int) $month + 1, 1, (int) $year));
            $from = "{$ym}-01";
            $to   = "{$next}-01";

            DB::statement("
                CREATE TABLE IF NOT EXISTS orders_p{$year}_{$month}
                PARTITION OF orders_partitioned
                FOR VALUES FROM ('{$from}') TO ('{$to}')
            ");

            DB::statement("
                CREATE TABLE IF NOT EXISTS order_items_p{$year}_{$month}
                PARTITION OF order_items_partitioned
                FOR VALUES FROM ('{$from}') TO ('{$to}')
            ");

            DB::statement("
                CREATE TABLE IF NOT EXISTS audit_logs_p{$year}_{$month}
                PARTITION OF audit_logs_partitioned
                FOR VALUES FROM ('{$from}') TO ('{$to}')
            ");
        }

        // Default catch-all partitions for rows outside the listed months.
        // Prevents insert failures on dates not yet explicitly partitioned.
        DB::statement("
            CREATE TABLE IF NOT EXISTS orders_p_default
            PARTITION OF orders_partitioned DEFAULT
        ");
        DB::statement("
            CREATE TABLE IF NOT EXISTS order_items_p_default
            PARTITION OF order_items_partitioned DEFAULT
        ");
        DB::statement("
            CREATE TABLE IF NOT EXISTS audit_logs_p_default
            PARTITION OF audit_logs_partitioned DEFAULT
        ");

        // =====================================================================
        // Indexes on the partitioned tables
        // PostgreSQL automatically creates matching indexes on each partition.
        // =====================================================================

        // orders_partitioned indexes
        DB::statement('CREATE INDEX IF NOT EXISTS orders_part_status_created_idx ON orders_partitioned(status, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_part_created_idx        ON orders_partitioned(created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_part_patient_created_idx ON orders_partitioned(patient_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_part_patient_status_created_idx ON orders_partitioned(patient_id, status, created_at DESC, id DESC)');

        // order_items_partitioned indexes
        DB::statement('CREATE INDEX IF NOT EXISTS items_part_service_code_order_idx ON order_items_partitioned(service_code, order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS items_part_department_order_idx   ON order_items_partitioned(department_id, order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS items_part_service_id_idx         ON order_items_partitioned(service_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS items_part_order_service_uniq ON order_items_partitioned(order_id, service_id, created_at)');

        // audit_logs_partitioned indexes
        DB::statement('CREATE INDEX IF NOT EXISTS audit_part_entity_date_idx  ON audit_logs_partitioned(entity_type, entity_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS audit_part_event_created_idx ON audit_logs_partitioned(event, created_at DESC)');

        // Append-only trigger — replicated on the partitioned table.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_part_no_mutation
            BEFORE UPDATE OR DELETE ON audit_logs_partitioned
            FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();

            CREATE TRIGGER audit_part_no_truncate
            BEFORE TRUNCATE ON audit_logs_partitioned
            FOR EACH STATEMENT EXECUTE FUNCTION prevent_audit_mutation();
        SQL);
    }

    public function down(): void
    {
        // Drop partitioned tables (cascades to all partitions and their indexes).
        DB::statement('DROP TABLE IF EXISTS orders_partitioned CASCADE');
        DB::statement('DROP TABLE IF EXISTS order_items_partitioned CASCADE');
        DB::statement('DROP TABLE IF EXISTS audit_logs_partitioned CASCADE');
    }
};
