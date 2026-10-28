<?php

$development = [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue' => ['critical', 'default', 'carrier', 'carrier-events', 'shipments', 'bulk', 'tracking', 'customs'],
        'balance' => false,
        'maxProcesses' => 2,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 128,
        'tries' => 1,
        'timeout' => 60,
        'nice' => 0,
    ],
];

return [
    // Do not register a dashboard until privileged authentication is implemented.
    'domain' => null,
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => 'clicktoship_horizon:',
    'middleware' => ['web'],
    'waits' => ['redis:critical' => 60],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 128,
    'defaults' => [],
    'environments' => [
        'local' => $development,
        'testing' => $development,
        // Separate containers reserve capacity. Never run both roles in one container.
        'staging' => env('HORIZON_ROLE') === 'critical' ? [
            'critical' => ['connection' => 'redis', 'queue' => ['critical', 'carrier', 'carrier-events', 'shipments'], 'balance' => false, 'maxProcesses' => 1, 'memory' => 192, 'tries' => 1, 'timeout' => 60, 'nice' => 0],
        ] : (env('HORIZON_ROLE') === 'background' ? [
            'background' => ['connection' => 'redis', 'queue' => ['default', 'bulk', 'tracking', 'customs'], 'balance' => false, 'maxProcesses' => 1, 'memory' => 192, 'tries' => 1, 'timeout' => 60, 'nice' => 5],
        ] : []),
        // Separate demo policy; staging configuration above is unchanged.
        'demo' => env('HORIZON_ROLE') === 'critical' ? [
            'critical' => ['connection' => 'redis', 'queue' => ['critical', 'carrier', 'carrier-events', 'shipments'], 'balance' => false, 'maxProcesses' => 1, 'memory' => 192, 'tries' => 1, 'timeout' => 60, 'nice' => 0],
        ] : (env('HORIZON_ROLE') === 'background' ? [
            'background' => ['connection' => 'redis', 'queue' => ['default', 'bulk', 'tracking', 'customs'], 'balance' => false, 'maxProcesses' => 1, 'memory' => 192, 'tries' => 1, 'timeout' => 60, 'nice' => 5],
        ] : []),
    ],
];
