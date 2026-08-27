<?php

use App\Modules\Platform\Infrastructure\Telemetry\SentryBeforeSend;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
    'environment' => env('SENTRY_ENVIRONMENT'),
    'send_default_pii' => false,
    'before_send' => [SentryBeforeSend::class, 'filter'],
    'ignore_transactions' => [
        '/live',
        '/ready',
        '/up',
    ],
];
