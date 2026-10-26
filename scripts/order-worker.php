<?php

// Integration-test subprocess: create an order on an independently named DB session.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (count($argv) !== 3) {
    fwrite(STDERR, "Usage: php scripts/order-worker.php JSON_BODY APPLICATION_NAME\n");
    exit(1);
}
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[2]]);
$request = Illuminate\Http\Request::create('/api/orders', 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
], $argv[1]);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
