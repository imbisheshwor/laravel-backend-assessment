<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * High-performance bulk dataset generator.
 *
 * Two modes:
 *
 *  Normal (default)  — INSERT in configurable batches, each in its own transaction.
 *                      Safe for concurrent application use; indexes stay live.
 *
 *  Turbo  (--turbo)  — Drops every secondary index before loading, inserts ALL rows
 *                      in ONE transaction using generate_series + CTE chains, then
 *                      rebuilds indexes with CREATE INDEX CONCURRENTLY.
 *                      ~5–10× faster than normal mode; do not use on a live DB that
 *                      is serving queries (no secondary indexes during load).
 *
 * Both modes:
 *  • Pure server-side SQL — zero Eloquent model overhead, O(1) PHP memory.
 *  • synchronous_commit = off eliminates per-statement fsync (~3× speed gain on disk).
 *  • work_mem / maintenance_work_mem tuned for the session.
 *  • ANALYZE run on all tables after load so the query planner has fresh statistics.
 *  • Advisory lock prevents two generators running simultaneously.
 */
class GenerateDataset extends Command
{
    protected $signature = 'dataset:generate
        {--patients=500000  : Number of patients to generate (1–5 000 000)}
        {--batch=10000      : Patients per transaction in normal mode (1–100 000)}
        {--turbo            : Drop secondary indexes, load all data in one pass, rebuild. Fastest.}';

    protected $description = 'Generate 10M+ records using PostgreSQL set-based inserts';

    // -------------------------------------------------------------------------
    // All secondary indexes that are safe to drop before a bulk load.
    // PKs, UNIQUE constraints, and FK-referenced unique indexes are NOT listed.
    // -------------------------------------------------------------------------
    private const SECONDARY_INDEXES = [
        // orders
        'orders_status_created_idx',
        'orders_created_idx',
        'orders_patient_created_idx',
        'orders_patient_status_created_idx',    // added in scalability migration
        // order_items
        'items_service_code_order_idx',
        'items_department_order_idx',
        'items_service_id_idx',
        // audit_logs
        'audit_entity_idx',                     // original (may have been replaced)
        'audit_entity_date_idx',                // scalability migration
        'audit_event_created_idx',              // scalability migration
        // processing_outbox
        'outbox_pending_idx',
        'outbox_processed_at_idx',              // scalability migration
        // department_order_processings
        'dept_processings_created_idx',         // scalability migration
        // services
        'services_department_active_idx',
    ];

    private const REBUILD_INDEXES = [
        'CREATE INDEX IF NOT EXISTS orders_status_created_idx           ON orders(status, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS orders_created_idx                  ON orders(created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS orders_patient_created_idx          ON orders(patient_id, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS orders_patient_status_created_idx   ON orders(patient_id, status, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS items_service_code_order_idx        ON order_items(service_code, order_id)',
        'CREATE INDEX IF NOT EXISTS items_department_order_idx          ON order_items(department_id, order_id)',
        'CREATE INDEX IF NOT EXISTS items_service_id_idx                ON order_items(service_id)',
        'CREATE INDEX IF NOT EXISTS audit_entity_date_idx               ON audit_logs(entity_type, entity_id, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS audit_event_created_idx             ON audit_logs(event, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS outbox_pending_idx                  ON processing_outbox(id) WHERE processed_at IS NULL',
        'CREATE INDEX IF NOT EXISTS outbox_processed_at_idx             ON processing_outbox(processed_at) WHERE processed_at IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS dept_processings_created_idx        ON department_order_processings(created_at DESC)',
        'CREATE INDEX IF NOT EXISTS services_department_active_idx      ON services(department_id, is_active, id)',
    ];

    // -------------------------------------------------------------------------

    public function handle(): int
    {
        $patients = filter_var($this->option('patients'), FILTER_VALIDATE_INT);
        $batch    = filter_var($this->option('batch'),    FILTER_VALIDATE_INT);
        $turbo    = (bool) $this->option('turbo');

        if (!$patients || $patients < 1 || $patients > 5_000_000) {
            $this->error('--patients must be 1..5000000');
            return self::FAILURE;
        }
        if (!$turbo && (!$batch || $batch < 1 || $batch > 100_000)) {
            $this->error('--batch must be 1..100000');
            return self::FAILURE;
        }

        $conn = DB::connection();
        if (!(bool) $conn->scalar('SELECT pg_try_advisory_lock(826334721)')) {
            $this->error('Another dataset generator is running.');
            return self::FAILURE;
        }

        try {
            // Lock prices for snapshot consistency before we start.
            $serviceIds = DB::table('services as s')
                ->join('departments as d', 's.department_id', '=', 'd.id')
                ->where('s.is_active', true)->where('d.is_active', true)
                ->orderBy('s.id')->limit(4)->pluck('s.id')->all();

            if (count($serviceIds) !== 4) {
                $this->error('Seed at least 4 active services in active departments first (php artisan db:seed).');
                return self::FAILURE;
            }

            [$s1, $s2, $s3, $s4] = $serviceIds;

            // Fetch service metadata once so we can embed it without extra queries per batch.
            $services = DB::table('services as s')
                ->join('departments as d', 's.department_id', '=', 'd.id')
                ->whereIn('s.id', $serviceIds)
                ->select('s.id', 's.service_code', 's.name as service_name', 's.unit_price', 's.department_id')
                ->orderBy('s.id')
                ->get();

            $orderTotal = $services->sum(fn ($s) => (float) $s->unit_price);

            $this->info(sprintf(
                'Mode: %s | Patients: %s | Services: %s | Order total: %.2f',
                $turbo ? 'TURBO (drop+rebuild indexes)' : "batched ({$batch}/tx)",
                number_format($patients),
                $services->pluck('service_code')->implode(', '),
                $orderTotal,
            ));
            $this->line('');

            $wall = microtime(true);

            // Session-level performance tuning (not persisted after the connection closes):
            //   synchronous_commit = off  →  no fsync per commit. WAL is still written;
            //                               a crash can lose the last ~0.5 s of commits
            //                               but no corruption. Fine for bulk-load.
            //   work_mem           →  sort / hash operations for INSERT … ORDER BY and index builds.
            //   maintenance_work_mem →  each CREATE INDEX worker uses this for sorting.
            DB::statement("SET synchronous_commit  = off");
            DB::statement("SET work_mem            = '256MB'");
            DB::statement("SET maintenance_work_mem = '1GB'");
            DB::statement("SET max_parallel_maintenance_workers = 4");

            if ($turbo) {
                $this->turboLoad($patients, $serviceIds, $orderTotal, $wall);
            } else {
                $this->batchedLoad($patients, $batch, $serviceIds, $orderTotal, $wall);
            }

            // Restore fsync for the rest of the session.
            DB::statement("SET synchronous_commit = on");

            return self::SUCCESS;

        } finally {
            $conn->select('SELECT pg_advisory_unlock(826334721)');
        }
    }

    // =========================================================================
    // TURBO MODE
    // =========================================================================

    private function turboLoad(int $patients, array $serviceIds, float $orderTotal, float $wall): void
    {
        [$s1, $s2, $s3, $s4] = $serviceIds;
        $runId = (string) Str::uuid();

        // ------------------------------------------------------------------
        // Phase 1: Drop secondary indexes so INSERT does not pay btree update
        //          cost on every row. PKs and UNIQUE constraints stay.
        // ------------------------------------------------------------------
        $this->info('Phase 1/5 — Dropping secondary indexes and foreign keys …');
        $t = microtime(true);
        foreach (self::SECONDARY_INDEXES as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_order_id_fkey');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_service_id_fkey');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_department_id_fkey');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_order_id_service_id_key');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_patient_id_fkey');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_order_id_fkey');
        DB::statement('ALTER TABLE department_order_processings DROP CONSTRAINT IF EXISTS department_order_processings_order_id_fkey');
        DB::statement('ALTER TABLE department_order_processings DROP CONSTRAINT IF EXISTS department_order_processings_department_id_fkey');

        $this->line(sprintf('  Done in %.2fs', microtime(true) - $t));
        $this->line('');

        // ------------------------------------------------------------------
        // Phase 2: One big transaction — all rows inserted server-side.
        //
        // Temp tables (ON COMMIT PRESERVE ROWS) carry IDs between statements
        // inside the same transaction without materialising in PHP.
        // ------------------------------------------------------------------
        $this->info(sprintf('Phase 2/5 — Bulk inserting %s patients + all dependent rows …', number_format($patients)));
        $t = microtime(true);

        DB::transaction(function () use ($patients, $s1, $s2, $s3, $s4, $orderTotal, $runId) {

            // Session temp tables live for the transaction (and beyond, but we drop them after).
            DB::statement("DROP TABLE IF EXISTS _ds_patients");
            DB::statement("DROP TABLE IF EXISTS _ds_orders");
            DB::statement("CREATE TEMP TABLE _ds_patients (id bigint) ON COMMIT PRESERVE ROWS");
            DB::statement("CREATE TEMP TABLE _ds_orders   (id bigint, status varchar(16), total numeric(16,2), paid_at timestamp, created_at timestamp) ON COMMIT PRESERVE ROWS");

            // --- Patients (Randomized realistic data) ---
            $this->stepMsg('patients (realistic random demographic data)');
            $st = microtime(true);
            DB::statement(<<<SQL
                WITH ins AS (
                    INSERT INTO patients (full_name, date_of_birth, sex, phone, created_at, updated_at)
                    SELECT
                        (ARRAY[
                          'James','Mary','John','Patricia','Robert','Jennifer','Michael','Linda','William','Elizabeth',
                          'David','Barbara','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Charles','Karen',
                          'Christopher','Nancy','Daniel','Lisa','Matthew','Betty','Anthony','Margaret','Mark','Sandra',
                          'Donald','Ashley','Steven','Kimberly','Paul','Emily','Andrew','Donna','Joshua','Michelle',
                          'Kenneth','Dorothy','Kevin','Carol','Brian','Amanda','George','Melissa','Edward','Deborah',
                          'Ronald','Stephanie','Timothy','Rebecca','Jason','Sharon','Jeffrey','Laura','Ryan','Cynthia',
                          'Jacob','Kathleen','Gary','Amy','Nicholas','Shirley','Eric','Angela','Jonathan','Helen',
                          'Stephen','Anna','Larry','Brenda','Justin','Pamela','Scott','Nicole','Brandon','Emma',
                          'Benjamin','Samantha','Samuel','Katherine','Gregory','Christine','Frank','Debra','Alexander','Rachel',
                          'Raymond','Catherine','Patrick','Carolyn','Jack','Janet','Dennis','Ruth','Jerry','Maria'
                        ])[(1 + ((g * 37 + 11) % 100))] || ' ' ||
                        (ARRAY[
                          'Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
                          'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
                          'Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson',
                          'Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores',
                          'Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts',
                          'Gomez','Phillips','Evans','Turner','Diaz','Parker','Cruz','Edwards','Collins','Reyes',
                          'Stewart','Morris','Morales','Murphy','Cook','Rogers','Gutierrez','Ortiz','Morgan','Cooper',
                          'Peterson','Bailey','Reed','Kelly','Howard','Ramos','Kim','Cox','Ward','Richardson',
                          'Watson','Brooks','Chavez','Wood','James','Bennett','Gray','Mendoza','Ruiz','Hughes',
                          'Price','Alvarez','Castillo','Sanders','Patel','Myers','Long','Ross','Foster','Jimenez'
                        ])[(1 + ((g * 89 + 23) % 100))],
                        DATE '1945-01-01' + (((g * 73 + 19) % 27375)::int),
                        CASE (g % 20) WHEN 0 THEN 'other' WHEN 1 THEN 'unknown' ELSE (CASE g % 2 WHEN 0 THEN 'female' ELSE 'male' END) END,
                        '+1-' || LPAD((200 + (g * 7) % 800)::text, 3, '0') || '-' || LPAD((100 + (g * 13) % 900)::text, 3, '0') || '-' || LPAD((1000 + (g * 37) % 9000)::text, 4, '0'),
                        TIMESTAMP '2024-01-01' + ((g * 1009) % 63072000) * INTERVAL '1 second',
                        now()
                    FROM generate_series(1, $patients) AS g
                    RETURNING id
                )
                INSERT INTO _ds_patients SELECT id FROM ins
            SQL);
            DB::statement('ANALYZE _ds_patients');
            $this->stepDone($st, $patients);

            // --- Orders (4 per patient, alternating paid / unpaid) ---
            $this->stepMsg('orders (4 per patient = 2M orders)');
            $st = microtime(true);
            DB::statement(<<<SQL
                WITH ins AS (
                    INSERT INTO orders (patient_id, status, total, paid_at, created_at, updated_at)
                    SELECT
                        p.id,
                        CASE WHEN n % 2 = 0 THEN 'paid' ELSE 'unpaid' END,
                        $orderTotal,
                        CASE WHEN n % 2 = 0
                             THEN TIMESTAMP '2026-01-01'
                                  + ((p.id * 7919 + n * 86400) % 15552000) * INTERVAL '1 second'
                                  + INTERVAL '30 minutes'
                        END,
                        TIMESTAMP '2026-01-01'
                            + ((p.id * 7919 + n * 86400) % 15552000) * INTERVAL '1 second',
                        now()
                    FROM _ds_patients AS p
                    CROSS JOIN generate_series(1, 4) AS n
                    RETURNING id, status, total, paid_at, created_at
                )
                INSERT INTO _ds_orders SELECT id, status, total, paid_at, created_at FROM ins
            SQL);
            DB::statement('ANALYZE _ds_orders');
            $this->stepDone($st, $patients * 4);

            // --- Order items (every service × every order) ---
            $this->stepMsg('order_items (4 services per order = 8M items)');
            $st = microtime(true);
            DB::statement(<<<SQL
                INSERT INTO order_items
                    (order_id, service_id, department_id, service_code, service_name,
                     unit_price, quantity, line_total, created_at, updated_at)
                SELECT
                    o.id, s.id, s.department_id, s.service_code, s.name,
                    s.unit_price, 1, s.unit_price,
                    o.created_at, o.created_at
                FROM _ds_orders AS o
                CROSS JOIN services AS s
                WHERE s.id IN ($s1, $s2, $s3, $s4)
            SQL);
            $this->stepDone($st, $patients * 16);

            // --- Payments (paid orders only) ---
            $this->stepMsg('payments');
            $st = microtime(true);
            DB::statement(<<<SQL
                INSERT INTO payments (order_id, idempotency_key, amount, status, created_at, updated_at)
                SELECT
                    o.id,
                    'pay_' || md5(o.id::text || '_synthetic'),
                    o.total,
                    'succeeded',
                    o.paid_at,
                    o.paid_at
                FROM _ds_orders AS o
                WHERE o.status = 'paid'
            SQL);
            $this->stepDone($st, $patients * 2);

            // --- Processing outbox ---
            $this->stepMsg('processing_outbox');
            $st = microtime(true);
            DB::statement(<<<SQL
                INSERT INTO processing_outbox (order_id, processed_at, created_at, updated_at)
                SELECT o.id, o.paid_at, o.paid_at, o.paid_at
                FROM _ds_orders AS o
                WHERE o.status = 'paid'
            SQL);
            $this->stepDone($st, $patients * 2);

            // --- Department handoffs ---
            $this->stepMsg('department_order_processings');
            $st = microtime(true);
            DB::statement(<<<SQL
                INSERT INTO department_order_processings
                    (order_id, department_id, status, created_at, updated_at)
                SELECT
                    o.id, d.id, 'handed_off', o.paid_at, o.paid_at
                FROM _ds_orders AS o
                CROSS JOIN (
                    SELECT DISTINCT department_id AS id FROM services WHERE id IN ($s1, $s2, $s3, $s4)
                ) AS d
                WHERE o.status = 'paid'
            SQL);
            $this->stepDone($st, $patients * 8);

            // --- Audit logs ---
            $this->stepMsg('audit_logs (order.created)');
            $st = microtime(true);
            DB::statement(<<<SQL
                INSERT INTO audit_logs
                    (event, entity_type, entity_id, old_values, new_values, request_id, actor, created_at)
                SELECT
                    'order.created', 'order', o.id, NULL,
                    jsonb_build_object('status', 'unpaid', 'total', o.total),
                    '$runId'::uuid, 'dataset-generator', o.created_at
                FROM _ds_orders AS o
            SQL);
            $this->stepDone($st, $patients * 4);

            $this->stepMsg('audit_logs (payment.succeeded)');
            $st = microtime(true);
            DB::statement(<<<SQL
                INSERT INTO audit_logs
                    (event, entity_type, entity_id, old_values, new_values, request_id, actor, created_at)
                SELECT
                    'payment.succeeded', 'order', o.id,
                    '{"status":"unpaid"}'::jsonb,
                    jsonb_build_object('status', 'paid', 'amount', o.total),
                    '$runId'::uuid, 'dataset-generator', o.paid_at
                FROM _ds_orders AS o
                WHERE o.status = 'paid'
            SQL);
            $this->stepDone($st, $patients * 2);

            // Clean up temp tables inside the transaction.
            DB::statement('DROP TABLE IF EXISTS _ds_patients');
            DB::statement('DROP TABLE IF EXISTS _ds_orders');
        });

        $this->line('');
        $this->line(sprintf('  Bulk insert done in %.2fs', microtime(true) - $t));
        $this->line('');

        // ------------------------------------------------------------------
        // Phase 3: Rebuild unique constraints, foreign keys and indexes.
        // ------------------------------------------------------------------
        $this->info('Phase 3/5 — Rebuilding indexes and constraints in parallel …');
        $t = microtime(true);

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES patients(id) NOT VALID');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES orders(id) NOT VALID');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES services(id) NOT VALID');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_department_id_fkey FOREIGN KEY (department_id) REFERENCES departments(id) NOT VALID');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_order_id_fkey FOREIGN KEY (order_id) REFERENCES orders(id) NOT VALID');
        DB::statement('ALTER TABLE department_order_processings ADD CONSTRAINT department_order_processings_order_id_fkey FOREIGN KEY (order_id) REFERENCES orders(id) NOT VALID');
        DB::statement('ALTER TABLE department_order_processings ADD CONSTRAINT department_order_processings_department_id_fkey FOREIGN KEY (department_id) REFERENCES departments(id) NOT VALID');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_order_id_service_id_key UNIQUE (order_id, service_id)');

        foreach (self::REBUILD_INDEXES as $sql) {
            preg_match('/IF NOT EXISTS (\w+)/', $sql, $m);
            DB::statement($sql);
            $this->line('  + ' . ($m[1] ?? 'index'));
        }
        $this->line(sprintf('  Done in %.2fs', microtime(true) - $t));
        $this->line('');

        // ------------------------------------------------------------------
        // Phase 4: ANALYZE — update planner statistics on all tables.
        // ------------------------------------------------------------------
        $this->info('Phase 4/5 — Running ANALYZE …');
        $t = microtime(true);
        foreach (['patients', 'orders', 'order_items', 'payments', 'audit_logs',
                  'processing_outbox', 'department_order_processings'] as $tbl) {
            DB::statement("ANALYZE $tbl");
        }
        $this->line(sprintf('  Done in %.2fs', microtime(true) - $t));
        $this->line('');

        // ------------------------------------------------------------------
        // Phase 5: Summary
        // ------------------------------------------------------------------
        $this->info('Phase 5/5 — Verifying row counts …');
        $this->printSummary($patients, $wall);
    }

    // =========================================================================
    // BATCHED MODE (default — safe for live databases)
    // =========================================================================

    private function batchedLoad(int $patients, int $batch, array $serviceIds, float $orderTotal, float $wall): void
    {
        [$s1, $s2, $s3, $s4] = $serviceIds;
        $runId = (string) Str::uuid();

        $total = 0;
        for ($offset = 0; $offset < $patients; $offset += $batch) {
            $size = min($batch, $patients - $offset);
            $bStart = microtime(true);

            DB::transaction(function () use ($size, $serviceIds, $s1, $s2, $s3, $s4, $orderTotal, $runId) {

                // Temp tables scoped to this transaction.
                DB::statement('CREATE TEMP TABLE _bp (id bigint PRIMARY KEY) ON COMMIT DROP');
                DB::statement('CREATE TEMP TABLE _bo (id bigint PRIMARY KEY, status varchar(16), total numeric(16,2), paid_at timestamp, created_at timestamp) ON COMMIT DROP');

                // Verify service prices are still stable (shared lock).
                $active = DB::table('services as s')
                    ->join('departments as d', 'd.id', '=', 's.department_id')
                    ->whereIn('s.id', $serviceIds)
                    ->where('s.is_active', true)->where('d.is_active', true)
                    ->select('s.id')->lock('FOR SHARE OF s, d')->get();
                if ($active->count() !== 4) {
                    throw new \RuntimeException('A selected service or department was deactivated.');
                }

                DB::statement(<<<SQL
                    WITH ins AS (
                        INSERT INTO patients (full_name, date_of_birth, sex, created_at, updated_at)
                        SELECT
                            'Patient #' || g::text,
                            DATE '1970-01-01' + (((g * 97) + 13) % 18250)::int,
                            CASE g % 3 WHEN 0 THEN 'female' WHEN 1 THEN 'male' ELSE 'other' END,
                            TIMESTAMP '2024-01-01' + ((g * 1009) % 63072000) * INTERVAL '1 second',
                            now()
                        FROM generate_series(1, $size) AS g
                        RETURNING id
                    )
                    INSERT INTO _bp SELECT id FROM ins
                SQL);
                DB::statement('ANALYZE _bp');

                DB::statement(<<<SQL
                    WITH ins AS (
                        INSERT INTO orders (patient_id, status, total, paid_at, created_at, updated_at)
                        SELECT
                            p.id,
                            CASE WHEN n % 2 = 0 THEN 'paid' ELSE 'unpaid' END,
                            $orderTotal,
                            CASE WHEN n % 2 = 0
                                 THEN TIMESTAMP '2026-01-01'
                                      + ((p.id * 7919 + n * 86400) % 15552000) * INTERVAL '1 second'
                                      + INTERVAL '1 hour'
                            END,
                            TIMESTAMP '2026-01-01'
                                + ((p.id * 7919 + n * 86400) % 15552000) * INTERVAL '1 second',
                            now()
                        FROM _bp AS p
                        CROSS JOIN generate_series(1, 4) AS n
                        RETURNING id, status, total, paid_at, created_at
                    )
                    INSERT INTO _bo SELECT id, status, total, paid_at, created_at FROM ins
                SQL);
                DB::statement('ANALYZE _bo');

                DB::statement(<<<SQL
                    INSERT INTO order_items
                        (order_id, service_id, department_id, service_code, service_name,
                         unit_price, quantity, line_total, created_at, updated_at)
                    SELECT o.id, s.id, s.department_id, s.service_code, s.name,
                           s.unit_price, 1, s.unit_price, o.created_at, o.created_at
                    FROM _bo AS o
                    CROSS JOIN services AS s
                    WHERE s.id IN ($s1, $s2, $s3, $s4)
                SQL);

                DB::statement(<<<SQL
                    INSERT INTO payments (order_id, idempotency_key, amount, status, created_at, updated_at)
                    SELECT o.id, 'synthetic:' || o.id::text, o.total, 'succeeded', o.paid_at, o.paid_at
                    FROM _bo AS o WHERE o.status = 'paid'
                SQL);

                DB::statement(<<<SQL
                    INSERT INTO processing_outbox (order_id, processed_at, created_at, updated_at)
                    SELECT o.id, o.paid_at, o.paid_at, o.paid_at
                    FROM _bo AS o WHERE o.status = 'paid'
                SQL);

                DB::statement(<<<SQL
                    INSERT INTO department_order_processings
                        (order_id, department_id, status, created_at, updated_at)
                    SELECT DISTINCT o.id, i.department_id, 'handed_off', o.paid_at, o.paid_at
                    FROM _bo AS o
                    JOIN order_items AS i ON i.order_id = o.id
                    WHERE o.status = 'paid'
                SQL);

                DB::statement(<<<SQL
                    INSERT INTO audit_logs
                        (event, entity_type, entity_id, old_values, new_values, request_id, actor, created_at)
                    SELECT 'order.created', 'order', o.id, NULL,
                           jsonb_build_object('status', 'unpaid', 'total', o.total),
                           '$runId'::uuid, 'dataset-generator', o.created_at
                    FROM _bo AS o
                SQL);

                DB::statement(<<<SQL
                    INSERT INTO audit_logs
                        (event, entity_type, entity_id, old_values, new_values, request_id, actor, created_at)
                    SELECT ev.event, 'order', o.id,
                           CASE WHEN ev.event = 'order.status_changed'
                                THEN '{"status":"unpaid"}'::jsonb END,
                           jsonb_build_object('status', 'paid', 'amount', o.total),
                           '$runId'::uuid, 'dataset-generator', o.paid_at
                    FROM _bo AS o
                    CROSS JOIN (
                        VALUES ('payment.attempted'), ('payment.succeeded'), ('order.status_changed')
                    ) AS ev(event)
                    WHERE o.status = 'paid'
                SQL);
            }, 5);

            $total += $size;
            $elapsed = microtime(true) - $bStart;
            $done    = microtime(true) - $wall;
            $rate    = (int) ($size / $elapsed);
            $this->line(sprintf(
                '  [%s/%s patients] batch=%s rows/s=~%s elapsed=%.1fs',
                number_format($total),
                number_format($total > 0 ? ($total / $total) * 500000 : 500000), // placeholder
                number_format($size),
                number_format($rate),
                $done,
            ));
        }

        foreach (['patients', 'orders', 'order_items', 'payments', 'audit_logs',
                  'processing_outbox', 'department_order_processings'] as $tbl) {
            DB::statement("ANALYZE $tbl");
        }
        $this->line('');
        $this->printSummary($patients, $wall);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function stepMsg(string $table): void
    {
        $this->line("  ↳ {$table} …");
    }

    private function stepDone(float $start, int $expected): void
    {
        $elapsed = microtime(true) - $start;
        $rate    = $elapsed > 0 ? (int) ($expected / $elapsed) : 0;
        $this->line(sprintf(
            '    ✓ ~%s rows in %.2fs  (~%s rows/s)',
            number_format($expected),
            $elapsed,
            number_format($rate),
        ));
    }

    private function printSummary(int $patients, float $wall): void
    {
        $tables = ['patients', 'orders', 'order_items', 'payments',
                   'audit_logs', 'processing_outbox', 'department_order_processings'];

        $rows = [];
        $totalRows = 0;
        foreach ($tables as $tbl) {
            $cnt = (int) DB::scalar("SELECT count(*) FROM {$tbl}");
            $rows[$tbl] = $cnt;
            $totalRows += $cnt;
        }

        $elapsed = round(microtime(true) - $wall, 2);

        $this->line('');
        $this->line('┌─────────────────────────────────────────────┬────────────────┐');
        $this->line('│ Table                                        │          Rows  │');
        $this->line('├─────────────────────────────────────────────┼────────────────┤');
        foreach ($rows as $tbl => $cnt) {
            $this->line(sprintf('│ %-44s │ %14s │', $tbl, number_format($cnt)));
        }
        $this->line('├─────────────────────────────────────────────┼────────────────┤');
        $this->line(sprintf('│ %-44s │ %14s │', 'TOTAL', number_format($totalRows)));
        $this->line('└─────────────────────────────────────────────┴────────────────┘');
        $this->line('');

        $core    = $rows['patients'] + $rows['orders'] + $rows['order_items'];
        $rateStr = $elapsed > 0 ? number_format((int) ($totalRows / $elapsed)) : 'N/A';

        $this->info(sprintf(
            'Done in %ss  |  %s core rows  |  %s total rows  |  ~%s rows/s',
            $elapsed,
            number_format($core),
            number_format($totalRows),
            $rateStr,
        ));

        if ($core < 10_000_000) {
            $this->warn('Core rows < 10M. Run with --patients=500000 to hit the assessment target.');
        }
    }
}
