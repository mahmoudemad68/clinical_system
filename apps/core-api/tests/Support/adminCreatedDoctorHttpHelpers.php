<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Clinics\Services\CreateClinicLocation;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Services\VerificationUploadProcessor;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adminCreatedDoctorApplicantBody(array $overrides = []): array
{
    $identity = doctorsSyntheticIdentity();
    $specialtyId = isset($overrides['specialty_id']) && is_string($overrides['specialty_id'])
        ? $overrides['specialty_id']
        : (string) doctorsSeedSpecialty('general_practice')['id'];

    return array_merge([
        'phone' => $identity['phone'],
        'national_id' => $identity['national_id'],
        'professional_display_name' => 'Dr Admin Created',
        'specialty_id' => $specialtyId,
        'password' => 'correct-horse-battery',
        'evidence_source' => 'in_person_originals',
    ], $overrides);
}

/**
 * @return array{token: string, user_id: string, totp_secret: string}
 */
function adminCreatedDoctorAttachTotpAndLogin(string $userId, string $phone, string $password, string $key): array
{
    clinicClearBrowserSession();
    if (app()->bound('session')) {
        session()->flush();
    }

    $protector = app(NationalIdProtector::class);
    $totp = app(TotpVerifier::class);
    $ids = app(IdentityGenerator::class);
    $now = now('UTC');
    $secret = $totp->generateSecret();

    expect(DB::table('mfa_factors')->where('user_id', $userId)->whereNull('disabled_at')->count())->toBe(0);

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

    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'doctor_desktop',
        'platform' => 'linux',
        'device_label' => 'clinic-pc-admin-created-'.$key,
    ]);
    $login->assertOk()->assertJsonPath('data.status', 'mfa_required');

    $code = $totp->codeAt($secret, app(Clock::class)->now());
    $session = test()->postJson('/api/v1/auth/mfa/challenges/'.$login->json('data.challenge_id').'/verify', [
        'code' => $code,
    ]);
    $session->assertOk();
    $token = $session->json('data.access_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [
        'token' => $token,
        'user_id' => $userId,
        'totp_secret' => $secret,
    ];
}

/**
 * @return array{upload_id: string}
 */
function adminCreatedDoctorAttachEvidenceAndSubmit(
    string $doctorId,
    string $caseId,
    int $caseVersion,
    int $profileVersion,
    string $key,
): array {
    verificationBindCleanScanner();
    $bytes = verificationMinimalPdf();
    $uploadId = '';
    foreach (['medical_license', 'national_id_or_passport'] as $code) {
        $created = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-uploads',
            [
                'case_id' => $caseId,
                'requirement_code' => $code,
                'expected_size_bytes' => strlen($bytes),
                'declared_media_type' => 'application/pdf',
            ],
            adminVerificationIdem('acd-up-'.$key.'-'.$code),
        );
        $created->assertCreated();
        $uploadId = (string) $created->json('data.upload_id');
        expect($uploadId)->not->toBe('');

        $row = DB::table('verification_upload_intents')->where('id', $uploadId)->first();
        assert($row !== null);
        app(StoreObject::class)->writeAt(
            new StoredObjectRef('verification', (string) $row->object_id, (string) $row->storage_locator),
            'application/pdf',
            $bytes,
        );

        adminVerificationPostJson(
            '/api/v1/verification-uploads/'.$uploadId.'/complete',
            [],
            adminVerificationIdem('acd-done-'.$key.'-'.$code),
        )->assertOk();
        app(VerificationUploadProcessor::class)->process(Identifier::fromTrusted($uploadId));
    }

    adminVerificationPostJson(
        '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-submissions',
        verificationSubmitBody($caseVersion, $profileVersion),
        adminVerificationIdem('acd-sub-'.$key),
    )->assertOk();

    return ['upload_id' => $uploadId];
}

function adminCreatedDoctorUserId(string $doctorId): string
{
    $userId = (string) DB::table('doctor_profiles')->where('id', $doctorId)->value('user_id');
    expect($userId)->not->toBe('');

    return $userId;
}

/**
 * Exercise the public Clinics CreateClinicLocation service as an AAL2 doctor.
 * Avoids HTTP TOTP login so Admin cookie sessions stay isolated.
 */
function adminCreatedDoctorProbeClinicCreate(string $userId, string $publicName): int
{
    $actor = new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Doctor,
        AccountStatus::Active,
        LanguagePreference::English,
        AssuranceLevel::Aal2Totp,
        1,
        null,
        null,
        [],
        Capabilities::AUTHENTICATED_SELF,
    );

    try {
        app(CreateClinicLocation::class)->handle($actor, clinicLocationBody($publicName));

        return 201;
    } catch (AuthorizationDenied|FeatureUnavailable) {
        return 404;
    }
}
