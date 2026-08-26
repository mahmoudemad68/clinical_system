<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS — enumerated origins only
|--------------------------------------------------------------------------
|
| Wildcard production CORS origins are a mandatory Phase 00 prohibition.
| An empty list fails closed: the browser gets no Access-Control-Allow-Origin
| rather than "*". Desktop clients do not use CORS; this exists for the
| admin web origin and local Vite.
|
*/

$origins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Request-Id',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'traceparent',
        'tracestate',
    ],

    'exposed_headers' => [
        'X-Request-Id',
        'Idempotent-Replay',
        'Retry-After',
        'traceresponse',
    ],

    'max_age' => 600,

    'supports_credentials' => true,

];
