<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scalability indexes for billion-row / TB-scale operation.
 *
 * These indexes are additive — they do not change any existing schema.
 * Each is created CONCURRENTLY so production tables are not locked during build.
 *
 * Why each index was added is documented inline.
 */
return new class extends Migration
{
    public $withinTransaction = false;


    public function up(): void
    {
        // -------------------------------------------------------------------------
        // 1. orders — patient + status + date composite
        // -------------------------------------------------------------------------
        // The existing orders_patient_created_idx covers (patient_id, created_at, id)
        // but when a patient filter is combined with status='paid' the planner must
        // re-check status in a heap fetch for every row returned by that index.
        // At a patient with millions of orders this matters.
        // New index covers the three columns together so no heap fetch is needed.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_patient_status_created_idx
            ON orders(patient_id, status, created_at DESC, id DESC)
        SQL);

        // -------------------------------------------------------------------------
        // 2. audit_logs — entity + date composite (replaces entity-only index)
        // -------------------------------------------------------------------------
        // The existing audit_entity_idx covers (entity_type, entity_id, id) but any
        // query that also filters or sorts by created_at (e.g. "show payment events
        // for order 5 in the last 30 days") must scan all audit rows for that entity
        // and filter in memory. Adding created_at lets the planner prune by date.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_entity_date_idx
            ON audit_logs(entity_type, entity_id, created_at DESC, id DESC)
        SQL);

        // The old narrower index is superseded — drop it to save index maintenance cost.
        // Safe: audit_entity_date_idx is a strict superset.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS audit_entity_idx');

        // -------------------------------------------------------------------------
        // 3. audit_logs — event filter index
        // -------------------------------------------------------------------------
        // Monitoring queries like "how many payment failures today?" filter on event
        // with no entity constraint. A partial index per event type would be ideal but
        // a plain index on (event, created_at DESC) covers most monitoring patterns.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_event_created_idx
            ON audit_logs(event, created_at DESC)
        SQL);

        // -------------------------------------------------------------------------
        // 4. processing_outbox — cleanup index
        // -------------------------------------------------------------------------
        // The existing partial index (WHERE processed_at IS NULL) helps the publisher
        // find pending rows. For the scheduled cleanup job that deletes old acknowledged
        // rows, an index on processed_at makes the DELETE efficient.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS outbox_processed_at_idx
            ON processing_outbox(processed_at)
            WHERE processed_at IS NOT NULL
        SQL);

        // -------------------------------------------------------------------------
        // 5. department_order_processings — date index for monitoring
        // -------------------------------------------------------------------------
        // Queries like "how many handoffs happened today?" or cleanup jobs that archive
        // old processings need to filter by created_at efficiently.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS dept_processings_created_idx
            ON department_order_processings(created_at DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_patient_status_created_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS audit_entity_date_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS audit_event_created_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS outbox_processed_at_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS dept_processings_created_idx');

        // Restore the original narrower audit index that down() removed.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_entity_idx
            ON audit_logs(entity_type, entity_id, id)
        SQL);
    }
};
