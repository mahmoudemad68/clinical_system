<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

function twoConnRuntimeEnabled(): bool
{
    return getenv('CLINIC_TWO_CONNECTION_RACE') === '1';
}

function twoConnIterations(): int
{
    $raw = getenv('CLINIC_TWO_CONNECTION_RACE_ITERATIONS');
    $n = is_string($raw) && ctype_digit($raw) ? (int) $raw : 40;

    return max(20, min(200, $n));
}

/**
 * @return array<string, mixed>
 */
function twoConnEvidence(): array
{
    if (! isset($GLOBALS['clinic_g0112_evidence']) || ! is_array($GLOBALS['clinic_g0112_evidence'])) {
        $GLOBALS['clinic_g0112_evidence'] = [
            'gate' => 'G-01-12',
            'scenarios' => [],
        ];
    }

    return $GLOBALS['clinic_g0112_evidence'];
}

function twoConnStoreScenario(string $id, array $row): void
{
    $evidence = twoConnEvidence();
    $evidence['scenarios'][$id] = $row;
    $GLOBALS['clinic_g0112_evidence'] = $evidence;
    $path = getenv('CLINIC_TWO_CONNECTION_RACE_EVIDENCE');
    if (is_string($path) && $path !== '') {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}

beforeEach(function () {
    if (! twoConnRuntimeEnabled()) {
        return;
    }

    config([
        'identity.rate_limits.otp_per_ip_per_hour' => 100000,
        'identity.rate_limits.otp_per_subject_per_hour' => 100000,
        'identity.rate_limits.login_per_ip_per_minute' => 100000,
        'identity.rate_limits.recovery_per_subject_per_hour' => 100000,
        'identity.rate_limits.refresh_per_device_per_minute' => 100000,
        'identity.rate_limits.refresh_per_ip_per_minute' => 100000,
        'identity.otp.global_hourly_budget' => 100000,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);
});

afterAll(function () {
    if (! twoConnRuntimeEnabled()) {
        return;
    }

    $path = getenv('CLINIC_TWO_CONNECTION_RACE_EVIDENCE');
    if (! is_string($path) || $path === '') {
        return;
    }

    $evidence = twoConnEvidence();
    $scenarios = $evidence['scenarios'];
    $failed = 0;
    foreach ($scenarios as $row) {
        if (($row['result'] ?? '') !== 'PASS') {
            $failed++;
        }
    }

    $txIso = 'read committed';
    try {
        $isolation = DB::selectOne('SHOW default_transaction_isolation');
        if (is_object($isolation) && isset($isolation->default_transaction_isolation)) {
            $txIso = (string) $isolation->default_transaction_isolation;
        }
    } catch (Throwable) {
        // Application may already be torn down; the default is still the runtime used.
    }

    $evidence['result'] = $failed === 0 && $scenarios !== [] ? 'PASS' : 'FAIL';
    $evidence['generated_at'] = gmdate('c');
    $evidence['candidate_sha'] = (string) (getenv('CLINIC_TWO_CONNECTION_RACE_SHA') ?: '');
    $evidence['command'] = 'bash scripts/perf/run-two-connection-auth-races.sh';
    $evidence['concurrency_method'] = 'two_os_processes_file_barrier_then_independent_laravel_http_kernels';
    $evidence['db_isolation'] = $txIso !== '' ? $txIso : 'read committed (PostgreSQL default)';
    $evidence['locking'] = [
        'refresh' => 'SELECT user_devices ... FOR UPDATE by current/previous/consumed refresh hash',
        'logout' => 'FOR UPDATE user_devices then auth_sessions (same order as refresh)',
        'otp_mfa' => 'SELECT otp_requests / mfa_challenges WHERE id = ? FOR UPDATE',
        'unique_indexes' => [
            'auth_refresh_consumptions_token_hash_unique',
            'user_devices_active_refresh_hash_unique',
        ],
    ];
    $evidence['failures'] = $failed;

    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
});

it('races two refreshes of the same token on independent connections', function () {
    if (! twoConnRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_TWO_CONNECTION_RACE=1 to run G-01-12.');
    }

    $iterations = twoConnIterations();
    $failures = 0;
    $deadlocks = 0;
    $timeouts = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $issued = twoConnIssueDeviceSession($this, 'ref-'.$i);
        $pair = ConcurrentHttpPair::run([
            'op' => 'refresh',
            'refresh_token' => $issued['refresh_token'],
            'idempotency_key' => 'clinic-race-ref-a-'.$i,
        ], [
            'op' => 'refresh',
            'refresh_token' => $issued['refresh_token'],
            'idempotency_key' => 'clinic-race-ref-b-'.$i,
        ]);

        $state = twoConnSessionState($issued['user_id'], $issued['device_id'], $issued['family_id']);
        $ok = twoConnNoInfrastructureFailure($pair, $deadlocks, $timeouts)
            && $state['active_sessions'] <= 1
            && $state['active_devices'] <= 1
            && $state['current_refresh_hashes'] <= 1
            && $state['duplicate_consumption_hashes'] === 0;

        if (! $ok) {
            $failures++;
        }
    }

    twoConnStoreScenario('dual_refresh_same_token', [
        'iterations' => $iterations,
        'failures' => $failures,
        'deadlocks' => $deadlocks,
        'timeouts' => $timeouts,
        'result' => $failures === 0 ? 'PASS' : 'FAIL',
        'invariant' => 'At most one live session/device/refresh hash; reuse of the loser revokes the family; unique consumption hashes.',
    ]);

    expect($failures)->toBe(0)->and($deadlocks)->toBe(0)->and($timeouts)->toBe(0);
})->group('two-connection-race');

it('races refresh against logout on the same session family', function () {
    if (! twoConnRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_TWO_CONNECTION_RACE=1 to run G-01-12.');
    }

    $iterations = twoConnIterations();
    $failures = 0;
    $deadlocks = 0;
    $timeouts = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $issued = twoConnIssueDeviceSession($this, 'out-'.$i);
        $pair = ConcurrentHttpPair::run([
            'op' => 'refresh',
            'refresh_token' => $issued['refresh_token'],
            'idempotency_key' => 'clinic-race-out-ref-'.$i,
        ], [
            'op' => 'logout',
            'access_token' => $issued['access_token'],
        ]);

        $state = twoConnSessionState($issued['user_id'], $issued['device_id'], $issued['family_id']);
        $ok = twoConnNoInfrastructureFailure($pair, $deadlocks, $timeouts)
            && $state['active_sessions'] <= 1
            && $state['duplicate_consumption_hashes'] === 0
            && (
                $state['active_sessions'] === 0
                || ($state['active_sessions'] === 1 && $state['device_revoked'] === false)
            );

        // Logout and refresh serialize on the device row. Final state is either
        // a revoked session or a single rotated live session, never both live
        // and revoked-missing, and never two successors.
        $logoutStatus = (int) ($pair['right']['status'] ?? 0);
        $refreshStatus = (int) ($pair['left']['status'] ?? 0);
        if ($logoutStatus === 200) {
            $ok = $ok && $state['session_revoked'] === true && $state['device_revoked'] === true && $state['active_sessions'] === 0;
        }
        if ($refreshStatus === 200 && $logoutStatus !== 200) {
            $ok = $ok && $state['active_sessions'] <= 1;
        }

        if (! $ok) {
            $failures++;
        }
    }

    twoConnStoreScenario('refresh_vs_logout', [
        'iterations' => $iterations,
        'failures' => $failures,
        'deadlocks' => $deadlocks,
        'timeouts' => $timeouts,
        'result' => $failures === 0 ? 'PASS' : 'FAIL',
        'invariant' => 'Device then session row locks. Logout commit leaves zero live sessions; refresh-first leaves at most one successor until logout revokes it.',
    ]);

    expect($failures)->toBe(0)->and($deadlocks)->toBe(0)->and($timeouts)->toBe(0);
})->group('two-connection-race');

it('races reuse of a rotated refresh token against an in-flight successor refresh', function () {
    if (! twoConnRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_TWO_CONNECTION_RACE=1 to run G-01-12.');
    }

    $iterations = twoConnIterations();
    $failures = 0;
    $deadlocks = 0;
    $timeouts = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $issued = twoConnIssueDeviceSession($this, 'reuse-'.$i);
        $oldRefresh = $issued['refresh_token'];
        $rotated = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $oldRefresh,
        ], twoConnIdem('reuse-rot-'.$i));
        $rotated->assertOk();
        $newRefresh = $rotated->json('data.refresh_token');

        $pair = ConcurrentHttpPair::run([
            'op' => 'refresh',
            'refresh_token' => $oldRefresh,
            'idempotency_key' => 'clinic-race-reuse-old-'.$i,
        ], [
            'op' => 'refresh',
            'refresh_token' => $newRefresh,
            'idempotency_key' => 'clinic-race-reuse-new-'.$i,
        ]);

        $state = twoConnSessionState($issued['user_id'], $issued['device_id'], $issued['family_id']);
        $ok = twoConnNoInfrastructureFailure($pair, $deadlocks, $timeouts)
            && $state['active_sessions'] === 0
            && $state['device_revoked'] === true
            && $state['duplicate_consumption_hashes'] === 0
            && $state['current_refresh_hashes'] === 0;

        if (! $ok) {
            $failures++;
        }
    }

    twoConnStoreScenario('rotated_reuse_vs_inflight_successor', [
        'iterations' => $iterations,
        'failures' => $failures,
        'deadlocks' => $deadlocks,
        'timeouts' => $timeouts,
        'result' => $failures === 0 ? 'PASS' : 'FAIL',
        'invariant' => 'Presenting N-1 while N is in flight revokes the family. Zero live sessions remain.',
    ]);

    expect($failures)->toBe(0)->and($deadlocks)->toBe(0)->and($timeouts)->toBe(0);
})->group('two-connection-race');

it('consumes an OTP challenge once under two connections and does not lose wrong-code attempts', function () {
    if (! twoConnRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_TWO_CONNECTION_RACE=1 to run G-01-12.');
    }

    $iterations = twoConnIterations();
    $failures = 0;
    $deadlocks = 0;
    $timeouts = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $open = twoConnOpenRegistration($this, 'otp-'.$i);
        $pair = ConcurrentHttpPair::run([
            'op' => 'otp_verify',
            'challenge_id' => $open['challenge_id'],
            'code' => $open['code'],
            'device_label' => 'one',
            'idempotency_key' => 'clinic-race-otp-a-'.$i,
        ], [
            'op' => 'otp_verify',
            'challenge_id' => $open['challenge_id'],
            'code' => $open['code'],
            'device_label' => 'two',
            'idempotency_key' => 'clinic-race-otp-b-'.$i,
        ]);

        $otp = DB::table('otp_requests')->where('id', $open['challenge_id'])->first();
        $sessions = DB::table('auth_sessions')->where('user_id', $open['user_id'])->whereNull('revoked_at')->count();
        $successes = ((int) ($pair['left']['status'] ?? 0) === 200 ? 1 : 0)
            + ((int) ($pair['right']['status'] ?? 0) === 200 ? 1 : 0);

        $ok = twoConnNoInfrastructureFailure($pair, $deadlocks, $timeouts)
            && $successes === 1
            && $sessions === 1
            && $otp !== null
            && $otp->consumed_at !== null
            && (int) $otp->attempts >= 1;

        if (! $ok) {
            $failures++;
        }
    }

    for ($i = 0; $i < $iterations; $i++) {
        $open = twoConnOpenRegistration($this, 'otp-bad-'.$i);
        $pair = ConcurrentHttpPair::run([
            'op' => 'otp_verify',
            'challenge_id' => $open['challenge_id'],
            'code' => '000000',
            'device_label' => 'one',
            'idempotency_key' => 'clinic-race-otp-bad-a-'.$i,
        ], [
            'op' => 'otp_verify',
            'challenge_id' => $open['challenge_id'],
            'code' => '111111',
            'device_label' => 'two',
            'idempotency_key' => 'clinic-race-otp-bad-b-'.$i,
        ]);

        $otp = DB::table('otp_requests')->where('id', $open['challenge_id'])->first();
        $sessions = DB::table('auth_sessions')->where('user_id', $open['user_id'])->count();
        $ok = twoConnNoInfrastructureFailure($pair, $deadlocks, $timeouts)
            && $otp !== null
            && $otp->consumed_at === null
            && (int) $otp->attempts === 2
            && $sessions === 0
            && (int) ($pair['left']['status'] ?? 0) !== 200
            && (int) ($pair['right']['status'] ?? 0) !== 200;

        if (! $ok) {
            $failures++;
        }
    }

    twoConnStoreScenario('otp_single_consumer_and_attempts', [
        'iterations' => $iterations,
        'wrong_code_iterations' => $iterations,
        'failures' => $failures,
        'deadlocks' => $deadlocks,
        'timeouts' => $timeouts,
        'result' => $failures === 0 ? 'PASS' : 'FAIL',
        'invariant' => 'FOR UPDATE on otp_requests: one consume, one session; concurrent wrong codes commit both attempt increments.',
    ]);

    expect($failures)->toBe(0)->and($deadlocks)->toBe(0)->and($timeouts)->toBe(0);
})->group('two-connection-race');

it('consumes a recovery OTP once under two connections', function () {
    if (! twoConnRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_TWO_CONNECTION_RACE=1 to run G-01-12.');
    }

    $iterations = twoConnIterations();
    $failures = 0;
    $deadlocks = 0;
    $timeouts = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $issued = twoConnIssueDeviceSession($this, 'rec-'.$i);
        $phone = $issued['phone'];
        $this->postJson('/api/v1/auth/recovery/start', [
            'phone' => $phone,
            'language' => 'en',
        ])->assertOk();
        twoConnDispatchOutbox();
        $challengeId = (string) DB::table('otp_requests')->where('purpose', 'recovery')->orderByDesc('created_at')->value('id');
        $code = twoConnLastOtp('recovery');
        $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], twoConnIdem('rec-ver-'.$i))->assertOk();

        $pair = ConcurrentHttpPair::run([
            'op' => 'recovery_complete',
            'challenge_id' => $challengeId,
            'code' => $code,
            'password' => 'recovered-horse-battery',
            'idempotency_key' => 'clinic-race-rec-a-'.$i,
        ], [
            'op' => 'recovery_complete',
            'challenge_id' => $challengeId,
            'code' => $code,
            'password' => 'recovered-horse-battery',
            'idempotency_key' => 'clinic-race-rec-b-'.$i,
        ]);

        $otp = DB::table('otp_requests')->where('id', $challengeId)->first();
        $recoveries = DB::table('recovery_requests')->where('user_id', $issued['user_id'])->count();
        $version = (int) DB::table('users')->where('id', $issued['user_id'])->value('credential_version');
        $successes = ((int) ($pair['left']['status'] ?? 0) === 200 ? 1 : 0)
            + ((int) ($pair['right']['status'] ?? 0) === 200 ? 1 : 0);

        $ok = twoConnNoInfrastructureFailure($pair, $deadlocks, $timeouts)
            && $successes === 1
            && $recoveries === 1
            && $otp !== null
            && $otp->consumed_at !== null
            && $version === ((int) $issued['credential_version']) + 1;

        if (! $ok) {
            $failures++;
        }
    }

    twoConnStoreScenario('recovery_otp_single_consumer', [
        'iterations' => $iterations,
        'failures' => $failures,
        'deadlocks' => $deadlocks,
        'timeouts' => $timeouts,
        'result' => $failures === 0 ? 'PASS' : 'FAIL',
        'invariant' => 'Recovery complete locks the OTP row; one applied recovery and one credential_version bump.',
    ]);

    expect($failures)->toBe(0)->and($deadlocks)->toBe(0)->and($timeouts)->toBe(0);
})->group('two-connection-race');

/**
 * @return array{user_id: string, device_id: string, family_id: string, session_id: string, access_token: string, refresh_token: string, phone: string, credential_version: int}
 */
function twoConnIssueDeviceSession(mixed $test, string $key): array
{
    $open = twoConnOpenRegistration($test, $key);
    $verify = $test->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => $open['challenge_id'],
        'code' => $open['code'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone',
    ], twoConnIdem('ver-'.$key));
    $verify->assertOk();

    $deviceId = (string) $verify->json('data.device_id');
    $family = (string) DB::table('user_devices')->where('id', $deviceId)->value('refresh_family_id');

    return [
        'user_id' => $open['user_id'],
        'device_id' => $deviceId,
        'family_id' => $family,
        'session_id' => (string) $verify->json('data.session_id'),
        'access_token' => (string) $verify->json('data.access_token'),
        'refresh_token' => (string) $verify->json('data.refresh_token'),
        'phone' => $open['phone'],
        'credential_version' => (int) DB::table('users')->where('id', $open['user_id'])->value('credential_version'),
    ];
}

/**
 * @return array{user_id: string, challenge_id: string, code: string, phone: string}
 */
function twoConnOpenRegistration(mixed $test, string $key): array
{
    $payload = twoConnIdentity();
    $register = $test->postJson('/api/v1/auth/registrations', $payload, twoConnIdem('reg-'.$key))->assertCreated();
    twoConnDispatchOutbox();
    $userId = (string) DB::table('users')->orderByDesc('created_at')->value('id');

    return [
        'user_id' => $userId,
        'challenge_id' => (string) $register->json('data.challenge_id'),
        'code' => twoConnLastOtp('registration'),
        'phone' => $payload['phone'],
    ];
}

function twoConnIdentity(): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);

    return [
        'name' => 'Synthetic Patient',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];
}

function twoConnLastOtp(string $purpose): string
{
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    return $sms->lastCodeByPurpose[$purpose];
}

function twoConnDispatchOutbox(): void
{
    app(OutboxDispatcher::class)->dispatchBatch();
}

/**
 * @return array{Idempotency-Key: string}
 */
function twoConnIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-race-'.$name];
}

/**
 * @param  array{left: array<string, mixed>, right: array<string, mixed>}  $pair
 */
function twoConnNoInfrastructureFailure(array $pair, int &$deadlocks, int &$timeouts): bool
{
    foreach (['left', 'right'] as $side) {
        $row = $pair[$side];
        $sqlstate = (string) ($row['sqlstate'] ?? '');
        $error = (string) ($row['error'] ?? '');
        if ($sqlstate === '40P01' || $sqlstate === '40001') {
            $deadlocks++;

            return false;
        }
        if ($error === 'worker_unreadable' || str_contains((string) ($row['stderr'] ?? ''), 'worker_timeout') || ($row['status'] ?? 0) === 0) {
            $timeouts++;

            return false;
        }
        if ((int) ($row['status'] ?? 0) >= 500) {
            return false;
        }
    }

    return true;
}

/**
 * @return array{
 *     active_sessions: int,
 *     active_devices: int,
 *     session_revoked: bool,
 *     device_revoked: bool,
 *     current_refresh_hashes: int,
 *     duplicate_consumption_hashes: int
 * }
 */
function twoConnSessionState(string $userId, string $deviceId, string $familyId): array
{
    $activeSessions = (int) DB::table('auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')->count();
    $activeDevices = (int) DB::table('user_devices')->where('user_id', $userId)->whereNull('revoked_at')->count();
    $session = DB::table('auth_sessions')->where('device_id', $deviceId)->orderByDesc('created_at')->first();
    $device = DB::table('user_devices')->where('id', $deviceId)->first();
    $hashes = (int) (DB::selectOne(
        'SELECT COUNT(DISTINCT refresh_token_hash) AS n
         FROM user_devices
         WHERE refresh_family_id = ? AND refresh_token_hash IS NOT NULL AND revoked_at IS NULL',
        [$familyId],
    )->n ?? 0);
    $dupes = (int) DB::selectOne(
        'SELECT COUNT(*) - COUNT(DISTINCT token_hash) AS dupes FROM auth_refresh_consumptions WHERE family_id = ?',
        [$familyId],
    )->dupes;

    return [
        'active_sessions' => $activeSessions,
        'active_devices' => $activeDevices,
        'session_revoked' => $session !== null && $session->revoked_at !== null,
        'device_revoked' => $device !== null && $device->revoked_at !== null,
        'current_refresh_hashes' => $hashes,
        'duplicate_consumption_hashes' => $dupes,
    ];
}
