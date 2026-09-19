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
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\ApplicantCaseProjection;

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
    $specialty = doctorsSeedSpecialty('gp_ver_'.$key);
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
    ActorContext $actor,
    string $caseId,
    string $status = 'available',
    string $scanStatus = 'clean',
    string $requirement = 'professional_id',
): array {
    $ids = app(IdentityGenerator::class);
    $objectId = $ids->next()->value;
    $sha = hash('sha256', 'synthetic-verification-bytes-'.$objectId);
    $row = app(VerificationDocumentService::class)->registerValidatedMetadata(
        $actor,
        Identifier::fromTrusted($caseId),
        [
            'requirement_code' => $requirement,
            'object_id' => $objectId,
            'sha256' => $sha,
            'detected_mime' => 'application/pdf',
            'size_bytes' => 2048,
            'scan_status' => $scanStatus,
            'status' => $status,
        ],
    );

    return [
        'document_id' => $row->documentId,
        'object_id' => $objectId,
        'sha256' => $sha,
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
    $document = verificationRegisterDocument($onboarded['actor'], (string) $opened->caseId);

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
