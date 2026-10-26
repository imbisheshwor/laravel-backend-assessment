<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateDataset extends Command
{
    protected $signature = 'dataset:generate {--patients=500000} {--batch=2000}';
    protected $description = 'Generate 10.5M+ core records using bounded PostgreSQL set-based inserts';

    public function handle(): int
    {
        $patients = filter_var($this->option('patients'), FILTER_VALIDATE_INT);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if (!$patients || $patients < 1 || $patients > 5000000 || !$batch || $batch < 1 || $batch > 10000) {
            $this->error('patients must be 1..5000000 and batch 1..10000.');
            return self::FAILURE;
        }
        $connection = DB::connection();
        if (!(bool) $connection->scalar('SELECT pg_try_advisory_lock(826334721)')) {
            $this->error('Another dataset generator is running.');
            return self::FAILURE;
        }
        try {
            $services = DB::table('services as s')->join('departments as d', 's.department_id', '=', 'd.id')
                ->where('s.is_active', true)->where('d.is_active', true)->orderBy('s.id')->limit(4)->pluck('s.id')->all();
            if (count($services) !== 4) {
                $this->error('Seed or create at least four active services in active departments first.');
                return self::FAILURE;
            }
            $runId = (string) Str::uuid();
            for ($offset = 0; $offset < $patients; $offset += $batch) {
                $size = min($batch, $patients - $offset);
                DB::transaction(function () use ($size, $services, $runId) {
                    DB::statement('CREATE TEMP TABLE batch_patients (id bigint PRIMARY KEY) ON COMMIT DROP');
                    DB::statement('CREATE TEMP TABLE batch_orders (id bigint PRIMARY KEY) ON COMMIT DROP');
                    DB::statement(<<<'SQL'
WITH inserted AS (
 INSERT INTO patients (full_name,date_of_birth,sex,created_at,updated_at)
 SELECT 'Synthetic Patient ' || g::text, DATE '1990-01-01' + (g % 10000)::int,
        CASE WHEN g % 2 = 0 THEN 'female' ELSE 'male' END, now(), now()
 FROM generate_series(1, ?::int) g RETURNING id
) INSERT INTO batch_patients SELECT id FROM inserted
SQL, [$size]);
                    DB::statement('ANALYZE batch_patients');
                    // Shared locks keep each batch's price and department snapshots stable.
                    $active = DB::table('services as s')->join('departments as d', 'd.id', '=', 's.department_id')
                        ->whereIn('s.id', $services)->where('s.is_active', true)->where('d.is_active', true)
                        ->orderBy('s.id')->select('s.id')->lock('FOR SHARE OF s, d')->get();
                    if ($active->count() !== 4) {
                        throw new \RuntimeException('A selected service or department was deactivated. Re-run to append another dataset.');
                    }
                    DB::statement(<<<'SQL'
WITH inserted AS (
 INSERT INTO orders (patient_id,status,total,paid_at,created_at,updated_at)
 SELECT p.id, CASE WHEN n % 2 = 0 THEN 'paid' ELSE 'unpaid' END,
        (SELECT sum(unit_price) FROM services WHERE id IN (?, ?, ?, ?)),
        CASE WHEN n % 2 = 0 THEN TIMESTAMP '2026-01-01' + ((p.id * 7919 + n * 86400) % 15552000) * INTERVAL '1 second' + INTERVAL '1 hour' END,
        TIMESTAMP '2026-01-01' + ((p.id * 7919 + n * 86400) % 15552000) * INTERVAL '1 second', now()
 FROM batch_patients p CROSS JOIN generate_series(1,4) n RETURNING id
) INSERT INTO batch_orders SELECT id FROM inserted
SQL, $services);
                    DB::statement('ANALYZE batch_orders');
                    DB::statement(<<<'SQL'
INSERT INTO order_items (order_id,service_id,department_id,service_code,service_name,unit_price,quantity,line_total,created_at,updated_at)
SELECT o.id,s.id,s.department_id,s.service_code,s.name,s.unit_price,1,s.unit_price,o.created_at,o.updated_at
FROM orders o JOIN batch_orders b ON b.id=o.id CROSS JOIN services s WHERE s.id IN (?, ?, ?, ?)
SQL, $services);
                    DB::statement(<<<'SQL'
INSERT INTO payments (order_id,idempotency_key,amount,status,created_at,updated_at)
SELECT o.id,'synthetic:' || o.id::text,o.total,'succeeded',o.paid_at,o.updated_at
FROM orders o JOIN batch_orders b ON b.id=o.id WHERE o.status='paid'
SQL);
                    DB::statement(<<<'SQL'
INSERT INTO processing_outbox (order_id,processed_at,created_at,updated_at)
SELECT o.id,o.paid_at,o.paid_at,o.updated_at FROM orders o JOIN batch_orders b ON b.id=o.id WHERE o.status='paid'
SQL);
                    DB::statement(<<<'SQL'
INSERT INTO department_order_processings (order_id,department_id,status,created_at,updated_at)
SELECT DISTINCT o.id,i.department_id,'handed_off',o.paid_at,o.updated_at
FROM orders o JOIN batch_orders b ON b.id=o.id JOIN order_items i ON i.order_id=o.id WHERE o.status='paid'
SQL);
                    DB::statement(<<<'SQL'
INSERT INTO audit_logs (event,entity_type,entity_id,old_values,new_values,request_id,actor,created_at)
SELECT 'order.created','order',o.id,NULL,jsonb_build_object('status','unpaid','total',o.total),?::uuid,'dataset-generator',o.created_at
FROM orders o JOIN batch_orders b ON b.id=o.id
SQL, [$runId]);
                    DB::statement(<<<'SQL'
INSERT INTO audit_logs (event,entity_type,entity_id,old_values,new_values,request_id,actor,created_at)
SELECT events.event,'order',o.id,
 CASE WHEN events.event='order.status_changed' THEN '{"status":"unpaid"}'::jsonb END,
 jsonb_build_object('status','paid','amount',o.total),?::uuid,'dataset-generator',o.paid_at
FROM orders o JOIN batch_orders b ON b.id=o.id
CROSS JOIN (VALUES ('payment.attempted'),('payment.succeeded'),('order.status_changed')) AS events(event)
WHERE o.status='paid'
SQL, [$runId]);
                });
                $this->line('Generated '.min($offset + $size, $patients).'/'.$patients.' patients; core rows: '.(min($offset + $size, $patients) * 21));
            }
            foreach (['patients', 'orders', 'order_items', 'payments', 'audit_logs', 'department_order_processings'] as $table) {
                DB::statement('ANALYZE '.$table);
            }
            $this->info('Done. Core records added: '.($patients * 21).'; additional payment, audit, and handoff records also generated.');
            return self::SUCCESS;
        } finally {
            $connection->select('SELECT pg_advisory_unlock(826334721)');
        }
    }
}
