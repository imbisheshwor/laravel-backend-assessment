<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => ['pgsql' => [
        'driver' => 'pgsql', 'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'hmis'), 'username' => env('DB_USERNAME', 'hmis'),
        'password' => env('DB_PASSWORD', ''), 'charset' => 'utf8', 'prefix' => '',
        'prefix_indexes' => true, 'search_path' => 'public', 'sslmode' => env('DB_SSLMODE', 'prefer'),
    ]],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
