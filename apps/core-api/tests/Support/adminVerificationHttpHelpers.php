<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Admin\Services\AdminVerificationReviewService;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\CursorScope;
use Modules\Verification\Support\ReviewerQueueFilters;
use Tests\Support\RecordingStoreObject;

/**
 * @return array{id: string, phone: string, password: string, totp_secret: string}
 */
function adminVerificationInsertAdmin(string $key = 'reviewer'): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $userId = $ids->next()->value;
    $password = 'correct-horse-battery';

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Synthetic Admin '.$key,
        'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsed)),
        'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsed)),
        'phone_key_version' => 1,
        'password_hash' => app(PasswordHasher::class)->hash($password),
        'account_type' => 'admin',
        'status' => 'active',
        'language' => 'en',
        'credential_version' => 1,
        'phone_verified_at' => $now,
        'last_authenticated_at' => null,
        'bootstrap_exempt' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $totp = app(TotpVerifier::class);
    $secret = $totp->generateSecret();
    DB::table('mfa_factors')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'factor_type' => 'totp',
        'secret_ciphertext' => BinaryColumn::bind($protector->encryptSecret('mfa_secret', $secret)),
        'key_version' => 1,
        'last_used_counter' => null,
        'last_used_at' => null,
        'verified_at' => $now,
        'disabled_at' => null,
        'disabled_by' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $userId,
        'phone' => $phone,
        'password' => $password,
        'totp_secret' => $secret,
    ];
}

function adminVerificationLogin(array $admin): void
{
    test()->withCredentials();
    test()->getJson('/api/v1/auth/csrf')->assertOk();
    $csrf = csrf_token();
    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $admin['phone'],
        'password' => $admin['password'],
        'client_class' => 'admin_web',
        'platform' => 'web',
        'device_label' => 'admin-browser',
        '_token' => $csrf,
    ], ['X-CSRF-TOKEN' => $csrf]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

    $code = app(TotpVerifier::class)->codeAt($admin['totp_secret'], app(Clock::class)->now());
    $csrf = csrf_token();
    $mfa = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'code' => $code,
        '_token' => $csrf,
    ], ['X-CSRF-TOKEN' => $csrf]);
    $mfa->assertOk()->assertJsonPath('data.session_kind', 'admin_cookie');

    Auth::guard('web')->forgetUser();
    adminVerificationPinCookie();
}

function adminVerificationPinCookie(): void
{
    test()->withCredentials()
        ->withCookie((string) config('session.cookie'), (string) session()->getId());
}

function adminVerificationGetJson(string $uri): TestResponse
{
    adminVerificationPinCookie();

    return test()->getJson($uri);
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function adminVerificationPostJson(string $uri, array $body = [], array $headers = []): TestResponse
{
    adminVerificationPinCookie();
    $csrf = csrf_token();

    return test()->postJson($uri, $body + ['_token' => $csrf], ['X-CSRF-TOKEN' => $csrf] + $headers);
}

function adminVerificationLogout(): void
{
    adminVerificationPostJson('/api/v1/auth/logout')->assertOk();
    Auth::guard('web')->forgetUser();
}

function adminVerificationIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     actor: ActorContext,
 *     doctor_id: string,
 *     case_id: string,
 *     case_version: int,
 *     profile_version: int,
 *     object_id: string,
 *     national_id: string
 * }
 */
function adminVerificationPendingCase(string $key): array
{
    $draft = verificationPrepareDraftWithAvailableDocument($key);
    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody($draft['case_version'], $draft['profile_version']),
        doctorsAuth($draft['session']['token']) + doctorsIdem('admin-ver-sub-'.$key),
    )->assertOk();

    $draft['case_version'] = $draft['case_version'] + 1;

    return $draft;
}

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     actor: ActorContext,
 *     doctor_id: string,
 *     case_id: string,
 *     case_version: int,
 *     profile_version: int,
 *     object_id: string,
 *     document_id: string,
 *     upload_id: string,
 *     storage_locator: string,
 *     canonical_storage_locator: string,
 *     national_id: string
 * }
 */
function adminVerificationPendingCanonicalCase(string $key): array
{
    verificationBindCleanScanner();
    $onboarded = verificationOnboardDoctor($key);
    $opened = verificationOpenCase($onboarded['actor']);
    $created = verificationCreateUploadIntent($onboarded, (string) $opened->caseId, 'admin-canon-'.$key);
    $created['response']->assertCreated();
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], $created['mime']);
    test()->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('admin-canon-done-'.$key),
    )->assertOk();
    verificationProcessUpload($created['upload_id']);

    $intent = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
    assert($intent !== null);
    $documentId = (string) DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->value('id');
    expect($documentId)->not->toBe('')
        ->and((string) $intent->state)->toBe('available')
        ->and((string) $intent->canonical_storage_locator)->toStartWith('verification/c/');

    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('admin-canon-sub-'.$key),
    )->assertOk();

    return [
        'session' => $onboarded['session'],
        'actor' => $onboarded['actor'],
        'doctor_id' => $onboarded['doctor_id'],
        'case_id' => (string) $opened->caseId,
        'case_version' => (int) $opened->caseVersion + 1,
        'profile_version' => $opened->profileVersion + 1,
        'object_id' => $created['object_id'],
        'document_id' => $documentId,
        'upload_id' => $created['upload_id'],
        'storage_locator' => (string) $intent->storage_locator,
        'canonical_storage_locator' => (string) $intent->canonical_storage_locator,
        'national_id' => $onboarded['national_id'],
    ];
}

function adminVerificationWrapObjectStore(): RecordingStoreObject
{
    $recording = new RecordingStoreObject(app(StoreObject::class));
    app()->instance(StoreObject::class, $recording);

    return $recording;
}

function adminVerificationVersionedCursor(string $reviewerUserId, array $position, int $version, array $filters = []): string
{
    $scope = CursorScope::of(
        AdminVerificationReviewService::QUEUE_OPERATION,
        $reviewerUserId,
        null,
        [
            'assignment' => (string) ($filters['assignment'] ?? 'unassigned'),
            'case_type' => (string) ($filters['case_type'] ?? 'doctor_verification'),
            'status' => (string) ($filters['status'] ?? 'pending_review'),
        ],
        ReviewerQueueFilters::ORDERING,
    );
    $payload = rtrim(strtr(base64_encode(json_encode([
        's' => $scope->hash(),
        'k' => $position,
        'v' => $version,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, (string) config('app.key'), true)), '+/', '-_'), '=');

    return $payload.'.'.$mac;
}
