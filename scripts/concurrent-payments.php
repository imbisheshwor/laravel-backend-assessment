<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use App\Models\Order;

$options = getopt('', ['order:', 'workers::', 'mode::']);
$orderId = filter_var($options['order'] ?? null, FILTER_VALIDATE_INT);
$workers = filter_var($options['workers'] ?? 20, FILTER_VALIDATE_INT);
$mode = $options['mode'] ?? 'same';
if (!$orderId || !$workers || $workers < 2 || $workers > 40 || !in_array($mode, ['same', 'different'], true)) {
    fwrite(STDERR, "Usage: php scripts/concurrent-payments.php --order=ID --workers=20 --mode=same|different\n");
    exit(1);
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$order = Order::find($orderId);
if (!$order || $order->status !== 'unpaid') {
    fwrite(STDERR, "Provide a fresh unpaid order.\n"); exit(1);
}
$barrier = sys_get_temp_dir().'/hmis-concurrency-'.bin2hex(random_bytes(8));
mkdir($barrier, 0700);
$processes = [];
$pipes = [];
$results = [];
$exit = 1;
try {
    $prefix = 'race-'.bin2hex(random_bytes(12));
    for ($i = 0; $i < $workers; $i++) {
        $key = $mode === 'same' ? $prefix : $prefix.'-'.$i;
        $processes[$i] = proc_open([PHP_BINARY, __DIR__.'/payment-worker.php', (string) $orderId, $key, $barrier, (string) $i],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], dirname(__DIR__));
        if (!is_resource($processes[$i])) { throw new RuntimeException('Could not start worker.'); }
        fclose($pipes[$i][0]);
    }
    $deadline = microtime(true) + 40;
    while (count(glob($barrier.'/ready-*')) < $workers) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Workers did not all reach start barrier.'); }
        usleep(10000);
    }
    // Release all workers after their connections are ready.
    touch($barrier.'/release');
    foreach ($processes as $i => $process) {
        stream_set_timeout($pipes[$i][1], 60);
        $output = stream_get_contents($pipes[$i][1]);
        $errors = stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][1]); fclose($pipes[$i][2]);
        $code = proc_close($process); unset($processes[$i]);
        if ($code !== 0) { throw new RuntimeException('Worker failed: '.$errors); }
        $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    $statuses = array_count_values(array_column($results, 'status'));
    $paymentCount = DB::table('payments')->where('order_id', $orderId)->count();
    $expectedOther = $mode === 'same' ? 200 : 409;
    $ids = array_unique(array_filter(array_map(fn ($r) => $r['body']['data']['id'] ?? null, $results)));
    $valid = ($statuses[201] ?? 0) === 1 && ($statuses[$expectedOther] ?? 0) === $workers - 1
        && $paymentCount === 1 && count($ids) === 1
        && DB::table('orders')->where('id', $orderId)->value('status') === 'paid'
        && DB::table('processing_outbox')->where('order_id', $orderId)->count() === 1;
    echo json_encode(['workers' => $workers, 'mode' => $mode, 'statuses' => $statuses, 'payments' => $paymentCount,
        'payment_ids' => array_values($ids), 'passed' => $valid], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    $exit = $valid ? 0 : 1;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
} finally {
    foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    foreach (glob($barrier.'/*') as $file) { unlink($file); }
    rmdir($barrier);
}
exit($exit);
