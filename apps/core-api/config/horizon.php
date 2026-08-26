<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon — Laravel-owned Redis jobs only
|--------------------------------------------------------------------------
|
| Named lanes from Phase 00: critical, notifications, files, integrations,
| analytics, reports, backups, and Laravel-side AI orchestration. Python
| workers never consume these queues (ADR 0009).
|
| Horizon's meta connection is the dedicated `queue` Redis role, not cache
| or realtime. Mixing them is how a cache flush wipes in-flight jobs.
|
*/

$lanes = [
    'critical',
    'notifications',
    'files',
    'integrations',
    'analytics',
    'reports',
    'backups',
    'ai-orchestration',
];

$supervisor = static fn (string $queue, int $maxProcesses, int $timeout): array => [
    'connection' => 'redis',
    'queue' => [$queue],
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'maxProcesses' => $maxProcesses,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 128,
    'tries' => 1,
    'timeout' => $timeout,
    'nice' => 0,
];

$defaults = [];
$waits = [];

foreach ($lanes as $lane) {
    $defaults['supervisor-'.$lane] = $supervisor(
        $lane,
        $lane === 'critical' ? 2 : 1,
        $lane === 'files' || $lane === 'reports' || $lane === 'backups' ? 300 : 60,
    );
    $waits['redis:'.$lane] = $lane === 'critical' ? 30 : 60;
}

return [

    'name' => env('HORIZON_NAME', 'clinic-horizon'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    // Dedicated queue Redis. Never the cache or realtime connection.
    'use' => 'queue',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'clinic'), '_').'_horizon:',
    ),

    // Horizon UI is an operations surface. It is not on the public API
    // gateway. Cookie auth exists; dedicated operator capability remains Phase 02.
    'middleware' => ['web'],

    'waits' => $waits,

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],
    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => $defaults,

    'environments' => [
        'production' => [
            'supervisor-critical' => ['maxProcesses' => 8],
            'supervisor-notifications' => ['maxProcesses' => 4],
            'supervisor-files' => ['maxProcesses' => 2],
            'supervisor-integrations' => ['maxProcesses' => 2],
            'supervisor-analytics' => ['maxProcesses' => 2],
            'supervisor-reports' => ['maxProcesses' => 1],
            'supervisor-backups' => ['maxProcesses' => 1],
            'supervisor-ai-orchestration' => ['maxProcesses' => 2],
        ],
        'local' => [
            'supervisor-critical' => ['maxProcesses' => 1],
            'supervisor-notifications' => ['maxProcesses' => 1],
            'supervisor-files' => ['maxProcesses' => 1],
            'supervisor-integrations' => ['maxProcesses' => 1],
            'supervisor-analytics' => ['maxProcesses' => 1],
            'supervisor-reports' => ['maxProcesses' => 1],
            'supervisor-backups' => ['maxProcesses' => 1],
            'supervisor-ai-orchestration' => ['maxProcesses' => 1],
        ],
        'testing' => [
            'supervisor-critical' => ['maxProcesses' => 1],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],

];
