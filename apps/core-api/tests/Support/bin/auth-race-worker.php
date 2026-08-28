<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($payload)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'invalid_payload', 'status' => 0]));
    exit(1);
}

$ready = (string) ($payload['ready_path'] ?? '');
$go = (string) ($payload['go_path'] ?? '');
if ($ready === '' || $go === '') {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'missing_barrier', 'status' => 0]));
    exit(1);
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$console = $app->make(Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

DB::statement("SET lock_timeout = '8s'");
DB::statement("SET statement_timeout = '15s'");

file_put_contents($ready, '1');
$deadline = microtime(true) + 10;
while (! is_file($go)) {
    if (microtime(true) > $deadline) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'barrier_timeout', 'status' => 0]));
        exit(2);
    }
    usleep(500);
}

$op = (string) ($payload['op'] ?? '');
$method = 'POST';
$uri = '';
$headers = [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
];
$body = [];

if ($op === 'refresh') {
    $uri = '/api/v1/auth/token/refresh';
    $body = ['refresh_token' => (string) ($payload['refresh_token'] ?? '')];
    $headers['HTTP_IDEMPOTENCY_KEY'] = (string) ($payload['idempotency_key'] ?? '');
} elseif ($op === 'logout') {
    $uri = '/api/v1/auth/logout';
    $headers['HTTP_AUTHORIZATION'] = 'Bearer '.(string) ($payload['access_token'] ?? '');
} elseif ($op === 'otp_verify') {
    $uri = '/api/v1/auth/otp-verifications';
    $body = [
        'challenge_id' => (string) ($payload['challenge_id'] ?? ''),
        'code' => (string) ($payload['code'] ?? ''),
        'client_class' => (string) ($payload['client_class'] ?? 'patient_mobile'),
        'platform' => (string) ($payload['platform'] ?? 'android'),
        'device_label' => (string) ($payload['device_label'] ?? 'phone'),
    ];
    $headers['HTTP_IDEMPOTENCY_KEY'] = (string) ($payload['idempotency_key'] ?? '');
} elseif ($op === 'recovery_complete') {
    $uri = '/api/v1/auth/recovery/complete';
    $body = [
        'challenge_id' => (string) ($payload['challenge_id'] ?? ''),
        'code' => (string) ($payload['code'] ?? ''),
        'password' => (string) ($payload['password'] ?? ''),
    ];
    $headers['HTTP_IDEMPOTENCY_KEY'] = (string) ($payload['idempotency_key'] ?? '');
} elseif ($op === 'mfa_verify') {
    $uri = '/api/v1/auth/mfa/challenges/'.rawurlencode((string) ($payload['challenge_id'] ?? '')).'/verify';
    if (isset($payload['recovery_code'])) {
        $body['recovery_code'] = (string) $payload['recovery_code'];
    }
    if (isset($payload['code'])) {
        $body['code'] = (string) $payload['code'];
    }
} else {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'unknown_op', 'status' => 0]));
    exit(1);
}

$started = hrtime(true);
$sqlstate = null;
$error = null;
$status = 0;
$json = null;
$content = $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR);

try {
    $kernel = $app->make(Kernel::class);
    $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        $headers,
        $content,
    );
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $decoded = json_decode($response->getContent(), true);
    $json = is_array($decoded) ? $decoded : null;
    $kernel->terminate($request, $response);
} catch (Throwable $e) {
    $error = $e::class;
    $cursor = $e;
    while ($cursor instanceof Throwable) {
        if ($cursor instanceof PDOException) {
            $sqlstate = (string) ($cursor->errorInfo[0] ?? $cursor->getCode());
            break;
        }
        if (is_object($cursor) && property_exists($cursor, 'errorInfo') && is_array($cursor->errorInfo)) {
            $sqlstate = (string) ($cursor->errorInfo[0] ?? '');
            if ($sqlstate !== '') {
                break;
            }
        }
        $cursor = $cursor->getPrevious();
        if (! $cursor instanceof Throwable) {
            break;
        }
    }
}

$elapsed = (hrtime(true) - $started) / 1e6;
$code = is_array($json) ? ($json['error']['code'] ?? $json['errors'][0]['code'] ?? null) : null;

fwrite(STDOUT, json_encode([
    'ok' => $error === null && $status > 0,
    'status' => $status,
    'error' => $error,
    'sqlstate' => $sqlstate,
    'error_code' => $code,
    'elapsed_ms' => round($elapsed, 3),
    'has_access_token' => is_array($json) && isset($json['data']['access_token']),
    'has_refresh_token' => is_array($json) && isset($json['data']['refresh_token']),
    'session_id' => is_array($json) ? ($json['data']['session_id'] ?? null) : null,
    'recovery_status' => is_array($json) ? ($json['data']['status'] ?? null) : null,
], JSON_THROW_ON_ERROR));
