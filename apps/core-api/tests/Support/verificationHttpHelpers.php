<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Services\VerificationUploadProcessor;
use Modules\Verification\Support\ApplicantCaseProjection;
use Modules\Verification\Support\VerificationPolicy;
use Tests\Support\FixtureScanObject;
use Tests\Support\TestingTrustedDocumentEvidenceIssuer;

function verificationDoctorActor(string $userId): ActorContext
{
    return new ActorContext(
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
}

function verificationOperatorActor(string $userId, AssuranceLevel $assurance = AssuranceLevel::Aal2Totp): ActorContext
{
    return new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Admin,
        AccountStatus::Active,
        LanguagePreference::English,
        $assurance,
        1,
        null,
        Identifier::fromTrusted($userId),
        [],
        Capabilities::forActor('admin', true),
    );
}

/**
 * @return array{user_id: string, actor: ActorContext}
 */
function verificationSeedAdmin(string $key = 'verifier'): array
{
    $admin = User::factory()->create([
        'name' => 'Synthetic Verifier '.$key,
        'account_type' => AccountType::Admin->value,
        'status' => AccountStatus::Active->value,
    ]);

    return [
        'user_id' => (string) $admin->id,
        'actor' => verificationOperatorActor((string) $admin->id),
    ];
}

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     specialty_id: string,
 *     doctor_id: string,
 *     actor: ActorContext,
 *     national_id: string
 * }
 */
function verificationOnboardDoctor(string $key, ?string $syndicate = null): array
{
    $code = 'gp_ver_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($key));
    $specialty = doctorsSeedSpecialty($code);
    $session = doctorsActiveSession($key);
    $body = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id'], $syndicate);
    $response = test()->postJson(
        '/api/v1/doctors/onboarding',
        $body,
        doctorsAuth($session['token']) + doctorsIdem('ver-onboard-'.$key),
    );
    $response->assertCreated();

    return [
        'session' => $session,
        'specialty_id' => $specialty['id'],
        'doctor_id' => (string) $response->json('data.doctor_id'),
        'actor' => verificationDoctorActor($session['user_id']),
        'national_id' => $session['payload']['national_id'],
    ];
}

function verificationOpenCase(ActorContext $actor): ApplicantCaseProjection
{
    return app(VerificationService::class)->openDoctorCase($actor);
}

/**
 * @return array{document_id: string, object_id: string, sha256: string}
 */
function verificationRegisterDocument(
    string $caseId,
    string $status = 'available',
    string $scanStatus = 'clean',
    string $requirement = 'professional_id',
): array {
    $ids = app(IdentityGenerator::class);
    $objectId = $ids->next()->value;
    $sha = hash('sha256', 'synthetic-verification-bytes-'.$objectId);
    $evidence = (new TestingTrustedDocumentEvidenceIssuer(app(VerificationPolicy::class)))->issue([
        'case_id' => $caseId,
        'requirement_code' => $requirement,
        'object_id' => $objectId,
        'sha256' => $sha,
        'detected_mime' => 'application/pdf',
        'size_bytes' => 2048,
        'scan_status' => $scanStatus,
        'status' => $status,
    ]);
    $row = app(VerificationDocumentService::class)->registerValidatedMetadata($evidence);

    return [
        'document_id' => $row->documentId,
        'object_id' => $objectId,
        'sha256' => $sha,
    ];
}

/**
 * @return array{admin: array{user_id: string, actor: ActorContext}, version: int}
 */
function verificationClaimPending(array $draft, string $adminKey): array
{
    $admin = verificationSeedAdmin($adminKey);
    $claimed = app(VerificationService::class)->claimCase(
        $admin['actor'],
        Identifier::fromTrusted($draft['case_id']),
        $draft['case_version'] + 1,
    );

    return [
        'admin' => $admin,
        'version' => $claimed->version,
    ];
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
function verificationPrepareDraftWithAvailableDocument(string $key): array
{
    $onboarded = verificationOnboardDoctor($key);
    $opened = verificationOpenCase($onboarded['actor']);
    expect($opened->caseId)->toBeString();
    $document = verificationRegisterDocument((string) $opened->caseId);

    return [
        'session' => $onboarded['session'],
        'actor' => $onboarded['actor'],
        'doctor_id' => $onboarded['doctor_id'],
        'case_id' => (string) $opened->caseId,
        'case_version' => (int) $opened->caseVersion,
        'profile_version' => $opened->profileVersion,
        'object_id' => $document['object_id'],
        'national_id' => $onboarded['national_id'],
    ];
}

function verificationSubmitBody(int $caseVersion, int $profileVersion): array
{
    return [
        'case_version' => $caseVersion,
        'profile_version' => $profileVersion,
    ];
}

function verificationOutboxPayload(string $eventType): ?string
{
    $row = DB::table('outbox_events')->where('event_type', $eventType)->orderByDesc('occurred_at')->first();
    if ($row === null) {
        return null;
    }

    return is_string($row->payload) ? $row->payload : json_encode($row->payload);
}

function verificationBindCleanScanner(): void
{
    test()->app->instance(ScanObject::class, new FixtureScanObject);
}

/**
 * @return array{upload_id: string, object_id: string, storage_locator: string, body: string}
 */
function verificationCreateUploadIntent(array $onboarded, string $caseId, string $idem, ?string $mime = 'application/pdf', int $size = 0, array $extra = []): array
{
    $bytes = match ($mime) {
        'image/png' => verificationMinimalPng(),
        'image/jpeg' => verificationMinimalJpeg(),
        default => verificationMinimalPdf(),
    };
    $expected = $size > 0 ? $size : strlen($bytes);
    $payload = array_merge([
        'case_id' => $caseId,
        'requirement_code' => 'professional_id',
        'expected_size_bytes' => $expected,
        'declared_media_type' => $mime,
    ], $extra);

    $response = test()->postJson(
        '/api/v1/verification-uploads',
        $payload,
        doctorsAuth($onboarded['session']['token']) + doctorsIdem($idem),
    );

    $uploadId = (string) $response->json('data.upload_id');
    $row = $uploadId !== ''
        ? DB::table('verification_upload_intents')->where('id', $uploadId)->first()
        : null;

    return [
        'response' => $response,
        'upload_id' => $uploadId,
        'object_id' => is_object($row) ? (string) $row->object_id : '',
        'storage_locator' => is_object($row) ? (string) $row->storage_locator : '',
        'bytes' => $bytes,
        'mime' => $mime,
    ];
}

function verificationPutUploadBytes(string $uploadId, string $bytes, string $mime): void
{
    $row = DB::table('verification_upload_intents')->where('id', $uploadId)->first();
    assert($row !== null);
    $ref = new StoredObjectRef('verification', (string) $row->object_id, (string) $row->storage_locator);
    app(StoreObject::class)->writeAt($ref, $mime, $bytes);
}

function verificationProcessUpload(string $uploadId): void
{
    app(VerificationUploadProcessor::class)->process(Identifier::fromTrusted($uploadId));
}

function verificationStoredRef(string $uploadId): StoredObjectRef
{
    $row = DB::table('verification_upload_intents')->where('id', $uploadId)->first();
    assert($row !== null);

    return new StoredObjectRef('verification', (string) $row->object_id, (string) $row->storage_locator);
}
