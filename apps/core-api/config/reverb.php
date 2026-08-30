<?php

$reverbServerHost = env('REVERB_SERVER_HOST');
$reverbScheme = env('REVERB_SCHEME');
$reverbAllowedOrigins = env('REVERB_ALLOWED_ORIGINS');
$corsAllowedOrigins = env('CORS_ALLOWED_ORIGINS');
$originsExplicit = (is_string($reverbAllowedOrigins) && $reverbAllowedOrigins !== '')
    || (is_string($corsAllowedOrigins) && $corsAllowedOrigins !== '');
$originsSource = is_string($reverbAllowedOrigins) && $reverbAllowedOrigins !== ''
    ? $reverbAllowedOrigins
    : (is_string($corsAllowedOrigins) && $corsAllowedOrigins !== ''
        ? $corsAllowedOrigins
        : 'http://localhost:5173,http://localhost:3000');
$resolvedScheme = is_string($reverbScheme) && $reverbScheme !== '' ? $reverbScheme : 'https';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            // Local/testing may fall back to 0.0.0.0. Production readiness
            // requires host_explicit so an absent env cannot silently bind.
            'host' => is_string($reverbServerHost) && $reverbServerHost !== '' ? $reverbServerHost : '0.0.0.0',
            'host_explicit' => is_string($reverbServerHost) && $reverbServerHost !== '',
            'port' => env('REVERB_SERVER_PORT', 8081),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_REALTIME_DB', '2'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => $resolvedScheme,
                    'useTLS' => $resolvedScheme === 'https',
                ],
                'scheme_explicit' => is_string($reverbScheme) && $reverbScheme !== '',
                'origins_explicit' => $originsExplicit,
                // Enumerated origins only. A wildcard here is the same defect
                // as wildcard CORS and is rejected by Semgrep.
                'allowed_origins' => array_values(array_unique(array_filter(array_map(
                    static function (string $origin): string {
                        $origin = trim($origin);
                        if ($origin === '') {
                            return '';
                        }

                        // Reverb compares against parse_url(..., PHP_URL_HOST).
                        $host = parse_url($origin, PHP_URL_HOST);

                        return is_string($host) && $host !== '' ? $host : $origin;
                    },
                    explode(',', $originsSource),
                )))),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS', 500),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', ''),
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', true),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
