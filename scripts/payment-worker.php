<?php

// Internal subprocess for concurrent-payments.php. Each process owns its DB connection.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
[$script, $orderId, $key, $barrier, $workerId] = $argv;
Illuminate\Support\Facades\DB::select('SELECT 1');
touch($barrier.'/ready-'.$workerId);
$deadline = microtime(true) + 45;
while (!is_file($barrier.'/release')) {
    if (microtime(true) > $deadline) { fwrite(STDERR, 'Barrier timed out'); exit(1); }
    usleep(1000);
}
$request = Illuminate\Http\Request::create('/api/orders/'.$orderId.'/pay', 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
], '{}');
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
