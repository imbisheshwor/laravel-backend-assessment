<?php

return ['default' => env('LOG_CHANNEL', 'stderr'), 'channels' => [
    'stderr' => ['driver' => 'monolog', 'level' => env('LOG_LEVEL', 'info'), 'handler' => Monolog\Handler\StreamHandler::class, 'with' => ['stream' => 'php://stderr']],
]];
