<?php

declare(strict_types=1);

use OTPHP\TOTP;
use PHPUnit\Framework\Assert;

/**
 * G-01-18 live Octane proof. Talks only to CLINIC_OCTANE_ISOLATION_BASE_URL
 * over real HTTP. Does not boot the application kernel as an HTTP server.
 */
function octaneIsoRuntimeEnabled(): bool
{
    return getenv('CLINIC_OCTANE_ISOLATION_RUNTIME') === '1';
}

function octaneIsoIterations(): int
{
    $raw = getenv('CLINIC_OCTANE_ISOLATION_ITERATIONS');
    $n = is_string($raw) && ctype_digit($raw) ? (int) $raw : 50;

    return max(20, min(200, $n));
}

function octaneIsoConcurrentPairs(): int
{
    $raw = getenv('CLINIC_OCTANE_ISOLATION_CONCURRENT');
    $n = is_string($raw) && ctype_digit($raw) ? (int) $raw : 20;

    return max(10, min(100, $n));
}

/**
 * @return array<string, mixed>
 */
function octaneIsoIdentities(): array
{
    $path = getenv('CLINIC_OCTANE_ISOLATION_IDENTITIES');
    if (! is_string($path) || $path === '') {
        throw new RuntimeException('CLINIC_OCTANE_ISOLATION_IDENTITIES is required.');
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    expect($decoded)->toBeArray()
        ->and($decoded['a'] ?? null)->toBeArray()
        ->and($decoded['b'] ?? null)->toBeArray();

    return $decoded;
}

function octaneIsoTotp(string $secret): string
{
    $totp = TOTP::createFromSecret($secret)
        ->withDigest('sha1')
        ->withDigits(6)
        ->withPeriod(30);

    return $totp->now();
}

/**
 * @param  array<string, mixed>|null  $json
 * @param  list<string>  $headers
 * @return array{ok: bool, status: int, headers: array<string, string>, body: array<string, mixed>, raw: string, error: ?string}
 */
function octaneIsoHttp(string $method, string $path, ?array $json = null, array $headers = []): array
{
    $base = rtrim((string) getenv('CLINIC_OCTANE_ISOLATION_BASE_URL'), '/');
    $headerLines = [];
    $ch = curl_init($base.$path);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $httpHeaders = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
    ], $headers);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$headerLines): int {
            $headerLines[] = $line;

            return strlen($line);
        },
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $errno !== 0 ? curl_strerror($errno) : null;
    unset($ch);

    $parsedHeaders = [];
    foreach ($headerLines as $line) {
        if (! str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $parsedHeaders[strtolower(trim($name))] = trim($value);
    }

    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'ok' => $errno === 0,
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => is_string($raw) ? $raw : '',
        'error' => $error,
    ];
}

/**
 * @return array{ok: bool, status: int, headers: array<string, string>, body: array<string, mixed>, raw: string, error: ?string}
 */
function octaneIsoAuthenticatedGet(string $path, string $token): array
{
    return octaneIsoHttp('GET', $path, null, ['Authorization: Bearer '.$token]);
}

/**
 * @return array{a: array<string, mixed>, b: array<string, mixed>}
 */
function octaneIsoConcurrentMe(string $tokenA, string $tokenB): array
{
    $base = rtrim((string) getenv('CLINIC_OCTANE_ISOLATION_BASE_URL'), '/');
    $mh = curl_multi_init();
    $started = [
        'a' => octaneIsoCurlGet($base.'/api/v1/me', $tokenA),
        'b' => octaneIsoCurlGet($base.'/api/v1/me', $tokenB),
    ];
    foreach ($started as $row) {
        curl_multi_add_handle($mh, $row['handle']);
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $out = [];
    foreach ($started as $key => $row) {
        $out[$key] = octaneIsoFinishHandle($row['handle'], $row['headers']);
        curl_multi_remove_handle($mh, $row['handle']);
        unset($row['handle']);
    }
    unset($mh);

    return $out;
}

/**
 * @return array{handle: CurlHandle, headers: object}
 */
function octaneIsoCurlGet(string $url, string $token): array
{
    $headers = (object) ['lines' => []];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use ($headers): int {
            $headers->lines[] = $line;

            return strlen($line);
        },
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer '.$token,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    return ['handle' => $ch, 'headers' => $headers];
}

/**
 * @param  CurlHandle  $ch
 * @return array{ok: bool, status: int, headers: array<string, string>, body: array<string, mixed>, raw: string, error: ?string}
 */
function octaneIsoFinishHandle($ch, object $headerBag): array
{
    $raw = curl_multi_getcontent($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $parsedHeaders = [];
    foreach ($headerBag->lines as $line) {
        if (! is_string($line) || ! str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $parsedHeaders[strtolower(trim($name))] = trim($value);
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'ok' => $errno === 0,
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => is_string($raw) ? $raw : '',
        'error' => $errno !== 0 ? curl_strerror($errno) : null,
    ];
}

/**
 * @param  array<string, mixed>  $foreign
 * @param  array<string, mixed>  $response
 * @return list<string>
 */
function octaneIsoForeignIdentityInBody(array $foreign, array $response): array
{
    $reasons = [];
    $raw = (string) ($response['raw'] ?? '');
    foreach (['user_id', 'session_id', 'device_id'] as $idField) {
        $foreignId = $foreign[$idField] ?? null;
        if (is_string($foreignId) && $foreignId !== '' && str_contains($raw, $foreignId)) {
            $reasons[] = 'response contained foreign '.$idField;
        }
    }

    return $reasons;
}

/**
 * @param  array<string, mixed>  $expected
 * @param  array<string, mixed>  $foreign
 * @param  array<string, mixed>  $response
 * @return list<string>
 */
function octaneIsoMeLeakReasons(string $who, array $expected, array $foreign, array $response): array
{
    $reasons = [];
    if (($response['ok'] ?? false) !== true || (int) ($response['status'] ?? 0) !== 200) {
        $reasons[] = $who.' request failed status='.(string) ($response['status'] ?? 0).' error='.(string) ($response['error'] ?? '');

        return $reasons;
    }

    $data = $response['body']['data'] ?? null;
    if (! is_array($data)) {
        $reasons[] = $who.' missing data envelope';

        return $reasons;
    }

    foreach (['user_id', 'account_type', 'status', 'language', 'assurance_level'] as $field) {
        $actual = $data[$field] ?? null;
        if ($actual !== $expected[$field]) {
            $reasons[] = $who.' '.$field.' expected '.$expected[$field].' got '.json_encode($actual);
        }
        if (($foreign[$field] ?? null) !== null && $actual === $foreign[$field] && $expected[$field] !== $foreign[$field]) {
            $reasons[] = $who.' observed foreign '.$field;
        }
    }

    foreach (octaneIsoForeignIdentityInBody($foreign, $response) as $reason) {
        $reasons[] = $who.' '.$reason;
    }

    return $reasons;
}

/**
 * @param  array<string, mixed>  $expected
 * @param  array<string, mixed>  $foreign
 * @param  array<string, mixed>  $response
 * @return list<string>
 */
function octaneIsoCapsLeakReasons(string $who, array $expected, array $foreign, array $response): array
{
    $reasons = [];
    if (($response['ok'] ?? false) !== true || (int) ($response['status'] ?? 0) !== 200) {
        $reasons[] = $who.' request failed status='.(string) ($response['status'] ?? 0).' error='.(string) ($response['error'] ?? '');

        return $reasons;
    }

    $data = $response['body']['data'] ?? null;
    $caps = is_array($data) ? ($data['capabilities'] ?? null) : null;
    if (! is_array($caps)) {
        $reasons[] = $who.' missing capabilities list';

        return $reasons;
    }

    $sorted = $caps;
    sort($sorted);
    $want = $expected['capabilities'];
    sort($want);
    if ($sorted !== $want) {
        $reasons[] = $who.' capabilities mismatch want='.implode(',', $want).' got='.implode(',', $sorted);
    }

    $foreignUnique = $foreign['unique_capability'] ?? null;
    if (is_string($foreignUnique) && in_array($foreignUnique, $caps, true) && $foreignUnique !== ($expected['unique_capability'] ?? null)) {
        $reasons[] = $who.' observed foreign capability '.$foreignUnique;
    }

    foreach (octaneIsoForeignIdentityInBody($foreign, $response) as $reason) {
        $reasons[] = $who.' '.$reason;
    }

    return $reasons;
}

it('does not leak actor identity or capabilities between two authenticated users on one Octane worker', function () {
    if (! octaneIsoRuntimeEnabled()) {
        Assert::markTestSkipped('Set CLINIC_OCTANE_ISOLATION_RUNTIME=1 with live Octane to run G-01-18.');
    }

    $identities = octaneIsoIdentities();
    $iterations = octaneIsoIterations();
    $concurrent = octaneIsoConcurrentPairs();
    $leaks = [];
    $workerPids = [];
    $requestIds = [];
    $authenticatedGets = 0;

    $loginA = octaneIsoHttp('POST', '/api/v1/auth/login', [
        'phone' => $identities['a']['phone'],
        'password' => $identities['password'],
        'client_class' => $identities['a']['client_class'],
        'platform' => $identities['a']['platform'],
        'device_label' => $identities['a']['device_label'],
    ]);
    if ($loginA['status'] !== 200) {
        fwrite(STDERR, 'login A: '.$loginA['raw'].PHP_EOL);
    }
    expect($loginA['status'])->toBe(200)
        ->and($loginA['body']['data']['access_token'] ?? null)->toBeString();
    $tokenA = (string) $loginA['body']['data']['access_token'];
    $sessionA = (string) ($loginA['body']['data']['session_id'] ?? '');
    $deviceA = (string) ($loginA['body']['data']['device_id'] ?? '');

    $loginB = octaneIsoHttp('POST', '/api/v1/auth/login', [
        'phone' => $identities['b']['phone'],
        'password' => $identities['password'],
        'client_class' => $identities['b']['client_class'],
        'platform' => $identities['b']['platform'],
        'device_label' => $identities['b']['device_label'],
    ]);
    if ($loginB['status'] !== 200) {
        fwrite(STDERR, 'login B: '.$loginB['raw'].PHP_EOL);
    }
    expect($loginB['status'])->toBe(200)
        ->and($loginB['body']['data']['status'] ?? null)->toBe('mfa_required');
    $challengeId = (string) $loginB['body']['data']['challenge_id'];
    $mfa = octaneIsoHttp('POST', '/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
        'code' => octaneIsoTotp((string) $identities['b']['totp_secret']),
    ]);
    if (($mfa['status'] ?? 0) !== 200) {
        $mfa = octaneIsoHttp('POST', '/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', [
            'code' => octaneIsoTotp((string) $identities['b']['totp_secret']),
        ]);
    }
    if ($mfa['status'] !== 200) {
        fwrite(STDERR, 'mfa B: '.$mfa['raw'].PHP_EOL);
    }
    expect($mfa['status'])->toBe(200)
        ->and($mfa['body']['data']['access_token'] ?? null)->toBeString();
    $tokenB = (string) $mfa['body']['data']['access_token'];
    $sessionB = (string) ($mfa['body']['data']['session_id'] ?? '');
    $deviceB = (string) ($mfa['body']['data']['device_id'] ?? '');

    expect($tokenA)->not->toBe($tokenB)
        ->and($sessionA)->not->toBe($sessionB)
        ->and($identities['a']['user_id'])->not->toBe($identities['b']['user_id']);

    $expectedA = [
        'user_id' => $identities['a']['user_id'],
        'account_type' => $identities['a']['account_type'],
        'status' => $identities['a']['status'],
        'language' => $identities['a']['language'],
        'assurance_level' => $identities['a']['assurance_level'],
        'session_id' => $sessionA,
        'device_id' => $deviceA,
        'unique_capability' => $identities['a']['unique_capability'],
    ];
    $expectedB = [
        'user_id' => $identities['b']['user_id'],
        'account_type' => $identities['b']['account_type'],
        'status' => $identities['b']['status'],
        'language' => $identities['b']['language'],
        'assurance_level' => $identities['b']['assurance_level'],
        'session_id' => $sessionB,
        'device_id' => $deviceB,
        'unique_capability' => $identities['b']['unique_capability'],
    ];
    $capsExpectedA = $expectedA + ['capabilities' => $identities['a']['capabilities']];
    $capsExpectedB = $expectedB + ['capabilities' => $identities['b']['capabilities']];

    $record = function (array $response) use (&$workerPids, &$requestIds): void {
        $pid = $response['headers']['x-octane-worker-pid'] ?? null;
        if (is_string($pid) && $pid !== '') {
            $workerPids[$pid] = true;
        }
        $rid = $response['headers']['x-request-id'] ?? ($response['body']['request_id'] ?? null);
        if (is_string($rid) && $rid !== '') {
            $requestIds[] = $rid;
        }
    };

    $checkMe = function (string $who, string $token, array $expected, array $foreign) use (&$leaks, &$authenticatedGets, $record): void {
        $response = octaneIsoAuthenticatedGet('/api/v1/me', $token);
        $authenticatedGets++;
        $record($response);
        foreach (octaneIsoMeLeakReasons($who.' /me', $expected, $foreign, $response) as $reason) {
            $leaks[] = $reason;
        }
    };
    $checkCaps = function (string $who, string $token, array $expected, array $foreign) use (&$leaks, &$authenticatedGets, $record): void {
        $response = octaneIsoAuthenticatedGet('/api/v1/me/capabilities', $token);
        $authenticatedGets++;
        $record($response);
        foreach (octaneIsoCapsLeakReasons($who.' /me/capabilities', $expected, $foreign, $response) as $reason) {
            $leaks[] = $reason;
        }
    };

    $checkMe('A', $tokenA, $expectedA, $expectedB);
    $checkMe('B', $tokenB, $expectedB, $expectedA);
    $checkCaps('A', $tokenA, $capsExpectedA, $capsExpectedB);
    $checkCaps('B', $tokenB, $capsExpectedB, $capsExpectedA);

    for ($i = 0; $i < $iterations; $i++) {
        if ($i % 2 === 0) {
            $checkMe('A', $tokenA, $expectedA, $expectedB);
            $checkMe('B', $tokenB, $expectedB, $expectedA);
            $checkCaps('A', $tokenA, $capsExpectedA, $capsExpectedB);
            $checkCaps('B', $tokenB, $capsExpectedB, $capsExpectedA);
        } else {
            $checkMe('B', $tokenB, $expectedB, $expectedA);
            $checkMe('A', $tokenA, $expectedA, $expectedB);
            $checkCaps('B', $tokenB, $capsExpectedB, $capsExpectedA);
            $checkCaps('A', $tokenA, $capsExpectedA, $capsExpectedB);
        }
    }

    $concurrentLeaks = 0;
    for ($i = 0; $i < $concurrent; $i++) {
        $pair = octaneIsoConcurrentMe($tokenA, $tokenB);
        $authenticatedGets += 2;
        $record($pair['a']);
        $record($pair['b']);
        $aReasons = octaneIsoMeLeakReasons('A concurrent /me', $expectedA, $expectedB, $pair['a']);
        $bReasons = octaneIsoMeLeakReasons('B concurrent /me', $expectedB, $expectedA, $pair['b']);
        $concurrentLeaks += count($aReasons) + count($bReasons);
        array_push($leaks, ...$aReasons, ...$bReasons);
    }

    $uniquePids = array_keys($workerPids);
    $uniqueRequestIds = array_values(array_unique($requestIds));
    $workerReuse = count($uniquePids) === 1 && $authenticatedGets > 1;
    $result = $leaks === [] && $workerReuse ? 'PASS' : 'FAIL';

    $evidence = [
        'gate' => 'G-01-18',
        'result' => $result,
        'runtime' => 'php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8080 --workers=1 --max-requests=10000',
        'octane_server' => 'frankenphp',
        'workers' => 1,
        'max_requests' => 10000,
        'worker_pids' => $uniquePids,
        'worker_reuse_proven' => $workerReuse,
        'sequential_iterations' => $iterations,
        'concurrent_pairs' => $concurrent,
        'authenticated_gets' => $authenticatedGets,
        'unique_request_ids' => count($uniqueRequestIds),
        'request_id_collisions' => count($requestIds) - count($uniqueRequestIds),
        'leakage_failures' => count($leaks),
        'leakage_samples' => array_slice($leaks, 0, 20),
        'users' => [
            'A' => [
                'label' => 'synthetic-isolation-alpha',
                'account_type' => $identities['a']['account_type'],
                'language' => $identities['a']['language'],
                'assurance_level' => $identities['a']['assurance_level'],
                'status' => $identities['a']['status'],
                'unique_capability' => $identities['a']['unique_capability'],
                'user_id' => $identities['a']['user_id'],
                'session_kind' => 'device',
            ],
            'B' => [
                'label' => 'synthetic-isolation-beta',
                'account_type' => $identities['b']['account_type'],
                'language' => $identities['b']['language'],
                'assurance_level' => $identities['b']['assurance_level'],
                'status' => $identities['b']['status'],
                'unique_capability' => $identities['b']['unique_capability'],
                'user_id' => $identities['b']['user_id'],
                'session_kind' => 'device',
            ],
        ],
        'sequence' => 'login A (password) then login B (password+TOTP); alternate GET /api/v1/me and /api/v1/me/capabilities; then concurrent paired GET /api/v1/me',
        'candidate_sha' => getenv('CLINIC_OCTANE_ISOLATION_SHA') ?: null,
        'generated_at' => gmdate('c'),
    ];

    $path = getenv('CLINIC_OCTANE_ISOLATION_EVIDENCE');
    if (is_string($path) && $path !== '') {
        file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect($leaks)->toBe([])
        ->and($uniquePids)->toHaveCount(1)
        ->and($authenticatedGets)->toBeGreaterThan($iterations)
        ->and($concurrentLeaks)->toBe(0);
})->group('octane-isolation');
