<?php

use App\Http\Middleware\PrivateResponse;
use Illuminate\Support\Str;

return [
    'name' => env('HORIZON_NAME', 'Kadrelio'),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'kadrelio'), '_').'_horizon:'),
    'middleware' => ['web', 'auth', PrivateResponse::class],
    'waits' => ['redis:default' => 60],
    'trim' => [
        'recent' => 1440,
        'pending' => 1440,
        'completed' => 1440,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'metrics' => [
        'trim_snapshots' => ['job' => 288, 'queue' => 288],
    ],
    'fast_termination' => false,
    'memory_limit' => 64,
    'defaults' => [
        'mail' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 80,
            'backoff' => [10, 60],
            'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => ['mail' => []],
        'local' => ['mail' => []],
    ],
];
