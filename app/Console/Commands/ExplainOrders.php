<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExplainOrders extends Command
{
    protected $signature = 'orders:explain {--output=docs/benchmarks/orders-query-plan.txt} {--require-large : Require at least ten million core records}';
    protected $description = 'Run EXPLAIN ANALYZE for the order search query';

    public function handle(): int
    {
        $serviceCode = DB::table('services')->orderBy('id')->value('service_code');
        if (!$serviceCode) {
            $this->error('Seed services first.');
            return self::FAILURE;
        }
        $query = <<<'SQL'
SELECT o.* FROM orders o
WHERE o.status = 'paid' AND o.created_at >= '2026-01-01' AND o.created_at < '2026-02-01'
AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.service_code = ?)
ORDER BY o.created_at DESC, o.id DESC LIMIT 21
SQL;
        $lines = ['Captured at: '.now()->toIso8601String(), 'PostgreSQL: '.DB::scalar('SHOW server_version'), 'service_code: '.$serviceCode];
        $counts = [];
        foreach (['patients', 'orders', 'order_items', 'payments', 'audit_logs', 'department_order_processings'] as $table) {
            $counts[$table] = DB::table($table)->count();
            $lines[] = $table.': '.$counts[$table];
        }
        $coreCount = $counts['patients'] + $counts['orders'] + $counts['order_items'];
        if ($this->option('require-large') && $coreCount < 10000000) {
            $this->error('At least 10M core records are required. Current count: '.$coreCount);
            return self::FAILURE;
        }
        $lines[] = $query;
        $plan = [];
        foreach (DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$query, [$serviceCode]) as $row) {
            $plan[] = $row->{'QUERY PLAN'};
        }
        $lines = array_merge($lines, $plan);
        $text = implode(PHP_EOL, $lines).PHP_EOL;
        $path = base_path($this->option('output'));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $text);
        // Refresh the README benchmark alongside the saved query plan.
        $planText = implode(PHP_EOL, $plan);
        preg_match('/Planning Time: ([0-9.]+) ms/', $planText, $planning);
        preg_match('/Execution Time: ([0-9.]+) ms/', $planText, $execution);
        preg_match_all('/(?:Index(?: Only)? Scan|Bitmap Index Scan).*?using ([a-zA-Z0-9_]+)/', $planText, $indexes);
        $observation = 'Dataset: '.number_format($coreCount).' core rows. Planning: '.($planning[1] ?? 'unknown').' ms. Execution: '.($execution[1] ?? 'unknown').' ms. ';
        $observation .= empty($indexes[1]) ? 'No index scan was used.'
            : 'Indexes: `'.implode('`, `', array_unique($indexes[1])).'`.';
        $observation .= str_contains($planText, 'Sort  (') ? ' The plan includes a sort.' : ' No sort was needed.';
        if ($coreCount < 10000000) {
            $observation = 'Small dataset run. '.$observation;
        }
        $readmePath = base_path('README.md');
        if (is_file($readmePath)) {
            $section = "<!-- benchmark:start -->\n".$observation."\n\n```text\n".$planText."\n```\n<!-- benchmark:end -->";
            $readme = file_get_contents($readmePath);
            file_put_contents($readmePath, preg_replace_callback('/<!-- benchmark:start -->.*?<!-- benchmark:end -->/s', fn () => $section, $readme));
        }
        $this->line($text);
        $this->info('Query plan saved to '.$path);
        return self::SUCCESS;
    }
}
