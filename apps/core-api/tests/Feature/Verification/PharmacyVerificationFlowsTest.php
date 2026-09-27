<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('pharmacy verification HTTP foundation', function () {
    it('opens or resumes the founding owner draft case without client-supplied identity', function () {
        $onboarded = pharmacyVerificationOnboard('open-own');
        $opened = pharmacyVerificationOpenHttp($onboarded, 'pver-open-own');
        $opened->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.organization_id', $onboarded['organization_id'])
            ->assertJsonPath('data.case_status', 'draft')
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.reviewer_id')
            ->assertJsonMissingPath('data.applicant_id')
            ->assertJsonMissingPath('data.case_type');

        pharmacyVerificationAssertNoCanaries((string) $opened->getContent(), $onboarded);
        expect(strlen((string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference')))->toBeLessThanOrEqual(255);

        $resume = pharmacyVerificationOpenHttp($onboarded, 'pver-open-own-2');
        $resume->assertOk()
            ->assertJsonPath('data.case_id', $opened->json('data.case_id'))
            ->assertJsonPath('data.case_status', 'draft');

        expect(DB::table('verification_cases')->count())->toBe(1)
            ->and((string) DB::table('verification_cases')->value('applicant_type'))->toBe('pharmacy')
            ->and((string) DB::table('verification_cases')->value('case_type'))->toBe('pharmacy_verification')
            ->and((string) DB::table('verification_cases')->value('applicant_id'))->toBe($onboarded['organization_id']);

        $status = $this->getJson(
            '/api/v1/pharmacy-organizations/me/verification-status',
            pharmaciesAuth($onboarded['session']['token']),
        );
        $status->assertOk()
            ->assertJsonPath('data.applicant_type', 'pharmacy')
            ->assertJsonPath('data.organization_id', $onboarded['organization_id'])
            ->assertJsonPath('data.case_id', $opened->json('data.case_id'))
            ->assertJsonPath('data.case_status', 'draft')
            ->assertJsonPath('data.organization_verification_status', PharmacyVerificationStatus::Draft->value)
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.object_id');
        pharmacyVerificationAssertNoCanaries((string) $status->getContent(), $onboarded);
    });

    it('returns 401 without a session and 404 for doctor or patient actors', function () {
        $this->getJson('/api/v1/pharmacy-organizations/me/verification-status')->assertUnauthorized();
        $this->postJson('/api/v1/pharmacy-organizations/me/verification-cases', [], pharmaciesIdem('pver-unauth'))
            ->assertUnauthorized();

        $doctor = verificationOnboardDoctor('pver-doctor');
        $this->getJson('/api/v1/pharmacy-organizations/me/verification-status', doctorsAuth($doctor['session']['token']))
            ->assertNotFound();
        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-cases',
            [],
            doctorsAuth($doctor['session']['token']) + pharmaciesIdem('pver-doctor-open'),
        )->assertNotFound();

        $patient = patientsActiveSession('pver-patient');
        $this->getJson('/api/v1/pharmacy-organizations/me/verification-status', [
            'Authorization' => 'Bearer '.$patient['token'],
        ])->assertNotFound();
    });

    it('does not expose another pharmacy case on own-status or invented lookup routes', function () {
        $left = pharmacyVerificationPrepareDraftWithAvailableDocument('bola-l');
        $right = pharmacyVerificationOnboard('bola-r');

        $own = $this->getJson(
            '/api/v1/pharmacy-organizations/me/verification-status',
            pharmaciesAuth($right['session']['token']),
        );
        $own->assertOk();
        expect($own->json('data.case_id'))->not->toBe($left['case_id'])
            ->and($own->json('data.organization_id'))->toBe($right['organization_id']);

        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$left['organization_id'].'/verification-status',
            pharmaciesAuth($right['session']['token']),
        )->assertNotFound();
        $this->getJson('/api/v1/verification-cases/'.$left['case_id'], pharmaciesAuth($right['session']['token']))
            ->assertNotFound();
        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => $left['case_id'],
            'requirement_code' => 'pharmacy_facility_license',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], pharmaciesAuth($right['session']['token']) + pharmaciesIdem('pver-upload-bola'))
            ->assertNotFound();
        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($left['case_version'], $left['organization_version']),
            pharmaciesAuth($right['session']['token']) + pharmaciesIdem('pver-sub-bola'),
        )->assertNotFound();
    });

    it('rejects mass assignment of applicant, case, status, reviewer, and capability fields', function () {
        $onboarded = pharmacyVerificationOnboard('mass-open');
        $this->postJson('/api/v1/pharmacy-organizations/me/verification-cases', [
            'applicant_id' => $onboarded['organization_id'],
            'organization_id' => $onboarded['organization_id'],
            'case_type' => 'pharmacy_verification',
            'applicant_type' => 'pharmacy',
            'status' => 'approved',
            'reviewer_id' => $onboarded['session']['user_id'],
            'membership_role' => 'owner',
        ], pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-mass-open'))
            ->assertStatus(422);

        $draft = pharmacyVerificationPrepareDraftWithAvailableDocument('mass-sub');
        $this->postJson('/api/v1/pharmacy-organizations/me/verification-submissions', [
            'case_version' => $draft['case_version'],
            'organization_version' => $draft['organization_version'],
            'organization_id' => $draft['organization_id'],
            'applicant_type' => 'pharmacy',
            'case_type' => 'pharmacy_verification',
            'verification_status' => 'approved',
            'organization_status' => 'active',
            'branch_status' => 'active',
            'membership_status' => 'active',
            'reviewer_id' => $draft['session']['user_id'],
            'capabilities' => ['inventory.adjust'],
        ], pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-mass-sub'))
            ->assertStatus(422);
    });
});

describe('pharmacy upload isolation', function () {
    it('rejects a doctor requirement on a pharmacy case and a pharmacy requirement on a doctor case', function () {
        $pharmacy = pharmacyVerificationOnboard('req-pharm');
        $opened = pharmacyVerificationOpenCase($pharmacy['actor']);
        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], pharmaciesAuth($pharmacy['session']['token']) + pharmaciesIdem('pver-req-doc'))
            ->assertStatus(422);

        $doctor = verificationOnboardDoctor('req-doc');
        $doctorCase = verificationOpenCase($doctor['actor']);
        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $doctorCase->caseId,
            'requirement_code' => 'pharmacy_facility_license',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($doctor['session']['token']) + doctorsIdem('pver-req-pharm'))
            ->assertStatus(422);
    });

    it('denies a doctor uploading to a pharmacy case and a pharmacy uploading to a doctor case', function () {
        $pharmacy = pharmacyVerificationPrepareDraftWithAvailableDocument('cross-p');
        $doctor = verificationOnboardDoctor('cross-d');
        $doctorCase = verificationOpenCase($doctor['actor']);

        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => $pharmacy['case_id'],
            'requirement_code' => 'pharmacy_facility_license',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($doctor['session']['token']) + doctorsIdem('pver-doc-on-pharm'))
            ->assertNotFound();

        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $doctorCase->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], pharmaciesAuth($pharmacy['session']['token']) + pharmaciesIdem('pver-pharm-on-doc'))
            ->assertNotFound();
    });
});

describe('pharmacy verification lifecycle', function () {
    it('runs the trusted scanner path then submits, approves, and activates only identity state', function () {
        verificationBindCleanScanner();
        $onboarded = pharmacyVerificationOnboard('e2e');
        $opened = pharmacyVerificationOpenHttp($onboarded, 'pver-e2e-open')->assertOk();
        $caseId = (string) $opened->json('data.case_id');
        $caseVersion = (int) $opened->json('data.case_version');
        $organizationVersion = (int) $opened->json('data.organization_version');

        $created = pharmacyVerificationCreateUploadIntent($onboarded, $caseId, 'pver-e2e-up');
        $created['response']->assertCreated()
            ->assertJsonPath('data.requirement_code', 'pharmacy_facility_license')
            ->assertJsonPath('data.state', 'uploading')
            ->assertJsonMissingPath('data.storage_locator')
            ->assertJsonMissingPath('data.object_id');
        pharmacyVerificationAssertNoCanaries((string) $created['response']->getContent(), $onboarded);
        expect((string) $created['storage_locator'])->toStartWith('verification/q/');

        verificationPutUploadBytes($created['upload_id'], $created['bytes'], $created['mime']);
        $this->postJson(
            '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
            [],
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-e2e-done'),
        )->assertOk()->assertJsonPath('data.state', 'quarantined');

        verificationProcessUpload($created['upload_id']);
        $intent = DB::table('verification_upload_intents')->where('id', $created['upload_id'])->first();
        $document = DB::table('verification_documents')->where('upload_intent_id', $created['upload_id'])->first();
        expect((string) $intent->state)->toBe('available')
            ->and((string) $intent->canonical_storage_locator)->toStartWith('verification/c/')
            ->and((string) $document->status)->toBe('available')
            ->and((string) $document->scan_status)->toBe('clean')
            ->and((string) $document->requirement_code)->toBe('pharmacy_facility_license');

        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($caseVersion, $organizationVersion),
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-e2e-sub-partial'),
        )->assertStatus(422);

        pharmacyVerificationUploadAndProcessRequirements(
            $onboarded,
            $caseId,
            'pver-e2e-rest',
            ['commercial_register', 'responsible_pharmacist_license'],
        );

        $submit = $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($caseVersion, $organizationVersion),
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-e2e-sub'),
        );
        $submit->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.case_status', 'pending_review')
            ->assertJsonPath('data.organization_verification_status', PharmacyVerificationStatus::PendingReview->value)
            ->assertJsonMissingPath('data.documents')
            ->assertJsonMissingPath('data.object_id');
        pharmacyVerificationAssertNoCanaries((string) $submit->getContent(), $onboarded);
        expect(strlen((string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference')))->toBeLessThanOrEqual(255);

        pharmacyVerificationAssertAggregate(
            $onboarded['organization_id'],
            PharmacyVerificationStatus::PendingReview->value,
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
        expect(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_submitted')->count())->toBe(0)
            ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(0);

        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => $caseId,
            'requirement_code' => 'pharmacy_facility_license',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-e2e-frozen'))
            ->assertStatus(409);

        $admin = verificationSeedAdmin('e2e');
        $claimed = app(VerificationService::class)->claimCase(
            $admin['actor'],
            Identifier::fromTrusted($caseId),
            $caseVersion + 1,
        );
        $decided = app(VerificationService::class)->recordDecision(
            $admin['actor'],
            Identifier::fromTrusted($caseId),
            'approved',
            'approved',
            $claimed->version,
            'INTERNAL_PHARMACY_NOTE',
        );
        expect($decided->status)->toBe('approved')
            ->and($decided->decision)->toBe('approved');
        expect(json_encode($decided->toArray(), JSON_THROW_ON_ERROR))->not->toContain('INTERNAL_PHARMACY_NOTE');
        pharmacyVerificationAssertNoCanaries(json_encode($decided->toArray(), JSON_THROW_ON_ERROR), $onboarded);

        pharmacyVerificationAssertAggregate(
            $onboarded['organization_id'],
            PharmacyVerificationStatus::Approved->value,
            PharmacyOrganizationStatus::Active->value,
            'active',
            PharmacyMembershipStatus::Active->value,
        );
        expect(DB::table('verification_decisions')->where('case_id', $caseId)->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(1);

        $payload = verificationOutboxPayload('pharmacy.verification_decided');
        expect($payload)->toBeString();
        $decoded = json_decode((string) $payload, true);
        expect($decoded)->toBeArray()
            ->and($decoded['organization_id'] ?? null)->toBe($onboarded['organization_id'])
            ->and($decoded['branch_ids'] ?? null)->toBe([$onboarded['branch_id']])
            ->and($decoded['case_id'] ?? null)->toBe($caseId)
            ->and($decoded['decision'] ?? null)->toBe('approved')
            ->and($decoded['reason_code'] ?? null)->toBe('approved');
        pharmacyVerificationAssertNoCanaries((string) $payload, $onboarded);
        expect((string) $payload)->not->toContain('INTERNAL_PHARMACY_NOTE')
            ->and((string) $payload)->not->toContain($created['object_id'])
            ->and((string) $payload)->not->toContain('hmac');

        $me = $this->getJson('/api/v1/pharmacy-organizations/me', pharmaciesAuth($onboarded['session']['token']));
        $me->assertOk()
            ->assertJsonPath('data.verification_status', PharmacyVerificationStatus::Approved->value)
            ->assertJsonPath('data.status', PharmacyOrganizationStatus::Active->value)
            ->assertJsonPath('data.initial_branch.status', 'active')
            ->assertJsonPath('data.membership.status', PharmacyMembershipStatus::Active->value);
        pharmacyVerificationAssertNoCanaries((string) $me->getContent(), $onboarded);

        $caps = $this->getJson('/api/v1/me/capabilities', pharmaciesAuth($onboarded['session']['token']));
        $caps->assertOk();
        $granted = $caps->json('data.capabilities');
        expect($granted)->toContain(Capabilities::PHARMACIES_ORGANIZATION_READ_OWN)
            ->and($granted)->toContain(Capabilities::VERIFICATION_STATUS_READ_OWN);
        foreach (pharmacyVerificationForbiddenCapabilities() as $capability) {
            expect($granted)->not->toContain($capability);
        }

        $auditBlob = json_encode(DB::table('audit_events')->get(['event_name', 'metadata', 'object_id'])->all(), JSON_THROW_ON_ERROR);
        $idemBlob = (string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference');
        pharmacyVerificationAssertNoCanaries($auditBlob, $onboarded);
        pharmacyVerificationAssertNoCanaries($idemBlob, $onboarded);
        expect(app(PlatformMetrics::class)->render())->not->toContain($onboarded['legal_name'])
            ->and(app(PlatformMetrics::class)->render())->not->toContain($onboarded['registration']);
    });

    it('rejects submission when evidence is missing and keeps the aggregate draft', function () {
        $onboarded = pharmacyVerificationOnboard('no-doc');
        $opened = pharmacyVerificationOpenCase($onboarded['actor']);
        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($opened->caseVersion, $opened->organizationVersion),
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pver-no-doc'),
        )->assertStatus(422);

        pharmacyVerificationAssertAggregate(
            $onboarded['organization_id'],
            PharmacyVerificationStatus::Draft->value,
            PharmacyOrganizationStatus::Draft->value,
            PharmacyBranchStatus::Draft->value,
            PharmacyMembershipStatus::Pending->value,
        );
        expect((string) DB::table('verification_cases')->value('status'))->toBe('draft')
            ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(0);
    });

    it('rejects a stale case version and a stale organization version', function () {
        $draft = pharmacyVerificationPrepareDraftWithAvailableDocument('stale');
        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($draft['case_version'] + 8, $draft['organization_version']),
            pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-stale-case'),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');

        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($draft['case_version'], $draft['organization_version'] + 8),
            pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-stale-org'),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');
    });

    it('replays the same submit idempotency key and conflicts on a different payload', function () {
        $draft = pharmacyVerificationPrepareDraftWithAvailableDocument('idem');
        $headers = pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-idem-same');
        $body = pharmacyVerificationSubmitBody($draft['case_version'], $draft['organization_version']);

        $first = $this->postJson('/api/v1/pharmacy-organizations/me/verification-submissions', $body, $headers);
        $first->assertOk()->assertJsonPath('data.case_status', 'pending_review');
        $second = $this->postJson('/api/v1/pharmacy-organizations/me/verification-submissions', $body, $headers);
        $second->assertOk()
            ->assertJsonPath('data.case_id', $first->json('data.case_id'))
            ->assertJsonPath('data.status', 'submitted');

        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($draft['case_version'] + 1, $draft['organization_version']),
            $headers,
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');

        expect(DB::table('verification_cases')->count())->toBe(1);
    });

    it('keeps rejection and changes_requested non-operational and allows a new case', function (string $decision, string $reason, string $verificationStatus) {
        $draft = pharmacyVerificationPrepareDraftWithAvailableDocument('cr-'.$decision);
        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($draft['case_version'], $draft['organization_version']),
            pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-cr-sub-'.$decision),
        )->assertOk();

        $claimed = verificationClaimPending($draft, 'cr-'.$decision);
        app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            $decision,
            $reason,
            $claimed['version'],
        );

        pharmacyVerificationAssertAggregate(
            $draft['organization_id'],
            $verificationStatus,
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
        expect((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe($decision);

        $fresh = pharmacyVerificationOpenCase($draft['actor']);
        expect($fresh->caseId)->not->toBe($draft['case_id'])
            ->and($fresh->caseStatus)->toBe('draft');
        verificationRegisterRequiredPharmacyDocuments($fresh->caseId);

        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($fresh->caseVersion, $fresh->organizationVersion),
            pharmaciesAuth($draft['session']['token']) + pharmaciesIdem('pver-cr-resub-'.$decision),
        )->assertOk()->assertJsonPath('data.case_status', 'pending_review');

        pharmacyVerificationAssertAggregate(
            $draft['organization_id'],
            PharmacyVerificationStatus::PendingReview->value,
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
        expect(DB::table('verification_cases')->count())->toBe(2)
            ->and(DB::table('verification_decisions')->where('case_id', $draft['case_id'])->count())->toBe(1);
    })->with([
        'rejected' => ['rejected', 'unauthorized_entity', PharmacyVerificationStatus::Rejected->value],
        'changes_requested' => ['changes_requested', 'docs_blurry_or_illegible', PharmacyVerificationStatus::ChangesRequested->value],
    ]);

    it('denies self-review even when the actor carries privileged capabilities', function () {
        $draft = pharmacyVerificationPendingCase('self');
        $self = verificationOperatorActor($draft['session']['user_id']);
        expect(fn () => app(VerificationService::class)->recordDecision(
            $self,
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $draft['case_version'],
        ))->toThrow(AuthorizationDenied::class);

        expect(DB::table('verification_decisions')->count())->toBe(0)
            ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(0);
        pharmacyVerificationAssertAggregate(
            $draft['organization_id'],
            PharmacyVerificationStatus::PendingReview->value,
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
    });

    it('denies a low-assurance admin reviewer', function () {
        $draft = pharmacyVerificationPendingCase('weak');
        $weakAdmin = verificationSeedAdmin('pver-weak');
        $weakActor = verificationOperatorActor($weakAdmin['user_id'], AssuranceLevel::Aal1Password);
        expect(fn () => app(VerificationService::class)->recordDecision(
            $weakActor,
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $draft['case_version'],
        ))->toThrow(AuthorizationDenied::class);

        expect(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())->toBeGreaterThan(0)
            ->and(DB::table('verification_decisions')->count())->toBe(0);
    });
});
