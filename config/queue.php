<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 120, 'after_commit' => true],
    ],
    'failed' => ['driver' => 'database-uuids', 'database' => 'pgsql', 'table' => 'failed_jobs'],
];
