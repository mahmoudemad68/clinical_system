<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\VerificationDecision;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\ApprovedVerificationPolicyV1;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('Phase 02 verification policy v1.0.1 HTTP', function () {
    it('submits a doctor case with both required documents and without syndicate_card', function () {
        $onboarded = verificationOnboardDoctor('pol-doc-ok');
        $opened = verificationOpenCase($onboarded['actor']);
        verificationRegisterDocument((string) $opened->caseId, requirement: 'medical_license');
        verificationRegisterDocument((string) $opened->caseId, requirement: 'national_id_or_passport');

        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('pol-doc-ok-sub'),
        )->assertOk()->assertJsonPath('data.case_status', 'pending_review');
    });

    it('blocks doctor submit when only medical_license is available', function () {
        $onboarded = verificationOnboardDoctor('pol-doc-one');
        $opened = verificationOpenCase($onboarded['actor']);
        verificationRegisterDocument((string) $opened->caseId, requirement: 'medical_license');

        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody((int) $opened->caseVersion, $opened->profileVersion),
            doctorsAuth($onboarded['session']['token']) + doctorsIdem('pol-doc-one-sub'),
        )->assertStatus(422);

        expect((string) DB::table('verification_cases')->where('id', $opened->caseId)->value('status'))->toBe('draft');
    });

    it('denies unknown and withdrawn requirement codes on new grants', function () {
        $onboarded = verificationOnboardDoctor('pol-unk');
        $opened = verificationOpenCase($onboarded['actor']);

        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($onboarded['session']['token']) + doctorsIdem('pol-unk-legacy'))
            ->assertStatus(422);

        $this->postJson('/api/v1/verification-uploads', [
            'case_id' => (string) $opened->caseId,
            'requirement_code' => 'fabricated_licence',
            'expected_size_bytes' => 128,
            'declared_media_type' => 'application/pdf',
        ], doctorsAuth($onboarded['session']['token']) + doctorsIdem('pol-unk-new'))
            ->assertStatus(422);
    });

    it('submits pharmacy only when all three required documents are available', function () {
        $onboarded = pharmacyVerificationOnboard('pol-pharm-ok');
        $opened = pharmacyVerificationOpenCase($onboarded['actor']);
        verificationRegisterDocument($opened->caseId, requirement: 'pharmacy_facility_license');
        verificationRegisterDocument($opened->caseId, requirement: 'commercial_register');

        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($opened->caseVersion, $opened->organizationVersion),
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pol-pharm-2'),
        )->assertStatus(422);

        verificationRegisterDocument($opened->caseId, requirement: 'responsible_pharmacist_license');
        $this->postJson(
            '/api/v1/pharmacy-organizations/me/verification-submissions',
            pharmacyVerificationSubmitBody($opened->caseVersion, $opened->organizationVersion),
            pharmaciesAuth($onboarded['session']['token']) + pharmaciesIdem('pol-pharm-3'),
        )->assertOk()->assertJsonPath('data.case_status', 'pending_review');
    });

    it('rejects withdrawn reason codes on new decisions and keeps historical rows readable', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('pol-hist');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('pol-hist-sub'),
        )->assertOk();

        $claimed = verificationClaimPending($draft, 'pol-hist');
        $ids = app(IdentityGenerator::class);
        $now = now('UTC')->format('Y-m-d H:i:s.uP');
        $historicalCase = $ids->next()->value;
        DB::table('verification_cases')->insert([
            'id' => $historicalCase,
            'applicant_type' => 'doctor',
            'applicant_id' => $draft['doctor_id'],
            'case_type' => 'doctor_verification',
            'status' => 'rejected',
            'submitted_at' => $now,
            'assigned_reviewer_id' => null,
            'decided_at' => $now,
            'version' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $historicalDecision = $ids->next()->value;
        DB::table('verification_decisions')->insert([
            'id' => $historicalDecision,
            'case_id' => $historicalCase,
            'decision' => 'rejected',
            'reason_code' => 'identity_mismatch',
            'reviewer_id' => $claimed['admin']['user_id'],
            'reviewer_assurance_level' => 'aal2_totp',
            'notes_ciphertext' => null,
            'created_at' => $now,
        ]);
        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'rejected',
            'identity_mismatch',
            $claimed['version'],
        ))->toThrow(InvalidValueObject::class);

        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'changes_requested',
            'documents_illegible',
            $claimed['version'],
        ))->toThrow(InvalidValueObject::class);

        expect((string) DB::table('verification_decisions')->where('id', $historicalDecision)->value('reason_code'))
            ->toBe('identity_mismatch')
            ->and((string) DB::table('verification_decisions')->where('id', $historicalDecision)->value('decision'))
            ->toBe('rejected');
    });

    it('records an approved changes_requested reason and shows the applicant-safe explanation', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('pol-safe');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('pol-safe-sub'),
        )->assertOk();
        $claimed = verificationClaimPending($draft, 'pol-safe');
        app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            VerificationDecision::ChangesRequested->value,
            'identity_mismatch',
            $claimed['version'],
            'reviewer-private-notes-must-not-cross',
        );

        $status = $this->getJson(
            '/api/v1/doctors/me/verification-status',
            doctorsAuth($draft['session']['token']),
        )->assertOk()
            ->assertJsonPath('data.reason_code', 'identity_mismatch')
            ->assertJsonPath('data.applicant_safe_explanation', ApprovedVerificationPolicyV1::applicantSafeExplanation('identity_mismatch'))
            ->assertJsonMissingPath('data.notes');
        expect($status->getContent())->not->toContain('reviewer-private-notes-must-not-cross');

        $fresh = verificationOpenCase($draft['actor']);
        expect($fresh->caseId)->not->toBe($draft['case_id'])
            ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))
            ->toBe('changes_requested')
            ->and((string) DB::table('verification_decisions')->where('case_id', $draft['case_id'])->value('reason_code'))
            ->toBe('identity_mismatch');
    });

    it('allows rejected resubmission as a new case immediately', function () {
        $draft = verificationPrepareDraftWithAvailableDocument('pol-rej');
        $this->postJson(
            '/api/v1/doctors/me/verification-submissions',
            verificationSubmitBody($draft['case_version'], $draft['profile_version']),
            doctorsAuth($draft['session']['token']) + doctorsIdem('pol-rej-sub'),
        )->assertOk();
        $claimed = verificationClaimPending($draft, 'pol-rej');
        app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($draft['case_id']),
            'rejected',
            'unauthorized_entity',
            $claimed['version'],
        );

        $fresh = verificationOpenCase($draft['actor']);
        expect($fresh->caseId)->not->toBe($draft['case_id'])
            ->and($fresh->caseStatus)->toBe('draft')
            ->and((string) DB::table('verification_cases')->where('id', $draft['case_id'])->value('status'))
            ->toBe('rejected');
    });

    it('does not expose an appeal capability or route', function () {
        $names = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->implode(' ');
        expect($names)->not->toContain('appeal')
            ->and(file_get_contents(base_path('Modules/Access/app/Support/Capabilities.php')))
            ->not->toContain('appeal');
    });

    it('keeps a pending-review case submitted under the previous requirement reviewable', function () {
        $onboarded = verificationOnboardDoctor('pol-legacy-pending');
        $opened = verificationOpenCase($onboarded['actor']);
        verificationRegisterDocument((string) $opened->caseId, requirement: 'professional_id');
        $now = now('UTC')->format('Y-m-d H:i:s.uP');
        DB::table('verification_cases')->where('id', $opened->caseId)->update([
            'status' => 'pending_review',
            'submitted_at' => $now,
            'version' => (int) $opened->caseVersion + 1,
            'updated_at' => $now,
        ]);

        $admin = adminVerificationInsertAdmin('pol-legacy');
        adminVerificationLogin($admin);
        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$opened->caseId.'/claim',
            ['expected_case_version' => (int) $opened->caseVersion + 1],
        )->assertOk();

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$opened->caseId.'/decisions',
            [
                'decision' => 'changes_requested',
                'reason_code' => 'docs_blurry_or_illegible',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
            ],
            adminVerificationIdem('pol-legacy-decide'),
        )->assertOk()->assertJsonPath('data.case_status', 'changes_requested');

        expect((string) DB::table('verification_documents')->where('case_id', $opened->caseId)->value('requirement_code'))
            ->toBe('professional_id')
            ->and((string) DB::table('verification_cases')->where('id', $opened->caseId)->value('status'))
            ->toBe('changes_requested');
    });

    it('still requires AAL2 for reviewer claim', function () {
        $pending = adminVerificationPendingCase('pol-aal1');
        $weak = adminVerificationInsertAdmin('pol-weak');
        adminVerificationLogin($weak);
        DB::table('auth_sessions')->where('user_id', $weak['id'])->update([
            'assurance_level' => AssuranceLevel::Aal1Password->value,
        ]);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertNotFound();
        expect(DB::table('verification_cases')->where('id', $pending['case_id'])->value('assigned_reviewer_id'))
            ->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())
            ->toBeGreaterThan(0);
    });

    it('still denies the creating admin from reviewing a represented applicant', function () {
        doctorsSeedSpecialty('general_practice');
        $creator = adminVerificationInsertAdmin('pol-creator');
        adminVerificationLogin($creator);
        $created = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody(['professional_display_name' => 'Dr Policy Creator']),
            adminVerificationIdem('pol-acd-create'),
        )->assertCreated();
        adminCreatedDoctorAttachEvidenceAndSubmit(
            (string) $created->json('data.doctor_id'),
            (string) $created->json('data.case_id'),
            (int) $created->json('data.case_version'),
            (int) $created->json('data.profile_version'),
            'pol-acd',
        );

        $version = (int) DB::table('verification_cases')->where('id', $created->json('data.case_id'))->value('version');
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$created->json('data.case_id').'/claim',
            ['expected_case_version' => $version],
        )->assertNotFound();
        expect(DB::table('verification_cases')->where('id', $created->json('data.case_id'))->value('assigned_reviewer_id'))
            ->toBeNull();
    });
});
