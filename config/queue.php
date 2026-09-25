<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    |
    | The connection used when a job does not ask for one. Choose one of the
    | connections defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "database", "redis", "memory".
    |
    | "lease" is how many seconds a worker may hold a job before it is handed
    | to another worker, which is how jobs of crashed workers are recovered.
    | Set it above the longest time a job needs; jobs with a timeout renew it
    | automatically while they run.
    |
    | Register your own driver with Queue::extend('name', fn ($config, $clock) => ...).
    |
    */

    'connections' => [

        'database' => [
            'driver' => 'database',
            // Database connection to use; null uses the default connection.
            'connection' => null,
            'table' => 'queue_jobs',
            'failed_table' => 'failed_jobs',
            'lease' => 90,
        ],

        'redis' => [
            'driver' => 'redis',
            // Requires predis/predis. Same shape as the "redis" cache store.
            'connection' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'options' => [
                'parameters' => [
                    'password' => env('REDIS_PASSWORD', null),
                    'database' => env('REDIS_DB', 0),
                ],
            ],
            // The braces are a Redis Cluster hash tag; keep them.
            'prefix' => '{doppar_queue}',
            'lease' => 90,
        ],

        // Keeps jobs in memory for the current process only. For tests.
        'memory' => [
            'driver' => 'memory',
            'lease' => 90,
        ],

    ],

];
