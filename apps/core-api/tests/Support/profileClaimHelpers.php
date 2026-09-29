<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Patients\Support\PatientHandle;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Support\Identifier;

/**
 * Isolated non-production enablement. Production hard-off is never removed.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function profileClaimIsolatedConfig(array $extra = []): array
{
    return array_merge([
        'identity.profile_claim_enabled' => true,
        'app.env' => 'testing',
        'identity.profile_claim.ceremony_starts_per_account_per_hour' => 1000,
        'identity.profile_claim.ceremony_starts_per_ip_per_hour' => 1000,
        'identity.profile_claim.ceremony_starts_per_nid_hmac_per_hour' => 1000,
    ], $extra);
}

/**
 * Isolated non-production enablement. Production hard-off is never removed.
 *
 * @param  array<string, mixed>  $extra
 */
function profileClaimEnableIsolated(array $extra = []): void
{
    cache()->store((string) config('cache.auth_rate_limiter', 'ratelimit'))->flush();

    config(profileClaimIsolatedConfig($extra));
}

function profileClaimDisableIsolated(): void
{
    config([
        'identity.profile_claim_enabled' => false,
        'app.env' => 'testing',
    ]);
}

function profileClaimIssueWalkIn(string $nationalId): PatientHandle
{
    return app(CreateUnlinkedPatientProfile::class)->handle(
        patientsUnlinkedActor(),
        patientsDemographics($nationalId, 'Walk In'),
        patientsCorrelationId(),
    );
}

/**
 * @param  array{token: string, payload: array<string, string>, user_id: string}  $session
 */
function profileClaimConsumeOtp(array $session, string $suffix): void
{
    $request = test()->postJson('/api/v1/auth/otp-requests', [
        'phone' => $session['payload']['phone'],
        'purpose' => 'profile_claim',
        'language' => 'en',
    ], patientsAuth($session['token']) + patientsIdem('otp-claim-req-'.$suffix));
    $request->assertOk()->assertJsonPath('data.status', 'otp_required');

    app(OutboxDispatcher::class)->dispatchBatch();

    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);
    $code = $sms->lastCodeByPurpose['profile_claim'] ?? null;
    expect($code)->toBeString()->not->toBeEmpty();

    $challengeId = (string) DB::table('otp_requests')->where('purpose', 'profile_claim')->orderByDesc('created_at')->value('id');

    $verify = test()->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => $challengeId,
        'code' => $code,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'claim-'.$suffix,
    ], patientsIdem('otp-claim-ver-'.$suffix));

    $verify->assertOk()
        ->assertJsonPath('data.status', 'otp_verified')
        ->assertJsonMissingPath('data.access_token')
        ->assertJsonMissingPath('data.patient_id');
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function profileClaimOnboardingBody(string $nationalId, ?string $claimCredential = null): array
{
    $body = patientsDemographics($nationalId);
    if (is_string($claimCredential) && $claimCredential !== '') {
        $body['claim_credential'] = $claimCredential;
    }

    return $body;
}

/**
 * @param  array{token: string, payload: array<string, string>, user_id: string}  $session
 * @return array{session: array{token: string, payload: array<string, string>, user_id: string}, nid: string, credential: string, patient_id: string}
 */
function profileClaimPrepareFourFactor(string $suffix): array
{
    $session = patientsActiveSession($suffix);
    $nid = $session['payload']['national_id'];
    $handle = profileClaimIssueWalkIn($nid);
    expect($handle->claimCredential)->toBeString()
        ->and(strlen((string) $handle->claimCredential))->toBe(16);
    profileClaimConsumeOtp($session, $suffix);

    return [
        'session' => $session,
        'nid' => $nid,
        'credential' => (string) $handle->claimCredential,
        'patient_id' => $handle->patientId->value,
    ];
}

function profileClaimAssertGenericPending(mixed $response, string $nationalId, ?string $credential = null): void
{
    $response->assertOk()
        ->assertJsonPath('data.status', 'manual_review_required')
        ->assertJsonMissingPath('data.patient_id')
        ->assertJsonMissingPath('data.profile');
    expect($response->json('errors'))->toBeArray()->toBeEmpty();

    $body = (string) $response->getContent();
    expect($body)->not->toContain($nationalId)
        ->and($body)->not->toContain('wrong_claim_code')
        ->and($body)->not->toContain('profile_exists')
        ->and($body)->not->toContain('profile_already_linked')
        ->and($body)->not->toContain('claim_code_expired')
        ->and($body)->not->toContain('national_id_not_found');

    if (is_string($credential) && $credential !== '') {
        expect($body)->not->toContain($credential)
            ->and($body)->not->toContain(str_replace('-', '', $credential));
    }
}

function profileClaimOperatorActor(): ActorContext
{
    $admin = User::factory()->create([
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);

    return new ActorContext(
        Identifier::fromTrusted((string) $admin->id),
        AccountType::Admin,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal2Totp,
        1,
        null,
        Identifier::fromTrusted((string) $admin->id),
        [],
        Capabilities::forActor('admin', true),
    );
}
