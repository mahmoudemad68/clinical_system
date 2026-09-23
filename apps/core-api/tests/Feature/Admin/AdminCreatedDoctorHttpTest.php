<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorSourceType;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Services\Telemetry\RedactingLogTap;
use Modules\Verification\Services\VerificationService;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('admin-created doctor HTTP', function () {
    beforeEach(function () {
        doctorsSeedApprovedSpecialtyCatalogue();
    });

    it('creates a draft hidden applicant, opens the canonical case, and denies pending capability', function () {
        $specialty = doctorsSeedSpecialty('general_practice');
        $body = adminCreatedDoctorApplicantBody([
            'specialty_id' => $specialty['id'],
            'professional_display_name' => 'Dr Admin Created Happy',
            'syndicate_number' => 'SYN-ACD-1001',
        ]);
        $canaryNid = $body['national_id'];
        $canaryPhone = $body['phone'];
        $canarySyn = 'SYN-ACD-1001';
        $logHandler = new TestHandler(Level::Debug);
        $monolog = new MonologLogger('admin-created-doctor-canary');
        $monolog->pushHandler($logHandler);
        app(RedactingLogTap::class)(new Logger($monolog));

        $admin = adminVerificationInsertAdmin('creator-happy');
        adminVerificationLogin($admin);

        $catalogue = adminVerificationGetJson('/api/v1/admin/doctor-applicants/specialties')->assertOk();
        $codes = collect($catalogue->json('data.specialties'))->pluck('code')->all();
        expect($codes)->toContain('general_practice')
            ->and(count($catalogue->json('data.specialties')))->toBeGreaterThanOrEqual(12)
            ->and($catalogue->json('data.specialties.0'))->not->toHaveKey('active')
            ->and($catalogue->getContent())->not->toContain('certified')
            ->and($catalogue->getContent())->not->toContain($canaryNid);

        $created = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            $body,
            adminVerificationIdem('acd-happy'),
        );
        $created->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.case_status', 'draft')
            ->assertJsonPath('data.profile_version', 1)
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.syndicate_number')
            ->assertJsonMissingPath('data.verification_status')
            ->assertJsonMissingPath('data.public_status')
            ->assertJsonMissingPath('data.bootstrap');

        $encoded = $created->getContent();
        expect($encoded)->not->toContain($canaryNid)
            ->and($encoded)->not->toContain($canaryPhone)
            ->and($encoded)->not->toContain($canarySyn)
            ->and($encoded)->not->toContain($body['password'])
            ->and($encoded)->not->toContain('hmac');

        $doctorId = (string) $created->json('data.doctor_id');
        $caseId = (string) $created->json('data.case_id');
        $row = DB::table('doctor_profiles')->where('id', $doctorId)->first();
        expect($row)->not->toBeNull()
            ->and((string) $row->source_type)->toBe(DoctorSourceType::AdminCreated->value)
            ->and((string) $row->created_by_user_id)->toBe($admin['id'])
            ->and((string) $row->user_id)->not->toBe($admin['id'])
            ->and((string) $row->verification_status)->toBe(DoctorVerificationStatus::Draft->value)
            ->and((string) $row->public_status)->toBe(DoctorPublicStatus::Hidden->value)
            ->and($row->approved_at)->toBeNull();

        $outbox = verificationOutboxPayload('doctor.profile_created');
        expect($outbox)->not->toBeNull()
            ->and($outbox)->toContain('admin_created')
            ->and($outbox)->not->toContain($canaryNid)
            ->and($outbox)->not->toContain($canaryPhone)
            ->and($outbox)->not->toContain($canarySyn);

        $auditJson = adminVerificationAuditJson('doctor.profile_created');
        expect($auditJson)->not->toContain($canaryNid)
            ->and($auditJson)->not->toContain($canaryPhone)
            ->and($auditJson)->not->toContain($canarySyn);

        $records = $logHandler->getRecords();
        $blob = json_encode($records, JSON_THROW_ON_ERROR);
        expect($blob)->not->toContain($canaryNid)
            ->and($blob)->not->toContain($canarySyn);

        adminCreatedDoctorAttachEvidenceAndSubmit(
            $doctorId,
            $caseId,
            (int) $created->json('data.case_version'),
            (int) $created->json('data.profile_version'),
            'happy',
        );

        expect((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('verification_status'))
            ->toBe(DoctorVerificationStatus::PendingReview->value)
            ->and((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('public_status'))
            ->toBe(DoctorPublicStatus::Hidden->value);

        $userId = adminCreatedDoctorUserId($doctorId);
        expect(adminCreatedDoctorProbeClinicCreate($userId, 'Pending Admin Created Clinic'))->toBe(404)
            ->and(DB::table('clinic_locations')->count())->toBe(0);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$caseId.'/claim',
            ['expected_case_version' => (int) DB::table('verification_cases')->where('id', $caseId)->value('version')],
        )->assertNotFound();

        adminVerificationLogout();
        $reviewer = adminVerificationInsertAdmin('reviewer-happy');
        adminVerificationLogin($reviewer);
        adminVerificationGetJson('/api/v1/me')->assertOk()->assertJsonPath('data.user_id', $reviewer['id']);
        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$caseId.'/claim',
            ['expected_case_version' => (int) DB::table('verification_cases')->where('id', $caseId)->value('version')],
        )->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$caseId.'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
            ],
            adminVerificationIdem('acd-happy-approve'),
        )->assertOk();

        expect((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('verification_status'))
            ->toBe(DoctorVerificationStatus::Approved->value)
            ->and((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('public_status'))
            ->toBe(DoctorPublicStatus::Hidden->value)
            ->and((string) DB::table('verification_cases')->where('id', $caseId)->value('status'))
            ->toBe('approved');

        expect(adminCreatedDoctorProbeClinicCreate($userId, 'Approved Admin Created Probe'))->toBe(201);

        $approvedSession = adminCreatedDoctorAttachTotpAndLogin($userId, $body['phone'], $body['password'], 'approved');
        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody('Approved Admin Created Clinic'),
            doctorsAuth($approvedSession['token']) + clinicIdem('acd-approved-clinic'),
        )->assertCreated();
        $me = $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($approvedSession['token']));
        $me->assertOk()
            ->assertJsonPath('data.verification_status', DoctorVerificationStatus::Approved->value)
            ->assertJsonPath('data.public_status', DoctorPublicStatus::Hidden->value);
        expect($me->getContent())->not->toContain($canaryNid)
            ->and((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('public_status'))
            ->toBe(DoctorPublicStatus::Hidden->value);
    });

    it('rejects duplicate protected identity without creating a second profile', function () {
        $specialty = doctorsSeedSpecialty('general_practice');
        $self = doctorsActiveSession('self-dup');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($self['payload']['national_id'], $specialty['id'], 'SYN-SELF-DUP'),
            doctorsAuth($self['token']) + doctorsIdem('acd-self-dup'),
        )->assertCreated();

        clinicClearBrowserSession();
        $firstBody = adminCreatedDoctorApplicantBody([
            'specialty_id' => $specialty['id'],
            'syndicate_number' => 'SYN-DUP-1',
        ]);
        $admin = adminVerificationInsertAdmin('creator-dup');
        adminVerificationLogin($admin);
        $first = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            $firstBody,
            adminVerificationIdem('acd-dup-1'),
        )->assertCreated();

        $replayOwned = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            $firstBody,
            adminVerificationIdem('acd-dup-2'),
        );
        $replayOwned->assertOk()
            ->assertJsonPath('data.status', 'already_exists')
            ->assertJsonPath('data.doctor_id', $first->json('data.doctor_id'))
            ->assertJsonPath('data.case_id', $first->json('data.case_id'));

        adminVerificationLogout();
        $other = adminVerificationInsertAdmin('other-dup');
        adminVerificationLogin($other);
        $collision = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody([
                'specialty_id' => $specialty['id'],
                'national_id' => $firstBody['national_id'],
                'phone' => doctorsSyntheticIdentity()['phone'],
            ]),
            adminVerificationIdem('acd-dup-other'),
        );
        $collision->assertOk()->assertJsonPath('data.status', 'manual_review_required');
        expect($collision->json('data'))->not->toHaveKey('doctor_id');

        $againstSelf = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody([
                'specialty_id' => $specialty['id'],
                'national_id' => $self['payload']['national_id'],
            ]),
            adminVerificationIdem('acd-vs-self'),
        );
        $againstSelf->assertOk()->assertJsonPath('data.status', 'manual_review_required');
        expect(DB::table('doctor_profiles')->count())->toBe(2);
    });

    it('denies unauthenticated, patient, doctor, and low-assurance admin create', function () {
        clinicClearBrowserSession();
        $body = adminCreatedDoctorApplicantBody();

        $this->postJson('/api/v1/admin/doctor-applicants', $body)
            ->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');

        $patient = patientsActiveSession('acd-patient');
        $this->postJson(
            '/api/v1/admin/doctor-applicants',
            $body,
            patientsAuth($patient['token']) + adminVerificationIdem('acd-patient'),
        )->assertNotFound();

        $doctor = doctorsActiveSession('acd-doctor');
        $this->postJson(
            '/api/v1/admin/doctor-applicants',
            $body,
            doctorsAuth($doctor['token']) + adminVerificationIdem('acd-doctor'),
        )->assertNotFound();

        clinicClearBrowserSession();
        $weak = adminVerificationInsertAdmin('acd-weak');
        adminVerificationLogin($weak);
        DB::table('auth_sessions')->where('user_id', $weak['id'])->update([
            'assurance_level' => AssuranceLevel::Aal1Password->value,
        ]);
        adminVerificationPostJson('/api/v1/admin/doctor-applicants', $body, adminVerificationIdem('acd-aal1'))
            ->assertNotFound();
        expect(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())->toBeGreaterThan(0);
        expect(DB::table('doctor_profiles')->count())->toBe(0);
    });

    it('rejects BOLA across representing admins and self-review', function () {
        $creator = adminVerificationInsertAdmin('bola-creator');
        adminVerificationLogin($creator);
        $created = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody(['professional_display_name' => 'Dr Bola Applicant']),
            adminVerificationIdem('acd-bola-create'),
        )->assertCreated();
        $doctorId = (string) $created->json('data.doctor_id');
        $caseId = (string) $created->json('data.case_id');
        adminCreatedDoctorAttachEvidenceAndSubmit(
            $doctorId,
            $caseId,
            (int) $created->json('data.case_version'),
            (int) $created->json('data.profile_version'),
            'bola',
        );

        $version = (int) DB::table('verification_cases')->where('id', $caseId)->value('version');
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$caseId.'/claim',
            ['expected_case_version' => $version],
        )->assertNotFound();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$caseId.'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => $version,
            ],
            adminVerificationIdem('acd-bola-self-decide'),
        )->assertNotFound();

        adminVerificationLogout();
        $intruder = adminVerificationInsertAdmin('bola-intruder');
        adminVerificationLogin($intruder);
        adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-uploads',
            [
                'case_id' => $caseId,
                'requirement_code' => 'professional_id',
                'expected_size_bytes' => 32,
                'declared_media_type' => 'application/pdf',
            ],
            adminVerificationIdem('acd-bola-up'),
        )->assertNotFound();
        adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-submissions',
            verificationSubmitBody(1, 1),
            adminVerificationIdem('acd-bola-sub'),
        )->assertNotFound();

        $missing = app(IdentityGenerator::class)->next()->value;
        adminVerificationGetJson('/api/v1/admin/verification-cases/'.$missing)->assertNotFound();

        expect((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('verification_status'))
            ->toBe(DoctorVerificationStatus::PendingReview->value);
    });

    it('rejects mass assignment of server-owned verification internals', function () {
        $base = adminCreatedDoctorApplicantBody();
        $admin = adminVerificationInsertAdmin('mass');
        adminVerificationLogin($admin);

        foreach ([
            ['verification_status' => 'approved'],
            ['public_status' => 'listed'],
            ['bootstrap' => true],
            ['bootstrap_exempt' => true],
            ['capabilities' => ['clinical.record.read']],
            ['created_by_user_id' => $admin['id']],
            ['reviewer_id' => $admin['id']],
            ['version' => 99],
            ['approved_at' => '2026-01-01T00:00:00Z'],
            ['national_id_ciphertext' => 'x'],
            ['national_id_lookup_hmac' => 'x'],
            ['national_id_key_version' => 1],
            ['source_type' => 'self_onboarding'],
        ] as $i => $extra) {
            adminVerificationPostJson(
                '/api/v1/admin/doctor-applicants',
                $base + $extra,
                adminVerificationIdem('acd-mass-'.$i),
            )->assertUnprocessable()->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        }

        expect(DB::table('doctor_profiles')->count())->toBe(0);
    });

    it('replays the same Idempotency-Key without duplicating records', function () {
        $admin = adminVerificationInsertAdmin('idem');
        adminVerificationLogin($admin);
        $body = adminCreatedDoctorApplicantBody();
        $headers = adminVerificationIdem('acd-idem-same');

        $first = adminVerificationPostJson('/api/v1/admin/doctor-applicants', $body, $headers)->assertCreated();
        $second = adminVerificationPostJson('/api/v1/admin/doctor-applicants', $body, $headers)->assertCreated();

        expect($second->json('data.doctor_id'))->toBe($first->json('data.doctor_id'))
            ->and($second->json('data.case_id'))->toBe($first->json('data.case_id'))
            ->and(DB::table('doctor_profiles')->count())->toBe(1)
            ->and(DB::table('verification_cases')->where('applicant_id', $first->json('data.doctor_id'))->count())->toBe(1);
    });

    it('enforces optimistic versions on represented submit', function () {
        $admin = adminVerificationInsertAdmin('ver');
        adminVerificationLogin($admin);
        $created = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody(),
            adminVerificationIdem('acd-ver-create'),
        )->assertCreated();
        $doctorId = (string) $created->json('data.doctor_id');
        $caseId = (string) $created->json('data.case_id');
        verificationBindCleanScanner();
        $bytes = verificationMinimalPdf();
        $upload = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-uploads',
            [
                'case_id' => $caseId,
                'requirement_code' => 'professional_id',
                'expected_size_bytes' => strlen($bytes),
                'declared_media_type' => 'application/pdf',
            ],
            adminVerificationIdem('acd-ver-up'),
        )->assertCreated();
        verificationPutUploadBytes((string) $upload->json('data.upload_id'), $bytes, 'application/pdf');
        adminVerificationPostJson(
            '/api/v1/verification-uploads/'.$upload->json('data.upload_id').'/complete',
            [],
            adminVerificationIdem('acd-ver-done'),
        )->assertOk();
        verificationProcessUpload((string) $upload->json('data.upload_id'));

        adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-submissions',
            verificationSubmitBody(1, 99),
            adminVerificationIdem('acd-ver-bad'),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');

        adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants/'.$doctorId.'/verification-submissions',
            verificationSubmitBody((int) $created->json('data.case_version'), (int) $created->json('data.profile_version')),
            adminVerificationIdem('acd-ver-ok'),
        )->assertOk();
    });

    it('records reject and changes_requested through the existing decision path', function () {
        $creator = adminVerificationInsertAdmin('dec-creator');
        $reviewer = adminVerificationInsertAdmin('dec-reviewer');
        adminVerificationLogin($creator);
        $rejectedBody = adminCreatedDoctorApplicantBody(['professional_display_name' => 'Dr Reject Path']);
        $rejected = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            $rejectedBody,
            adminVerificationIdem('acd-rej-create'),
        )->assertCreated();
        adminCreatedDoctorAttachEvidenceAndSubmit(
            (string) $rejected->json('data.doctor_id'),
            (string) $rejected->json('data.case_id'),
            (int) $rejected->json('data.case_version'),
            (int) $rejected->json('data.profile_version'),
            'rej',
        );
        $changes = adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody(['professional_display_name' => 'Dr Changes Path']),
            adminVerificationIdem('acd-chg-create'),
        )->assertCreated();
        adminCreatedDoctorAttachEvidenceAndSubmit(
            (string) $changes->json('data.doctor_id'),
            (string) $changes->json('data.case_id'),
            (int) $changes->json('data.case_version'),
            (int) $changes->json('data.profile_version'),
            'chg',
        );

        adminVerificationLogout();
        adminVerificationLogin($reviewer);
        $rejClaim = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$rejected->json('data.case_id').'/claim',
            ['expected_case_version' => (int) DB::table('verification_cases')->where('id', $rejected->json('data.case_id'))->value('version')],
        )->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$rejected->json('data.case_id').'/decisions',
            [
                'decision' => 'rejected',
                'reason_code' => 'identity_mismatch',
                'expected_case_version' => (int) $rejClaim->json('data.case_version'),
            ],
            adminVerificationIdem('acd-rej-decide'),
        )->assertOk();

        $chgClaim = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$changes->json('data.case_id').'/claim',
            ['expected_case_version' => (int) DB::table('verification_cases')->where('id', $changes->json('data.case_id'))->value('version')],
        )->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$changes->json('data.case_id').'/decisions',
            [
                'decision' => 'changes_requested',
                'reason_code' => 'evidence_incomplete',
                'expected_case_version' => (int) $chgClaim->json('data.case_version'),
            ],
            adminVerificationIdem('acd-chg-decide'),
        )->assertOk();

        expect((string) DB::table('doctor_profiles')->where('id', $rejected->json('data.doctor_id'))->value('verification_status'))
            ->toBe(DoctorVerificationStatus::Rejected->value)
            ->and((string) DB::table('doctor_profiles')->where('id', $changes->json('data.doctor_id'))->value('verification_status'))
            ->toBe(DoctorVerificationStatus::ChangesRequested->value)
            ->and((string) DB::table('doctor_profiles')->where('id', $rejected->json('data.doctor_id'))->value('public_status'))
            ->toBe(DoctorPublicStatus::Hidden->value)
            ->and(adminCreatedDoctorProbeClinicCreate(
                adminCreatedDoctorUserId((string) $rejected->json('data.doctor_id')),
                'Rejected Admin Created Clinic',
            ))->toBe(404)
            ->and(DB::table('clinic_locations')->count())->toBe(0);
    });

    it('returns the seeded specialty catalogue and rejects inactive specialties', function () {
        doctorsSeedApprovedSpecialtyCatalogue();
        $doctor = doctorsActiveSession('spec-cat');
        $listed = $this->getJson('/api/v1/doctors/specialties', doctorsAuth($doctor['token']))->assertOk();
        $codes = collect($listed->json('data.specialties'))->pluck('code')->all();
        expect($codes)->toContain('general_practice')
            ->and($codes)->toContain('cardiology')
            ->and($codes)->toContain('pulmonology')
            ->and(count($listed->json('data.specialties')))->toBeGreaterThanOrEqual(12);

        clinicClearBrowserSession();
        $inactive = doctorsSeedSpecialty('inactive_admin_create', ['active' => false, 'sort_order' => 999]);
        $admin = adminVerificationInsertAdmin('spec-inactive');
        adminVerificationLogin($admin);
        adminVerificationPostJson(
            '/api/v1/admin/doctor-applicants',
            adminCreatedDoctorApplicantBody(['specialty_id' => $inactive['id']]),
            adminVerificationIdem('acd-inactive'),
        )->assertUnprocessable();
        expect(DB::table('doctor_profiles')->count())->toBe(0);
    });

    it('leaves existing self-registration behavior unchanged', function () {
        $specialty = doctorsSeedSpecialty('gp_self_reg');
        $session = doctorsActiveSession('self-reg');
        $response = $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']),
            doctorsAuth($session['token']) + doctorsIdem('acd-self-reg'),
        );
        $response->assertCreated()->assertJsonPath('data.status', 'profile_ready');
        $doctorId = (string) $response->json('data.doctor_id');
        $row = DB::table('doctor_profiles')->where('id', $doctorId)->first();
        expect((string) $row->source_type)->toBe(DoctorSourceType::SelfOnboarding->value)
            ->and((string) $row->created_by_user_id)->toBe($session['user_id'])
            ->and((string) $row->verification_status)->toBe(DoctorVerificationStatus::Draft->value)
            ->and((string) $row->public_status)->toBe(DoctorPublicStatus::Hidden->value);
    });
});

it('rolls back Admin-created doctor writes when profile audit fails', function () {
    $admin = adminVerificationInsertAdmin('rb-create');
    $actor = verificationOperatorActor($admin['id']);
    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'doctor.profile_created'));

    expect(fn () => app(VerificationService::class)->createAdminDoctorApplicant(
        $actor,
        adminCreatedDoctorApplicantBody(),
    ))->toThrow(TransientProviderFailure::class);

    expect(DB::table('doctor_profiles')->count())->toBe(0)
        ->and(DB::table('outbox_events')->where('event_type', 'doctor.profile_created')->count())->toBe(0);
});
