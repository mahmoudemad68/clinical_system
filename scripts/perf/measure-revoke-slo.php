#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Measure HTTP deny latency after logout (authoritative). Socket close is
 * reported only when a Reverb port answers; a publish is not a close.
 */

$base = rtrim((string) (getenv('CLINIC_API_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$slo = (float) (getenv('AUTH_REVOCATION_SLO_SECONDS') ?: 5);
$out = $argv[1] ?? 'docs/evidence/security-review/reverb-revoke-slo-2026-08-26.txt';

function request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name.': '.$value;
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match) === 1) {
        $status = (int) $match[1];
    }

    return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
}

$reverb = getenv('REVERB_HOST') ?: '127.0.0.1';
$reverbPort = getenv('REVERB_PORT') ?: '8081';
$socket = @fsockopen($reverb, (int) $reverbPort, $errno, $errstr, 1);
$reverbReachable = is_resource($socket);
if ($reverbReachable) {
    fclose($socket);
}

$access = (string) (getenv('CLINIC_ACCESS_TOKEN') ?: '');
$mode = 'unauthenticated_probe';
$logoutStatus = 0;
$denyStatus = 0;
$started = hrtime(true);

if ($access !== '') {
    $mode = 'logout_then_me';
    $logout = request('POST', $base.'/api/v1/auth/logout', [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer '.$access,
        'Content-Type' => 'application/json',
    ], '{}');
    $logoutStatus = $logout['status'];
    $me = request('GET', $base.'/api/v1/me', [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer '.$access,
    ]);
    $denyStatus = $me['status'];
} else {
    $me = request('GET', $base.'/api/v1/me', [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer not-a-token',
    ]);
    $denyStatus = $me['status'];
}

$elapsed = (hrtime(true) - $started) / 1e9;
$httpOk = $elapsed < $slo && $denyStatus === 401;

$lines = [
    'reverb_revoke_slo',
    'generated_at='.gmdate('c'),
    'base='.$base,
    'mode='.$mode,
    'logout_status='.$logoutStatus,
    'http_deny_status='.$denyStatus,
    'http_probe_seconds='.sprintf('%.4f', $elapsed),
    'slo_seconds='.$slo,
    'http_deny_within_slo='.($httpOk ? 'yes' : 'no'),
    'reverb_reachable='.($reverbReachable ? 'yes' : 'no'),
    'socket_close_slo='.($reverbReachable ? 'NOT_MEASURED_no_subscribed_client' : 'NOT_MEASURED_reverb_unreachable'),
    'authoritative_control=http_deny',
];

@mkdir(dirname($out), 0777, true);
file_put_contents($out, implode("\n", $lines)."\n");
fwrite(STDOUT, implode("\n", $lines)."\n");

exit($httpOk ? 0 : 1);
