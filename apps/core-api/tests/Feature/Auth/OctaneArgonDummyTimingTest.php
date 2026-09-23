<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

/**
 * Live FrankenPHP/Octane timing proof for DEF-C17-ARGON-001.
 * Talks only to CLINIC_OCTANE_ARGON_TIMING_BASE_URL over real HTTP.
 */
function octaneArgonTimingEnabled(): bool
{
    return getenv('CLINIC_OCTANE_ARGON_TIMING') === '1';
}

function octaneArgonTimingBase(): string
{
    return rtrim((string) getenv('CLINIC_OCTANE_ARGON_TIMING_BASE_URL'), '/');
}

/**
 * @return array<string, string>
 */
function octaneArgonCaptureHeader(string $line, array $headers): array
{
    $trimmed = trim($line);
    if ($trimmed === '' || ! str_contains($trimmed, ':')) {
        return $headers;
    }
    [$name, $value] = explode(':', $trimmed, 2);
    $headers[strtolower(trim($name))] = trim($value);

    return $headers;
}

/**
 * @return array{status: int, ms: float, body: string, headers: array<string, string>}
 */
function octaneArgonHttp(string $method, string $path, ?string $payload = null): array
{
    $headers = [];
    $ch = curl_init(octaneArgonTimingBase().$path);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
            $headers = octaneArgonCaptureHeader($line, $headers);

            return strlen($line);
        },
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Connection: close',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = ((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000;
    unset($ch);

    return [
        'status' => $status,
        'ms' => $ms,
        'body' => is_string($raw) ? $raw : '',
        'headers' => $headers,
    ];
}

/**
 * @return array{status: int, ms: float, code: ?string, primed: ?string, worker: ?string}
 */
function octaneArgonLogin(string $phone, string $password): array
{
    $payload = json_encode([
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'argon-timing',
    ], JSON_THROW_ON_ERROR);
    $http = octaneArgonHttp('POST', '/api/v1/auth/login', $payload);
    $decoded = json_decode($http['body'], true);
    $code = is_array($decoded) ? ($decoded['errors'][0]['code'] ?? null) : null;

    return [
        'status' => $http['status'],
        'ms' => $http['ms'],
        'code' => is_string($code) ? $code : null,
        'primed' => $http['headers']['x-octane-argon-dummy-primed'] ?? null,
        'worker' => $http['headers']['x-octane-worker-pid'] ?? null,
    ];
}

/**
 * @param  list<string>  $phones
 * @return list<array{status: int, ms: float, code: ?string, primed: ?string, worker: ?string}>
 */
function octaneArgonLoginConcurrent(array $phones, string $password): array
{
    $mh = curl_multi_init();
    $handles = [];
    $headerBags = [];
    foreach ($phones as $i => $phone) {
        $payload = json_encode([
            'phone' => $phone,
            'password' => $password,
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'argon-timing-'.$i,
        ], JSON_THROW_ON_ERROR);
        $headerBags[$i] = [];
        $ch = curl_init(octaneArgonTimingBase().'/api/v1/auth/login');
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Connection: close',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headerBags, $i): int {
                $headerBags[$i] = octaneArgonCaptureHeader($line, $headerBags[$i]);

                return strlen($line);
            },
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $rows = [];
    foreach ($handles as $i => $ch) {
        $raw = curl_multi_getcontent($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms = ((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $code = is_array($decoded) ? ($decoded['errors'][0]['code'] ?? null) : null;
        $headers = $headerBags[$i];
        $rows[] = [
            'status' => $http,
            'ms' => $ms,
            'code' => is_string($code) ? $code : null,
            'primed' => $headers['x-octane-argon-dummy-primed'] ?? null,
            'worker' => $headers['x-octane-worker-pid'] ?? null,
        ];
        curl_multi_remove_handle($mh, $ch);
        unset($ch);
    }
    curl_multi_close($mh);

    return $rows;
}

/**
 * @param  list<float>  $samples
 * @return array{n: int, min: float, max: float, median: float, p95: float, first: float}
 */
function octaneArgonStats(array $samples): array
{
    $first = $samples[0];
    sort($samples);
    $n = count($samples);
    $idx95 = max(0, (int) ceil(0.95 * $n) - 1);

    return [
        'n' => $n,
        'min' => $samples[0],
        'max' => $samples[$n - 1],
        'median' => $samples[(int) floor(($n - 1) / 2)],
        'p95' => $samples[$idx95],
        'first' => $first,
    ];
}

it('does not expose a first-unknown extra Argon2id make after Octane worker start', function () {
    if (! octaneArgonTimingEnabled()) {
        Assert::markTestSkipped('Set CLINIC_OCTANE_ARGON_TIMING=1 with live Octane to run DEF-C17-ARGON-001.');
    }

    $live = octaneArgonHttp('GET', '/live');
    expect($live['status'])->toBe(200);
    $livePrimed = $live['headers']['x-octane-argon-dummy-primed'] ?? null;
    if ($livePrimed !== null) {
        expect($livePrimed)->toBe('1');
    }

    $knownPhone = getenv('CLINIC_OCTANE_ARGON_KNOWN_PHONE') ?: '01900000000';
    $wrong = 'definitely-not-the-password';
    $nonce = (int) (microtime(true) * 1000) % 10000000;

    $firstUnknownPhone = '0198'.str_pad((string) ($nonce % 10000000), 7, '0', STR_PAD_LEFT);
    $openingUnknown = octaneArgonLogin($firstUnknownPhone, $wrong);

    $known = [];
    for ($i = 0; $i < 12; $i++) {
        $known[] = octaneArgonLogin($knownPhone, $wrong);
    }

    $firstUnknown = [$openingUnknown];
    for ($i = 1; $i < 16; $i++) {
        $firstUnknown[] = octaneArgonLogin(
            '0198'.str_pad((string) (($nonce + $i) % 10000000), 7, '0', STR_PAD_LEFT),
            $wrong,
        );
    }

    $laterUnknown = [];
    for ($i = 0; $i < 12; $i++) {
        $laterUnknown[] = octaneArgonLogin(
            '0197'.str_pad((string) (($nonce + 50 + $i) % 10000000), 7, '0', STR_PAD_LEFT),
            $wrong,
        );
    }

    foreach ([...$known, ...$firstUnknown, ...$laterUnknown] as $row) {
        expect($row['status'])->toBe(401)
            ->and($row['code'])->toBe($known[0]['code']);
        if ($row['primed'] !== null) {
            expect($row['primed'])->toBe('1');
        }
    }

    $knownStats = octaneArgonStats(array_column($known, 'ms'));
    $firstStats = octaneArgonStats(array_column($firstUnknown, 'ms'));
    $laterStats = octaneArgonStats(array_column($laterUnknown, 'ms'));

    $ratio = $firstStats['median'] / max(1.0, $knownStats['median']);
    $delta = $firstStats['median'] - $knownStats['median'];
    $workers = array_values(array_unique(array_filter([
        ...array_column($known, 'worker'),
        ...array_column($firstUnknown, 'worker'),
        ...array_column($laterUnknown, 'worker'),
    ], fn ($pid) => is_string($pid) && $pid !== '')));

    $report = [
        'context' => getenv('CLINIC_OCTANE_ARGON_TIMING_CONTEXT') ?: 'unspecified',
        'live_ms' => $live['ms'],
        'live_primed' => $livePrimed,
        'live_worker' => $live['headers']['x-octane-worker-pid'] ?? null,
        'opening_unknown_ms' => $openingUnknown['ms'],
        'opening_unknown_primed' => $openingUnknown['primed'],
        'opening_unknown_worker' => $openingUnknown['worker'],
        'known_bad' => $knownStats,
        'first_unknown' => $firstStats,
        'later_unknown' => $laterStats,
        'first_over_known_ratio' => $ratio,
        'first_minus_known_ms' => $delta,
        'distinct_workers' => count($workers),
        'worker_pids' => $workers,
        'known_bad_samples_ms' => array_column($known, 'ms'),
        'first_unknown_samples_ms' => array_column($firstUnknown, 'ms'),
        'later_unknown_samples_ms' => array_column($laterUnknown, 'ms'),
    ];
    $out = getenv('CLINIC_OCTANE_ARGON_TIMING_EVIDENCE');
    if (is_string($out) && $out !== '') {
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    expect($ratio)->toBeLessThan(1.45)
        ->and($delta)->toBeLessThan(150.0)
        ->and($openingUnknown['ms'] / max(1.0, $knownStats['first']))->toBeLessThan(1.75)
        ->and($openingUnknown['ms'] - $knownStats['median'])->toBeLessThan(200.0)
        ->and($laterStats['median'] / max(1.0, $knownStats['median']))->toBeLessThan(1.45);
})->group('octane-argon-timing');
