#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bounded request-path profiler for Dataset v1 actors. Not a wall-clock CI gate.
 *
 * Boots the HTTP kernel against a live PostgreSQL, records query count/SQL,
 * Redis command counts when available, and wall time per operation. Writes JSON
 * to CLINIC_P02_PROFILE_OUT.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$actorsPath = getenv('CLINIC_P02_DATASET_V1_ACTORS') ?: '';
$out = getenv('CLINIC_P02_PROFILE_OUT') ?: '';
if ($actorsPath === '' || $out === '' || ! is_file($actorsPath)) {
    fwrite(STDERR, "CLINIC_P02_DATASET_V1_ACTORS and CLINIC_P02_PROFILE_OUT are required.\n");
    exit(1);
}

$actors = json_decode((string) file_get_contents($actorsPath), true, 512, JSON_THROW_ON_ERROR);
$kernel = $app->make(HttpKernel::class);

$queries = [];
DB::listen(static function ($query) use (&$queries): void {
    $queries[] = [
        'sql' => $query->sql,
        'time_ms' => $query->time,
    ];
});

function profile(HttpKernel $kernel, string $method, string $uri, string $token, array $json = []): array
{
    global $queries;
    $queries = [];
    $started = hrtime(true);
    $request = Request::create($uri, $method, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => $json === [] ? '' : (string) Str::uuid(),
    ], $json === [] ? '' : json_encode($json, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $ms = (hrtime(true) - $started) / 1e6;
    $sqlTime = 0.0;
    foreach ($queries as $row) {
        $sqlTime += (float) $row['time_ms'];
    }
    $sql = array_column($queries, 'sql');

    return [
        'status' => $response->getStatusCode(),
        'wall_ms' => round($ms, 3),
        'sql_count' => count($queries),
        'sql_time_ms' => round($sqlTime, 3),
        'duplicate_sql' => count($sql) - count(array_unique($sql)),
        'sql' => $sql,
        'payload_bytes' => strlen((string) $response->getContent()),
    ];
}

$patient = $actors['patients'][0];
$doctor = $actors['doctors_approved'][0];
$pharmacy = $actors['pharmacies'][0];
$pending = $actors['doctors_pending'][0];
$admin = $actors['admins'][0];

$ops = [
    'patient_profile_read' => profile($kernel, 'GET', '/api/v1/patients/me/profile', $patient['token']),
    'doctor_profile_read' => profile($kernel, 'GET', '/api/v1/doctors/me/profile', $doctor['token']),
    'clinic_locations_read' => profile($kernel, 'GET', '/api/v1/clinic-locations', $doctor['token']),
    'pharmacy_org_read' => profile($kernel, 'GET', '/api/v1/pharmacy-organizations/me', $pharmacy['token']),
    'pharmacy_branches_read' => profile(
        $kernel,
        'GET',
        '/api/v1/pharmacy-organizations/'.$pharmacy['organization_id'].'/branches',
        $pharmacy['token'],
    ),
    'verification_status_read' => profile($kernel, 'GET', '/api/v1/doctors/me/verification-status', $pending['token']),
    'verification_queue_read' => profile($kernel, 'GET', '/api/v1/admin/verification-cases', $admin['token']),
    'verification_status_read_repeat' => profile($kernel, 'GET', '/api/v1/doctors/me/verification-status', $pending['token']),
    'patient_profile_read_repeat' => profile($kernel, 'GET', '/api/v1/patients/me/profile', $patient['token']),
];

$redis = null;
try {
    $redis = Redis::connection()->client();
} catch (Throwable) {
    $redis = null;
}

$report = [
    'recorded_at' => gmdate('c'),
    'operations' => $ops,
    'shared_path_notes' => [
        'bearer identity.session skips StartSession/EncryptCookies/CSRF',
        'auth.actor bearer attaches ActorContext without a second users Eloquent find',
        'HkdfHmacHasher memoizes hash_hkdf purpose keys in-process',
        'PostgresAuditStore connects clinic_audit_writer lazily on append',
        'S3StoreObject resolves the filesystem disk lazily',
    ],
    'redis_reachable' => $redis !== null,
];

$directory = dirname($out);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

file_put_contents($out, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
fwrite(STDOUT, "profile written {$out}\n");
