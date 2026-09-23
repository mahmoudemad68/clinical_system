<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs the synthetic doctor onboarding, verification, admin review, and resubmission flow', function () {
    verificationBindCleanScanner();
    $specialtyAlpha = doctorsSeedSpecialty('e2e_alpha', ['sort_order' => 20, 'label_en' => 'Alpha']);
    $specialtyBeta = doctorsSeedSpecialty('e2e_beta', ['sort_order' => 10, 'label_en' => 'Beta']);
    $session = doctorsActiveSession('e2e-doc');
    $nationalId = $session['payload']['national_id'];
    $syndicate = 'SYN-E2E-11';

    $catalogue = $this->getJson('/api/v1/doctors/specialties', doctorsAuth($session['token']));
    $catalogue->assertOk();
    $ids = collect($catalogue->json('data.specialties'))->pluck('specialty_id')->all();
    expect($ids)->toContain($specialtyBeta['id'])
        ->and($ids)->toContain($specialtyAlpha['id']);
    expect($catalogue->getContent())->not->toContain($nationalId)
        ->and($catalogue->getContent())->not->toContain($syndicate);

    $onboarded = $this->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($nationalId, $specialtyBeta['id'], $syndicate),
        doctorsAuth($session['token']) + doctorsIdem('e2e-onboard'),
    )->assertCreated()
        ->assertJsonPath('data.status', 'profile_ready');
    expect($onboarded->getContent())->not->toContain($nationalId)
        ->and($onboarded->getContent())->not->toContain($syndicate);

    $profile = $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($session['token']));
    $profile->assertOk()
        ->assertJsonPath('data.doctor_id', $onboarded->json('data.doctor_id'))
        ->assertJsonPath('data.professional_display_name', 'Synthetic Doctor')
        ->assertJsonPath('data.specialty_id', $specialtyBeta['id'])
        ->assertJsonPath('data.verification_status', DoctorVerificationStatus::Draft->value)
        ->assertJsonPath('data.public_status', DoctorPublicStatus::Hidden->value)
        ->assertJsonMissingPath('data.national_id')
        ->assertJsonMissingPath('data.syndicate_number');
    expect($profile->getContent())->not->toContain($nationalId)
        ->and($profile->getContent())->not->toContain($syndicate);

    $opened = $this->postJson(
        '/api/v1/doctors/me/verification-cases',
        [],
        doctorsAuth($session['token']) + doctorsIdem('e2e-open'),
    )->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.case_status', 'draft')
        ->assertJsonMissingPath('data.national_id')
        ->assertJsonMissingPath('data.documents')
        ->assertJsonMissingPath('data.reviewer_id');
    $caseId = (string) $opened->json('data.case_id');
    expect(strlen((string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference')))->toBeLessThanOrEqual(255);

    $created = verificationCreateUploadIntent(
        ['session' => $session],
        $caseId,
        'e2e-upload',
    );
    $created['response']->assertCreated();
    expect($created['response']->getContent())->not->toContain($created['storage_locator'])
        ->and($created['response']->json('data'))->not->toHaveKey('object_id');
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], $created['mime']);
    $this->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        doctorsAuth($session['token']) + doctorsIdem('e2e-complete'),
    )->assertOk();
    verificationProcessUpload($created['upload_id']);
    $uploadStatus = $this->getJson(
        '/api/v1/verification-uploads/'.$created['upload_id'],
        doctorsAuth($session['token']),
    )->assertOk()
        ->assertJsonPath('data.state', 'available');
    expect($uploadStatus->getContent())->not->toContain($created['storage_locator'])
        ->and($uploadStatus->getContent())->not->toContain($nationalId);

    $submitted = $this->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody((int) $opened->json('data.case_version'), (int) $opened->json('data.profile_version')),
        doctorsAuth($session['token']) + doctorsIdem('e2e-submit'),
    )->assertOk()
        ->assertJsonPath('data.case_status', 'pending_review')
        ->assertJsonPath('data.profile_verification_status', DoctorVerificationStatus::PendingReview->value);

    $admin = adminVerificationInsertAdmin('e2e-review');
    adminVerificationLogin($admin);
    $queue = adminVerificationGetJson('/api/v1/admin/verification-cases')->assertOk();
    expect($queue->json('data.0.case_id'))->toBe($caseId)
        ->and($queue->getContent())->not->toContain($nationalId)
        ->and($queue->getContent())->not->toContain($syndicate)
        ->and($queue->getContent())->not->toContain($created['object_id']);

    $claimed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$caseId.'/claim', [
        'expected_case_version' => (int) $submitted->json('data.case_version'),
    ])->assertOk();
    $documentId = (string) $claimed->json('data.documents.0.document_id');
    expect($documentId)->not->toBe('');

    $grant = adminVerificationPostJson(
        '/api/v1/admin/verification-cases/'.$caseId.'/documents/'.$documentId.'/access',
        [],
    )->assertOk();
    expect($grant->json('data'))->not->toHaveKey('storage_locator')
        ->and($grant->json('data'))->not->toHaveKey('object_id')
        ->and($grant->getContent())->not->toContain($nationalId);

    $approved = adminVerificationPostJson(
        '/api/v1/admin/verification-cases/'.$caseId.'/decisions',
        [
            'decision' => 'approved',
            'reason_code' => 'approved',
            'expected_case_version' => (int) $claimed->json('data.case_version'),
            'notes' => 'reviewer-private-notes-must-not-cross',
        ],
        adminVerificationIdem('e2e-approve'),
    )->assertOk()
        ->assertJsonPath('data.decision', 'approved');
    expect($approved->json('data'))->not->toHaveKey('notes')
        ->and($approved->getContent())->not->toContain('reviewer-private-notes-must-not-cross');

    $refreshed = $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($session['token']));
    $refreshed->assertOk()
        ->assertJsonPath('data.decision', 'approved')
        ->assertJsonPath('data.profile_verification_status', DoctorVerificationStatus::Approved->value)
        ->assertJsonPath('data.reason_code', 'approved');
    expect($refreshed->getContent())->not->toContain('reviewer-private-notes-must-not-cross')
        ->and($refreshed->getContent())->not->toContain($nationalId);

    $ownProfile = $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($session['token']));
    $ownProfile->assertOk()
        ->assertJsonPath('data.verification_status', DoctorVerificationStatus::Approved->value)
        ->assertJsonPath('data.public_status', DoctorPublicStatus::Hidden->value);
});

it('opens a new doctor verification case after changes_requested without mutating the previous case', function () {
    verificationBindCleanScanner();
    $onboarded = verificationOnboardDoctor('e2e-rework');
    $opened = verificationOpenHttp($onboarded, 'e2e-rework-open');
    $opened->assertOk();
    $firstCaseId = (string) $opened->json('data.case_id');
    $created = verificationCreateUploadIntent($onboarded, $firstCaseId, 'e2e-rework-up');
    $created['response']->assertCreated();
    verificationPutUploadBytes($created['upload_id'], $created['bytes'], $created['mime']);
    $this->postJson(
        '/api/v1/verification-uploads/'.$created['upload_id'].'/complete',
        [],
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('e2e-rework-done'),
    )->assertOk();
    verificationProcessUpload($created['upload_id']);
    $this->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody((int) $opened->json('data.case_version'), (int) $opened->json('data.profile_version')),
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('e2e-rework-sub'),
    )->assertOk();

    $admin = adminVerificationInsertAdmin('e2e-rework');
    adminVerificationLogin($admin);
    $claimed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$firstCaseId.'/claim', [
        'expected_case_version' => (int) $opened->json('data.case_version') + 1,
    ])->assertOk();
    $decided = adminVerificationPostJson(
        '/api/v1/admin/verification-cases/'.$firstCaseId.'/decisions',
        [
            'decision' => 'changes_requested',
            'reason_code' => 'documents_illegible',
            'expected_case_version' => (int) $claimed->json('data.case_version'),
            'notes' => 'reviewer-private-notes-must-not-cross',
        ],
        adminVerificationIdem('e2e-rework-decide'),
    )->assertOk();
    expect($decided->json('data'))->not->toHaveKey('notes');

    $status = $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($onboarded['session']['token']));
    $status->assertOk()
        ->assertJsonPath('data.profile_verification_status', DoctorVerificationStatus::ChangesRequested->value)
        ->assertJsonPath('data.reason_code', 'documents_illegible');
    expect($status->getContent())->not->toContain('reviewer-private-notes-must-not-cross')
        ->and($status->getContent())->not->toContain($onboarded['national_id']);

    $fresh = verificationOpenHttp($onboarded, 'e2e-rework-open-2');
    $fresh->assertOk()
        ->assertJsonPath('data.case_status', 'draft');
    expect($fresh->json('data.case_id'))->not->toBe($firstCaseId)
        ->and((string) DB::table('verification_cases')->where('id', $firstCaseId)->value('status'))->toBe('changes_requested');

    $second = verificationCreateUploadIntent($onboarded, (string) $fresh->json('data.case_id'), 'e2e-rework-up-2');
    $second['response']->assertCreated();
    expect($second['upload_id'])->not->toBe($created['upload_id']);
    verificationPutUploadBytes($second['upload_id'], $second['bytes'], $second['mime']);
    $this->postJson(
        '/api/v1/verification-uploads/'.$second['upload_id'].'/complete',
        [],
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('e2e-rework-done-2'),
    )->assertOk();
    verificationProcessUpload($second['upload_id']);
    $this->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody((int) $fresh->json('data.case_version'), (int) $fresh->json('data.profile_version')),
        doctorsAuth($onboarded['session']['token']) + doctorsIdem('e2e-rework-sub-2'),
    )->assertOk()
        ->assertJsonPath('data.case_status', 'pending_review');
});
