<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\PharmacyVerificationCaseOutcome;

function pharmacyVerificationActor(string $userId): ActorContext
{
    return new ActorContext(
        Identifier::fromTrusted($userId),
        AccountType::Pharmacy,
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

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     organization_id: string,
 *     branch_id: string,
 *     membership_id: string,
 *     organization_version: int,
 *     actor: ActorContext,
 *     legal_name: string,
 *     registration: string,
 *     address: string,
 *     phone: string
 * }
 */
function pharmacyVerificationOnboard(string $key): array
{
    $session = pharmaciesActiveSession($key);
    $legalName = 'Synthetic Pharmacy '.$key.' LLC';
    $address = '12 Test Street, Cairo '.$key;
    $body = pharmaciesOnboardingBody(
        $session['payload']['registration'],
        $session['payload']['phone'],
        publicName: 'Synthetic Pharmacy '.$key,
        legalName: $legalName,
        address: $address,
    );
    $response = test()->postJson(
        '/api/v1/pharmacy-organizations/onboarding',
        $body,
        pharmaciesAuth($session['token']) + pharmaciesIdem('pver-onboard-'.$key),
    );
    $response->assertCreated();

    return [
        'session' => $session,
        'organization_id' => (string) $response->json('data.organization_id'),
        'branch_id' => (string) $response->json('data.branch_id'),
        'membership_id' => (string) $response->json('data.membership_id'),
        'organization_version' => (int) $response->json('data.version'),
        'actor' => pharmacyVerificationActor($session['user_id']),
        'legal_name' => $legalName,
        'registration' => $session['payload']['registration'],
        'address' => $address,
        'phone' => $session['payload']['phone'],
    ];
}

function pharmacyVerificationOpenHttp(array $onboarded, string $idem): TestResponse
{
    return test()->postJson(
        '/api/v1/pharmacy-organizations/me/verification-cases',
        [],
        pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem($idem),
    );
}

function pharmacyVerificationOpenCase(ActorContext $actor): PharmacyVerificationCaseOutcome
{
    return app(VerificationService::class)->openPharmacyCase($actor);
}

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     actor: ActorContext,
 *     organization_id: string,
 *     branch_id: string,
 *     membership_id: string,
 *     case_id: string,
 *     case_version: int,
 *     organization_version: int,
 *     object_id: string,
 *     legal_name: string,
 *     registration: string,
 *     address: string,
 *     phone: string
 * }
 */
function pharmacyVerificationPrepareDraftWithAvailableDocument(string $key): array
{
    $onboarded = pharmacyVerificationOnboard($key);
    $opened = pharmacyVerificationOpenCase($onboarded['actor']);
    $document = verificationRegisterRequiredPharmacyDocuments($opened->caseId)[0];

    return [
        'session' => $onboarded['session'],
        'actor' => $onboarded['actor'],
        'organization_id' => $onboarded['organization_id'],
        'branch_id' => $onboarded['branch_id'],
        'membership_id' => $onboarded['membership_id'],
        'case_id' => $opened->caseId,
        'case_version' => $opened->caseVersion,
        'organization_version' => $opened->organizationVersion,
        'object_id' => $document['object_id'],
        'legal_name' => $onboarded['legal_name'],
        'registration' => $onboarded['registration'],
        'address' => $onboarded['address'],
        'phone' => $onboarded['phone'],
    ];
}

/**
 * @return array{case_version: int, organization_version: int}
 */
function pharmacyVerificationSubmitBody(int $caseVersion, int $organizationVersion): array
{
    return [
        'case_version' => $caseVersion,
        'organization_version' => $organizationVersion,
    ];
}

/**
 * @return array{response: TestResponse, upload_id: string, object_id: string, storage_locator: string, bytes: string, mime: string}
 */
function pharmacyVerificationCreateUploadIntent(array $onboarded, string $caseId, string $idem, ?string $mime = 'application/pdf', int $size = 0, array $extra = []): array
{
    $bytes = match ($mime) {
        'image/png' => verificationMinimalPng(),
        'image/jpeg' => verificationMinimalJpeg(),
        default => verificationMinimalPdf(),
    };
    $expected = $size > 0 ? $size : strlen($bytes);
    $payload = array_merge([
        'case_id' => $caseId,
        'requirement_code' => 'pharmacy_facility_license',
        'expected_size_bytes' => $expected,
        'declared_media_type' => $mime,
    ], $extra);

    $response = test()->postJson(
        '/api/v1/verification-uploads',
        $payload,
        pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem($idem),
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

/**
 * @param  list<string>  $codes
 */
function pharmacyVerificationUploadAndProcessRequirements(
    array $onboarded,
    string $caseId,
    string $idemPrefix,
    array $codes,
): void {
    foreach ($codes as $code) {
        $created = pharmacyVerificationCreateUploadIntent(
            $onboarded,
            $caseId,
            $idemPrefix.'-'.$code,
            extra: ['requirement_code' => $code],
        );
        $created['response']->assertCreated();
        verificationPutUploadBytes($created['upload_id'], $created['bytes'], $created['mime']);
        test()->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem($idemPrefix.'-done-'.$code),
        )->assertOk();
        verificationProcessUpload($created['upload_id']);
    }
}

function pharmacyVerificationAssertNoCanaries(string $haystack, array $onboarded): void
{
    expect($haystack)->not->toContain($onboarded['legal_name'])
        ->and($haystack)->not->toContain($onboarded['registration'])
        ->and($haystack)->not->toContain($onboarded['address'])
        ->and($haystack)->not->toContain($onboarded['phone'])
        ->and($haystack)->not->toContain('legal_registration')
        ->and($haystack)->not->toContain('registration_ciphertext')
        ->and($haystack)->not->toContain('registration_lookup_hmac');
}

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     actor: ActorContext,
 *     organization_id: string,
 *     branch_id: string,
 *     membership_id: string,
 *     case_id: string,
 *     case_version: int,
 *     organization_version: int,
 *     object_id: string,
 *     legal_name: string,
 *     registration: string,
 *     address: string,
 *     phone: string
 * }
 */
function pharmacyVerificationPendingCase(string $key): array
{
    $draft = pharmacyVerificationPrepareDraftWithAvailableDocument($key);
    test()->postJson(
        '/api/v1/pharmacy-organizations/me/verification-submissions',
        pharmacyVerificationSubmitBody($draft['case_version'], $draft['organization_version']),
        pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-sub-'.$key),
    )->assertOk();

    $draft['case_version'] = $draft['case_version'] + 1;
    $draft['organization_version'] = $draft['organization_version'] + 1;

    return $draft;
}

/**
 * @return array{
 *     session: array{token: string, payload: array<string, string>, user_id: string, totp_secret: string},
 *     actor: ActorContext,
 *     organization_id: string,
 *     branch_id: string,
 *     membership_id: string,
 *     case_id: string,
 *     case_version: int,
 *     organization_version: int,
 *     object_id: string,
 *     document_id: string,
 *     upload_id: string,
 *     storage_locator: string,
 *     canonical_storage_locator: string,
 *     legal_name: string,
 *     registration: string,
 *     address: string,
 *     phone: string
 * }
 */
function pharmacyVerificationPendingCanonicalCase(string $key): array
{
    verificationBindCleanScanner();
    $onboarded = pharmacyVerificationOnboard($key);
    $opened = pharmacyVerificationOpenCase($onboarded['actor']);
    $created = pharmacyVerificationCreateUploadIntent($onboarded, $opened->caseId, 'pver-canon-'.$key);
    $created['response']->assertCreated();
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], $created['mime']);
    test()->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-canon-done-'.$key),
    )->assertOk();
    verificationProcessUpload($created['upload_id']);
    pharmacyVerificationUploadAndProcessRequirements(
        $onboarded,
        $opened->caseId,
        'pver-canon-'.$key.'-rest',
        ['commercial_register', 'responsible_pharmacist_license'],
    );

    $intent = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
    assert($intent !== null);
    $documentId = (string) DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->value('id');
    expect($documentId)->not->toBe('')
        ->and((string) $intent->state)->toBe('available')
        ->and((string) $intent->canonical_storage_locator)->toStartWith('verification/c/');

    test()->postJson(
        '/api/v1/pharmacy-organizations/me/verification-submissions',
        pharmacyVerificationSubmitBody($opened->caseVersion, $opened->organizationVersion),
        pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-canon-sub-'.$key),
    )->assertOk();

    return [
        'session' => $onboarded['session'],
        'actor' => $onboarded['actor'],
        'organization_id' => $onboarded['organization_id'],
        'branch_id' => $onboarded['branch_id'],
        'membership_id' => $onboarded['membership_id'],
        'case_id' => $opened->caseId,
        'case_version' => $opened->caseVersion + 1,
        'organization_version' => $opened->organizationVersion + 1,
        'object_id' => $created['object_id'],
        'document_id' => $documentId,
        'upload_id' => $created['upload_id'],
        'storage_locator' => (string) $intent->storage_locator,
        'canonical_storage_locator' => (string) $intent->canonical_storage_locator,
        'legal_name' => $onboarded['legal_name'],
        'registration' => $onboarded['registration'],
        'address' => $onboarded['address'],
        'phone' => $onboarded['phone'],
    ];
}

/**
 * @return list<string>
 */
function pharmacyVerificationForbiddenCapabilities(): array
{
    return [
        'inventory.adjust',
        'pos.sale.complete',
        'purchasing.order.create',
        'catalog.medication.publish',
        'clinical.record.read',
        'pharmacy.inventory.manage',
        'pharmacy.pos.operate',
        'pharmacy.purchasing.manage',
    ];
}

/**
 * @return array{admin: array{user_id: string, actor: ActorContext}, version: int}
 */
function pharmacyVerificationClaimPending(array $pending, string $adminKey): array
{
    $admin = verificationSeedAdmin($adminKey);
    $claimed = app(VerificationService::class)->claimCase(
        $admin['actor'],
        Identifier::fromTrusted($pending['case_id']),
        $pending['case_version'],
    );

    return [
        'admin' => $admin,
        'version' => $claimed->version,
    ];
}

function pharmacyVerificationAssertAggregate(
    string $organizationId,
    string $verificationStatus,
    string $organizationStatus,
    string $branchStatus,
    string $membershipStatus,
): void {
    expect((string) DB::table('pharmacy_organizations')->where('id', $organizationId)->value('verification_status'))->toBe($verificationStatus)
        ->and((string) DB::table('pharmacy_organizations')->where('id', $organizationId)->value('status'))->toBe($organizationStatus)
        ->and((string) DB::table('pharmacy_branches')->where('organization_id', $organizationId)->value('status'))->toBe($branchStatus)
        ->and((string) DB::table('pharmacy_memberships')->where('organization_id', $organizationId)->value('status'))->toBe($membershipStatus);
}
