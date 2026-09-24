<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Contracts\TrustedDocumentEvidenceIssuer;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Services\Adapters\DisabledTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\TrustedDocumentEvidence;
use Modules\Verification\Support\VerificationPolicy;
use Tests\Support\TestingTrustedDocumentEvidenceIssuer;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('doctor verification HTTP foundation', function () {
    it('creates a draft case and returns own status without sensitive fields', function () {
        $onboarded = verificationOnboardDoctor('status');
        $actor = $onboarded['actor'];
        $opened = verificationOpenCase($actor);
        $canary = $onboarded['national_id'];

        $status = $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($onboarded['session']['token']));
        $status->assertOk()
            ->assertJsonPath('data.doctor_id', $onboarded['doctor_id'])
            ->assertJsonPath('data.case_id', $opened->caseId)
            ->assertJsonPath('data.case_status', 'draft')
            ->assertJsonPath('data.profile_verification_status', DoctorVerificationStatus::Draft->value)
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.object_id')
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.national_id_lookup_hmac');

        expect($status->getContent())->not->toContain($canary)
            ->and($status->getContent())->not->toContain('hmac');
    });

    it('opens or resumes the caller draft case without client-supplied identity', function () {
        $onboarded = verificationOnboardDoctor('open-own');
        $opened = verificationOpenHttp($onboarded, 'ver-open-own');
        $opened->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.doctor_id', $onboarded['doctor_id'])
            ->assertJsonPath('data.case_status', 'draft')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.reviewer_id')
            ->assertJsonMissingPath('data.applicant_id')
            ->assertJsonMissingPath('data.case_type')
            ->assertJsonMissingPath('data.documents')
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.object_id');

        expect($opened->getContent())->not->toContain($onboarded['national_id'])
            ->and($opened->getContent())->not->toContain('hmac')
            ->and(strlen((string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference')))->toBeLessThanOrEqual(255);

        $resume = verificationOpenHttp($onboarded, 'ver-open-own-2');
        $resume->assertOk()
            ->assertJsonPath('data.case_id', $opened->json('data.case_id'))
            ->assertJsonPath('data.case_status', 'draft')
            ->assertJsonPath('data.profile_version', $opened->json('data.profile_version'));

        expect(DB::table('verification_cases')->count())->toBe(1)
            ->and((string) DB::table('verification_cases')->value('applicant_type'))->toBe('doctor')
            ->and((string) DB::table('verification_cases')->value('case_type'))->toBe('doctor_verification')
            ->and((string) DB::table('verification_cases')->value('applicant_id'))->toBe($onboarded['doctor_id']);
    });

    it('rejects mass assignment on case open and returns 401/404 for other actors', function () {
        $this->postJson('/api/v1/doctors/me/verification-cases', [], doctorsIdem('ver-open-unauth'))
            ->assertUnauthorized();

        $onboarded = verificationOnboardDoctor('open-mass');
        $this->postJson('/api/v1/doctors/me/verification-cases', [
            'doctor_id' => $onboarded['doctor_id'],
            'applicant_id' => $onboarded['session']['user_id'],
            'case_type' => 'doctor_verification',
            'status' => 'approved',
            'reviewer_id' => $onboarded['session']['user_id'],
            'verification_status' => 'approved',
            'public_status' => 'listed',
        ], doctorsAuth($onboarded['session']['token']) + doctorsIdem('ver-open-mass'))
            ->assertStatus(422);

        $patient = patientsActiveSession('ver-open-patient');
        $this->postJson(
            '/api/v1/doctors/me/verification-cases',
            [],
            patientsAuth($patient['token']) + doctorsIdem('ver-open-patient'),
        )->assertNotFound();

        $pharmacy = pharmaciesActiveSession('ver-open-pharmacy');
        $this->postJson(
            '/api/v1/doctors/me/verification-cases',
            [],
            pharmaciesAuth($pharmacy['token']) + doctorsIdem('ver-open-pharmacy'),
        )->assertNotFound();
    });

    it('returns 401 without a session and 404 for a patient actor', function () {
        $this->getJson('/api/v1/doctors/me/verification-status')->assertUnauthorized();
        $this->postJson('/api/v1/doctors/me/verification-submissions', [
            'case_version' => 1,
            'profile_version' => 1,
        ], doctorsIdem('ver-unauth'))->assertUnauthorized();

        $patient = patientsActiveSession('ver-patient');
        $this->getJson('/api/v1/doctors/me/verification-status', [
            'Authorization' => 'Bearer '.$patient['token'],
        ])->assertNotFound();
    });

    it('does not expose another doctor case on own-status or invented lookup routes', function () {
        $left = verificationPrepareDraftWithAvailableDocument('bola-l');
        $right = verificationOnboardDoctor('bola-r');

        $own = $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($right['session']['token']));
        $own->assertOk();
        expect($own->json('data.case_id'))->not->toBe($left['case_id'])
            ->and($own->json('data.doctor_id'))->toBe($right['doctor_id']);

        $this->getJson('/api/v1/doctors/'.$left['doctor_id'].'/verification-status', doctorsAuth($right['session']['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/verification-cases/'.$left['case_id'], doctorsAuth($right['session']['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/admin/verification-cases', doctorsAuth($left['session']['token']))
            ->assertNotFound();
        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => $left['case_id'],
            'requirement_code' => 'medical_license',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($right['session']['token']) + doctorsIdem('ver-upload-bola'))
            ->assertNotFound();
    });

    it('rejects unknown json fields on submit', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('mass');
        $this->postJson('/api/v1/doctors/me/verification-submissions', [
            'case_version' => $draft['case_version'],
            'profile_version' => $draft['profile_version'],
            'reviewer_id' => $draft['session']['user_id'],
            'status' => 'approved',
        ], doctorsAuth($draft['session']['token']) + doctorsIdem('ver-mass'))
            ->assertStatus(422);
    });
});

describe('submission', function () {
    it('submits when required documents are available and clean', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('submit-ok');
        $objectId = $draft['object_id'];
        $canary = $draft['national_id'];

        $response = $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-submit-ok'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.case_id', $draft['case_id'])
            ->assertJsonPath('data.case_status', 'pending_review')
            ->assertJsonPath('data.profile_verification_status', DoctorVerificationStatus::PendingReview->value)
            ->assertJsonMissingPath('data.object_id')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.documents')
            ->assertJsonMissingPath('data.profile_public_status');

        $status = $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($draft['session']['token']));
        $status->assertOk()
            ->assertJsonPath('data.case_id', $draft['case_id'])
            ->assertJsonPath('data.case_status', 'pending_review')
            ->assertJsonPath('data.profile_public_status', DoctorPublicStatus::Hidden->value);

        $body = $response->getContent();
        expect($body)->not->toContain($objectId)
            ->and($body)->not->toContain($canary)
            ->and($body)->not->toContain('s3://');

        expect((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe('pending_review')
            ->and((string) DB::table('doctor_profiles')->where('id', $draft['doctor_id'])->value('verification_status'))->toBe('pending_review')
            ->and((string) DB::table('doctor_profiles')->where('id', $draft['doctor_id'])->value('public_status'))->toBe('hidden');

        $payload = verificationOutboxPayload('doctor.verification_submitted');
        expect($payload)->toBeString()
            ->and($payload)->toContain($draft['doctor_id'])
            ->and($payload)->toContain($draft['case_id'])
            ->and($payload)->not->toContain($canary)
            ->and($payload)->not->toContain($objectId)
            ->and($payload)->not->toContain('national_id')
            ->and($payload)->not->toContain('object_id');

        expect(DB::table('audit_events')->where('event_name', 'verification.case_submitted')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'doctor.verification_status_changed')->count())->toBe(1);

        $auditBlob = json_encode(DB::table('audit_events')->get(['event_name', 'metadata', 'object_id'])->all(), JSON_THROW_ON_ERROR);
        expect($auditBlob)->not->toContain($canary)
            ->and($auditBlob)->not->toContain($objectId);
    });

    it('rejects submission when the required document is not available', function () {
        $onboarded = verificationOnboardDoctor('submit-quarantine');
        $opened = verificationOpenCase($onboarded['actor']);
        verificationRegisterDocument((string) $opened->caseId, 'quarantined', 'pending');

        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('ver-submit-q'),
        )->assertStatus(422);

        expect((string) DB::table('verification_cases')->value('status'))->toBe('draft')
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_submitted')->count())->toBe(0)
            ->and((string) DB::table('doctor_profiles')->value('verification_status'))->toBe('draft');
    });

    it('rejects a stale case version with VERSION_CONFLICT', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('stale');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'] + 8, $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-stale'),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');
    });

    it('rejects an invalid pending_review resubmit as STATE_CONFLICT', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('invalid-tx');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-tx-1'),
        )->assertOk();

        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'] + 1, $draft['profile_version'] + 1),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-tx-2'),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'STATE_CONFLICT');
    });

    it('replays the same idempotency key and payload', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('idem-ok');
        $headers = doctorsAuth($draft['session']['token']) + doctorsIdem('ver-idem-same');
        $body = verificationSubmitBody($draft['case_version'], $draft['profile_version']);

        $first = $this->postJson('/api/v1/doctors/me/verification-submissions', $body, $headers);
        $first->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.case_status', 'pending_review');
        $second = $this->postJson('/api/v1/doctors/me/verification-submissions', $body, $headers);
        $second->assertOk()
            ->assertJsonPath('data.case_id', $first->json('data.case_id'))
            ->assertJsonPath('data.case_status', 'pending_review')
            ->assertJsonPath('data.status', 'submitted');

        $stored = (string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference');
        expect(strlen($stored))->toBeLessThanOrEqual(255)
            ->and($stored)->toContain((string) $first->json('data.case_id'))
            ->and($stored)->not->toContain('documents')
            ->and($stored)->not->toContain($draft['object_id']);

        expect(DB::table('verification_cases')->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_submitted')->count())->toBe(1);
    });

    it('conflicts when the same idempotency key carries a different payload', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('idem-diff');
        $headers = doctorsAuth($draft['session']['token']) + doctorsIdem('ver-idem-diff');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            $headers,
        )->assertOk();

        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'] + 1, $draft['profile_version']),
            $headers,
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');
    });
});

describe('reviewer decisions', function () {
    it('records approval without listing the doctor or granting clinical capabilities', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('approve');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-ap-sub'),
        )->assertOk();

        $claimed = verificationClaimPending($draft, 'approve');
        $case = app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $claimed['version'],
            'INTERNAL_NOTE_CANARY',
        );

        expect($case->status)->toBe('approved')
            ->and($case->decision)->toBe('approved')
            ->and($case->reasonCode)->toBe('approved');
        expect(json_encode($case->toArray(), JSON_THROW_ON_ERROR))->not->toContain($draft['object_id'])
            ->and(json_encode($case->toArray(), JSON_THROW_ON_ERROR))->not->toContain('INTERNAL_NOTE_CANARY');

        $profile = $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($draft['session']['token']));
        $profile->assertOk()
            ->assertJsonPath('data.verification_status', DoctorVerificationStatus::Approved->value)
            ->assertJsonPath('data.public_status', DoctorPublicStatus::Hidden->value);

        $own = $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($draft['session']['token']));
        $own->assertOk()
            ->assertJsonPath('data.decision', 'approved')
            ->assertJsonPath('data.reason_code', 'approved');
        expect($own->getContent())->not->toContain('INTERNAL_NOTE_CANARY')
            ->and($own->getContent())->not->toContain($draft['object_id'])
            ->and($own->getContent())->not->toContain($draft['national_id']);

        $payload = verificationOutboxPayload('doctor.verification_decided');
        expect($payload)->toBeString();
        $decoded = json_decode((string) $payload, true);
        expect($decoded)->toBeArray()
            ->and($decoded['decision'] ?? null)->toBe('approved')
            ->and($decoded['reason_code'] ?? null)->toBe('approved')
            ->and($payload)->not->toContain('INTERNAL_NOTE_CANARY')
            ->and($payload)->not->toContain($draft['object_id'])
            ->and($payload)->not->toContain($draft['national_id']);

        $caps = $this->getJson('/api/v1/me/capabilities', doctorsAuth($draft['session']['token']));
        $caps->assertOk();
        expect($caps->json('data.capabilities'))->not->toContain('clinical.record.read');

        expect(DB::table('verification_decisions')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'verification.decision_recorded')->count())->toBe(1);
    });

    it('records rejection and changes_requested through the Doctors public service', function (string $decision, string $reason, string $profileStatus) {
        $draft = verificationPrepareDraftWithAvailableDocument('dec-'.$decision);
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-dec-'.$decision),
        )->assertOk();

        $claimed = verificationClaimPending($draft, 'dec-'.$decision);
        app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            $decision,
            $reason,
            $claimed['version'],
        );

        expect((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe($decision)
            ->and((string) DB::table('doctor_profiles')->where('id', $draft['doctor_id'])->value('verification_status'))->toBe($profileStatus)
            ->and((string) DB::table('doctor_profiles')->where('id', $draft['doctor_id'])->value('public_status'))->toBe('hidden');
    })->with([
        'rejected' => ['rejected', 'unauthorized_entity', DoctorVerificationStatus::Rejected->value],
        'changes_requested' => ['changes_requested', 'docs_blurry_or_illegible', DoctorVerificationStatus::ChangesRequested->value],
    ]);

    it('keeps historical decisions when a new case is opened after changes_requested', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('history');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-hist-sub'),
        )->assertOk();

        $claimed = verificationClaimPending($draft, 'history');
        app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'changes_requested',
            'docs_blurry_or_illegible',
            $claimed['version'],
        );

        $fresh = verificationOpenCase($draft['actor']);
        expect($fresh->caseId)->not->toBe($draft['case_id'])
            ->and($fresh->caseStatus)->toBe('draft');

        expect(DB::table('verification_cases')->count())->toBe(2)
            ->and(DB::table('verification_decisions')->where('case_id', $draft['case_id'])->count())->toBe(1)
            ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe('changes_requested');
    });

    it('denies self-review even when the actor carries privileged capabilities', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('self');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-self-sub'),
        )->assertOk();

        $self = verificationOperatorActor($draft['session']['user_id']);
        expect(fn () => app(VerificationService::class)->recordDecision(
            $self,
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $draft['case_version'] + 1,
        ))->toThrow(AuthorizationDenied::class);

        expect(DB::table('verification_decisions')->count())->toBe(0)
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(0);
    });

    it('denies an unauthorized reviewer and a low-assurance admin', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('unauth');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-unauth-sub'),
        )->assertOk();

        expect(fn () => app(VerificationService::class)->recordDecision(
            $draft['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $draft['case_version'] + 1,
        ))->toThrow(AuthorizationDenied::class);

        $weakAdmin = verificationSeedAdmin('weak');
        $weakActor = verificationOperatorActor($weakAdmin['user_id'], AssuranceLevel::Aal1Password);
        expect(fn () => app(VerificationService::class)->recordDecision(
            $weakActor,
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $draft['case_version'] + 1,
        ))->toThrow(AuthorizationDenied::class);

        expect(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())->toBeGreaterThan(0)
            ->and(DB::table('verification_decisions')->count())->toBe(0);
    });

    it('claims a pending case for one reviewer and rejects a second claimant', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('claim');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-claim-sub'),
        )->assertOk();

        $admin = verificationSeedAdmin('claim');
        $claimed = app(VerificationService::class)->claimCase(
            $admin['actor'],
            Identifier::fromTrusted($draft['case_id']),
            $draft['case_version'] + 1,
        );
        expect($claimed->assignedReviewerId)->toBe($admin['user_id']);

        $other = verificationSeedAdmin('claim-other');
        expect(fn () => app(VerificationService::class)->claimCase(
            $other['actor'],
            Identifier::fromTrusted($draft['case_id']),
            $draft['case_version'] + 2,
        ))->toThrow(StateConflict::class);

        expect(DB::table('audit_events')->where('event_name', 'verification.reviewer_claimed')->count())->toBe(1);
    });

    it('denies unknown decisions and reason codes', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('unknown');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-unk-sub'),
        )->assertOk();
        $admin = verificationSeedAdmin('unknown');

        expect(fn () => app(VerificationService::class)->recordDecision(
            $admin['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'activate_clinical',
            'approved',
            $draft['case_version'] + 1,
        ))->toThrow(InvalidValueObject::class);

        expect(fn () => app(VerificationService::class)->recordDecision(
            $admin['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'rejected',
            'fraud_internal',
            $draft['case_version'] + 1,
        ))->toThrow(InvalidValueObject::class);
    });

    it('replays an identical decision and rejects a conflicting second decision', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('replay');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-replay-sub'),
        )->assertOk();
        $claimed = verificationClaimPending($draft, 'replay');
        $service = app(VerificationService::class);
        $first = $service->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        );
        $second = $service->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        );

        expect($second->caseId)->toBe($first->caseId)
            ->and(DB::table('verification_decisions')->count())->toBe(1);

        $other = verificationSeedAdmin('replay-other');
        expect(fn () => $service->recordDecision(
            $other['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'rejected',
            'unauthorized_entity',
            $draft['case_version'] + 1,
        ))->toThrow(StateConflict::class);

        expect(DB::table('verification_decisions')->count())->toBe(1)
            ->and((string) DB::table('verification_decisions')->value('decision'))->toBe('approved');
    });

    it('rolls back the decision when the doctor transition is no longer legal', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('rollback');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-rb-sub'),
        )->assertOk();

        $claimed = verificationClaimPending($draft, 'rollback');

        DB::table('doctor_profiles')->where('id', $draft['doctor_id'])->update([
            'verification_status' => DoctorVerificationStatus::Draft->value,
        ]);

        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        ))->toThrow(StateConflict::class);

        expect(DB::table('verification_decisions')->count())->toBe(0)
            ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe('pending_review')
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(0);
    });

    it('omits object identifiers from review-safe document metadata', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('meta');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-meta-sub'),
        )->assertOk();
        $claimed = verificationClaimPending($draft, 'meta');
        $row = DB::table('verification_documents')->where('case_id', $draft['case_id'])->first();
        $projection = app(VerificationDocumentService::class)->reviewSafeMetadata(
            $claimed['admin']['actor'],
            Identifier::fromTrusted((string) $row->id),
        );
        $encoded = json_encode($projection->toArray(), JSON_THROW_ON_ERROR);

        expect($encoded)->not->toContain($draft['object_id'])
            ->and($encoded)->not->toContain('object_id')
            ->and($projection->sha256)->toBe((string) $row->sha256);
    });

    it('denies a decision on an unclaimed case', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('unclaimed');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-unclaimed-sub'),
        )->assertOk();

        $admin = verificationSeedAdmin('unclaimed');
        expect(fn () => app(VerificationService::class)->recordDecision(
            $admin['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $draft['case_version'] + 1,
        ))->toThrow(AuthorizationDenied::class);

        expect(DB::table('verification_decisions')->count())->toBe(0)
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(0)
            ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('assigned_reviewer_id'))->toBe('');
    });
});

describe('trusted document registration', function () {
    it('binds the fail-closed issuer in the production container', function () {
        expect(app(TrustedDocumentEvidenceIssuer::class))->toBeInstanceOf(DisabledTrustedDocumentEvidenceIssuer::class)
            ->and(app(TrustedDocumentEvidenceIssuer::class)->canIssue())->toBeFalse();
    });

    it('does not let a doctor or admin ActorContext issue AVAILABLE evidence', function () {
        $onboarded = verificationOnboardDoctor('forge-doc');
        $opened = verificationOpenCase($onboarded['actor']);
        $admin = verificationSeedAdmin('forge-admin');
        $payload = [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'medical_license',
            'object_id' => app(IdentityGenerator::class)->next()->value,
            'sha256' => str_repeat('ab', 32),
            'detected_mime' => 'application/pdf',
            'size_bytes' => 2048,
            'scan_status' => 'clean',
            'status' => 'available',
        ];

        expect(fn () => app(TrustedDocumentEvidenceIssuer::class)->issue($payload))
            ->toThrow(ProviderNotEnabled::class);
        expect(fn () => TrustedDocumentEvidence::hydrateFromIssuer(app(TrustedDocumentEvidenceIssuer::class), $payload))
            ->toThrow(ProviderNotEnabled::class);
        expect(fn () => (new ReflectionClass(TrustedDocumentEvidence::class))->newInstanceArgs([
            Identifier::fromTrusted($payload['case_id']),
            $payload['requirement_code'],
            Identifier::fromTrusted($payload['object_id']),
            $payload['sha256'],
            $payload['detected_mime'],
            $payload['size_bytes'],
            VerificationDocumentScanStatus::Clean,
            VerificationDocumentStatus::Available,
        ]))->toThrow(ReflectionException::class);

        expect(DB::table('verification_documents')->count())->toBe(0);
        unset($onboarded, $admin);
    });

    it('registers AVAILABLE evidence only through the test-only issuer', function () {
        $onboarded = verificationOnboardDoctor('trusted-iss');
        $opened = verificationOpenCase($onboarded['actor']);
        $document = verificationRegisterDocument((string) $opened->caseId);

        $audit = DB::table('audit_events')->where('event_name', 'verification.document_registered')->first();
        $metadata = is_string($audit->metadata) ? json_decode($audit->metadata, true) : (array) $audit->metadata;

        expect(DB::table('verification_documents')->where('id', $document['document_id'])->value('status'))->toBe('available')
            ->and($audit->actor_type)->toBe('system')
            ->and($audit->actor_id)->toBeNull()
            ->and($metadata['attributed_applicant_user_id'] ?? null)->toBe($onboarded['session']['user_id'])
            ->and(json_encode($metadata, JSON_THROW_ON_ERROR))->not->toContain($document['object_id'])
            ->and(app(TrustedDocumentEvidenceIssuer::class))->toBeInstanceOf(DisabledTrustedDocumentEvidenceIssuer::class);
    });

    it('attributes registration to the case applicant even when a different user is in scope', function () {
        $owner = verificationOnboardDoctor('attr-owner');
        $opened = verificationOpenCase($owner['actor']);
        $other = verificationOnboardDoctor('attr-other');
        $document = verificationRegisterDocument((string) $opened->caseId);

        $audit = DB::table('audit_events')->where('event_name', 'verification.document_registered')->first();
        $metadata = is_string($audit->metadata) ? json_decode($audit->metadata, true) : (array) $audit->metadata;

        expect($metadata['attributed_applicant_user_id'] ?? null)->toBe($owner['session']['user_id'])
            ->and($metadata['attributed_applicant_user_id'] ?? null)->not->toBe($other['session']['user_id'])
            ->and($audit->actor_type)->toBe('system');
        unset($document);
    });

    it('fails closed when the case applicant cannot be resolved', function () {
        $ids = app(IdentityGenerator::class);
        $now = now('UTC')->format('Y-m-d H:i:s.uP');
        $caseId = $ids->next()->value;
        DB::table('verification_cases')->insert([
            'id' => $caseId,
            'applicant_type' => 'doctor',
            'applicant_id' => $ids->next()->value,
            'case_type' => 'doctor_verification',
            'status' => 'draft',
            'submitted_at' => null,
            'assigned_reviewer_id' => null,
            'decided_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $objectId = $ids->next()->value;
        $evidence = (new TestingTrustedDocumentEvidenceIssuer(app(VerificationPolicy::class)))->issue([
            'case_id' => $caseId,
            'requirement_code' => 'medical_license',
            'object_id' => $objectId,
            'sha256' => hash('sha256', 'synthetic-missing-applicant-'.$objectId),
            'detected_mime' => 'application/pdf',
            'size_bytes' => 2048,
            'scan_status' => 'clean',
            'status' => 'available',
        ]);

        expect(fn () => app(VerificationDocumentService::class)->registerValidatedMetadata($evidence))
            ->toThrow(AuthorizationDenied::class);

        expect(DB::table('verification_documents')->count())->toBe(0)
            ->and(DB::table('audit_events')->where('event_name', 'verification.document_registered')->count())->toBe(0);
    });

    it('applies a trusted scan outcome only while the case is draft', function () {
        $onboarded = verificationOnboardDoctor('scan-life');
        $opened = verificationOpenCase($onboarded['actor']);
        $quarantined = verificationRegisterDocument((string) $opened->caseId, 'quarantined', 'pending');
        $issuer = new TestingTrustedDocumentEvidenceIssuer(app(VerificationPolicy::class));
        $promoted = $issuer->issue([
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'medical_license',
            'object_id' => $quarantined['object_id'],
            'sha256' => $quarantined['sha256'],
            'detected_mime' => 'application/pdf',
            'size_bytes' => 2048,
            'scan_status' => 'clean',
            'status' => 'available',
        ]);

        $updated = app(VerificationDocumentService::class)->applyTrustedScanOutcome($promoted);
        expect($updated->status)->toBe('available')
            ->and($updated->scanStatus)->toBe('clean');

        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('ver-scan-life-sub'),
        )->assertOk();

        expect(fn () => app(VerificationDocumentService::class)->applyTrustedScanOutcome($promoted))
            ->toThrow(StateConflict::class);
    });
});

describe('reviewer document access', function () {
    it('denies ordinary doctors and low-assurance admins', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('rev-deny');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-rev-deny-sub'),
        )->assertOk();
        $documentId = Identifier::fromTrusted((string) DB::table('verification_documents')->where('case_id', $draft['case_id'])->value('id'));

        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata($draft['actor'], $documentId))
            ->toThrow(AuthorizationDenied::class);

        $weak = verificationSeedAdmin('rev-weak');
        $weakActor = verificationOperatorActor($weak['user_id'], AssuranceLevel::Aal1Password);
        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata($weakActor, $documentId))
            ->toThrow(AuthorizationDenied::class);

        expect(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())->toBeGreaterThan(0);
    });

    it('denies unassigned privileged reviewers and self-review', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('rev-unassigned');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-rev-unass-sub'),
        )->assertOk();
        $documentId = Identifier::fromTrusted((string) DB::table('verification_documents')->where('case_id', $draft['case_id'])->value('id'));
        $admin = verificationSeedAdmin('rev-unassigned');

        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata($admin['actor'], $documentId))
            ->toThrow(AuthorizationDenied::class);

        $header = app(VerificationService::class)->reviewerCase($admin['actor'], Identifier::fromTrusted($draft['case_id']));
        expect($header->documents)->toBe([]);

        $self = verificationOperatorActor($draft['session']['user_id']);
        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata($self, $documentId))
            ->toThrow(AuthorizationDenied::class);
    });

    it('lets the assigned reviewer read only AVAILABLE and CLEAN evidence', function () {
        $onboarded = verificationOnboardDoctor('rev-ok');
        $opened = verificationOpenCase($onboarded['actor']);
        $quarantined = verificationRegisterDocument((string) $opened->caseId, 'quarantined', 'pending');
        $failed = verificationRegisterDocument((string) $opened->caseId, 'rejected', 'failed');
        $retired = verificationRegisterDocument((string) $opened->caseId, 'retired', 'clean');
        $available = verificationRegisterDocument((string) $opened->caseId);
        $draft = [
            'session' => $onboarded['session'],
            'case_id' => (string) $opened->caseId,
            'case_version' => (int) $opened->caseVersion,
            'profile_version' => $opened->profileVersion,
            'object_id' => $available['object_id'],
        ];
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-rev-ok-sub'),
        )->assertOk();
        $claimed = verificationClaimPending($draft, 'rev-ok');

        $ok = app(VerificationDocumentService::class)->reviewSafeMetadata(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($available['document_id']),
        );
        expect($ok->status)->toBe('available')
            ->and($ok->scanStatus)->toBe('clean')
            ->and(json_encode($ok->toArray(), JSON_THROW_ON_ERROR))->not->toContain($available['object_id']);

        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($quarantined['document_id']),
        ))->toThrow(AuthorizationDenied::class);
        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($failed['document_id']),
        ))->toThrow(AuthorizationDenied::class);
        expect(fn () => app(VerificationDocumentService::class)->reviewSafeMetadata(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($retired['document_id']),
        ))->toThrow(AuthorizationDenied::class);

        $projection = app(VerificationService::class)->reviewerCase(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
        );
        expect($projection->documents)->toHaveCount(1)
            ->and($projection->documents[0]['document_id'])->toBe($available['document_id']);
    });

    it('does not decide when submitted reviewable evidence is no longer valid', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('broken-ev');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('ver-broken-sub'),
        )->assertOk();
        $claimed = verificationClaimPending($draft, 'broken-ev');

        DB::statement('ALTER TABLE verification_documents DISABLE TRIGGER verification_documents_protect');
        DB::table('verification_documents')->where('case_id', $draft['case_id'])->update([
            'status' => 'quarantined',
            'scan_status' => 'pending',
        ]);
        DB::statement('ALTER TABLE verification_documents ENABLE TRIGGER verification_documents_protect');

        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        ))->toThrow(ValidationException::class);

        expect(DB::table('verification_decisions')->count())->toBe(0)
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(0)
            ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))->toBe('pending_review')
            ->and((string) DB::table('doctor_profiles')->where('id', $draft['doctor_id'])->value('verification_status'))->toBe('pending_review');
    });
});
