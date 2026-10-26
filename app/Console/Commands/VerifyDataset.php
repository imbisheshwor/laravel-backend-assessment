<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyDataset extends Command
{
    protected $signature = 'dataset:verify {--require-large} {--output=docs/benchmarks/dataset-integrity.json} {--days= : Scope integrity checks to orders created within this many days (recommended at billions of rows)}';
    protected $description = 'Check row counts and billing, payment, audit, and handoff consistency';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        // At billions of rows, running integrity checks across all time is impractical.
        // Use --days=7 to scope checks to a recent window. Full counts are still reported.
        $since = $days !== null ? now()->subDays($days)->toDateTimeString() : null;
        $orderFilter       = $since ? "WHERE o.created_at >= '$since'" : '';
        $orderFilterAnd    = $since ? "AND o.created_at >= '$since'" : '';
        $auditOrderFilter  = $since ? "AND o.created_at >= '$since'" : '';

        if ($since) {
            $this->line("Scoping integrity checks to orders created since {$since} (--days={$days}).");
            $this->line("Run without --days to check all rows (slow at billions of records).");
        }

        $result = DB::transaction(function () use ($orderFilter, $orderFilterAnd, $auditOrderFilter, $since, $days) {
            // Every check sees the same committed database state.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $counts = [];
            foreach (['departments', 'services', 'patients', 'orders', 'order_items', 'payments', 'audit_logs', 'processing_outbox', 'department_order_processings'] as $table) {
                $counts[$table] = DB::table($table)->count();
            }
            $queries = [
                'orders_missing_items_or_wrong_totals' => <<<SQL
SELECT count(*) FROM orders o LEFT JOIN (
 SELECT order_id, sum(line_total) AS total FROM order_items GROUP BY order_id
) i ON i.order_id=o.id WHERE (i.order_id IS NULL OR o.total<>i.total) {$orderFilterAnd}
SQL,
                'invalid_payment_state_or_amount' => <<<SQL
SELECT count(*) FROM orders o LEFT JOIN payments p ON p.order_id=o.id
WHERE ((o.status='paid' AND (p.id IS NULL OR p.amount<>o.total))
   OR (o.status='unpaid' AND p.id IS NOT NULL)) {$orderFilterAnd}
SQL,
                'invalid_outbox_state' => <<<SQL
SELECT count(*) FROM orders o LEFT JOIN processing_outbox b ON b.order_id=o.id
WHERE ((o.status='paid' AND b.id IS NULL) OR (o.status='unpaid' AND b.id IS NOT NULL)) {$orderFilterAnd}
SQL,
                'unexpected_department_handoffs' => <<<SQL
SELECT count(*) FROM department_order_processings p JOIN orders o ON o.id=p.order_id
WHERE (o.status<>'paid' OR NOT EXISTS (
 SELECT 1 FROM order_items i WHERE i.order_id=p.order_id AND i.department_id=p.department_id
)) {$orderFilterAnd}
SQL,
                'acknowledged_handoffs_missing' => <<<SQL
SELECT count(*) FROM (
 SELECT DISTINCT i.order_id,i.department_id FROM order_items i
 JOIN processing_outbox b ON b.order_id=i.order_id WHERE b.processed_at IS NOT NULL
 EXCEPT SELECT order_id,department_id FROM department_order_processings
) missing
SQL,
                'orders_missing_creation_audit' => <<<SQL
SELECT count(*) FROM orders o WHERE NOT EXISTS (
 SELECT 1 FROM audit_logs a WHERE a.entity_type='order' AND a.entity_id=o.id AND a.event='order.created'
) {$auditOrderFilter}
SQL,
                'paid_orders_missing_success_audit' => <<<SQL
SELECT count(*) FROM orders o WHERE o.status='paid' AND NOT EXISTS (
 SELECT 1 FROM audit_logs a WHERE a.entity_type='order' AND a.entity_id=o.id AND a.event='payment.succeeded'
) {$auditOrderFilter}
SQL,
            ];
            $violations = [];
            foreach ($queries as $name => $sql) {
                $violations[$name] = (int) DB::scalar($sql);
            }
            $core = $counts['patients'] + $counts['orders'] + $counts['order_items'];
            return [
                'captured_at' => now()->toIso8601String(), 'postgresql' => DB::scalar('SHOW server_version'),
                'database' => DB::connection()->getDatabaseName(), 'counts' => $counts,
                'core_records' => $core, 'counted_records' => array_sum($counts),
                'large_dataset' => $core >= 10000000,
                'scoped_to_days' => $days,
                'violations' => $violations,
                'passed' => array_sum($violations) === 0 && (!$this->option('require-large') || $core >= 10000000),
            ];
        });
        $path = base_path($this->option('output'));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($path, $json);
        $this->line($json);
        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
