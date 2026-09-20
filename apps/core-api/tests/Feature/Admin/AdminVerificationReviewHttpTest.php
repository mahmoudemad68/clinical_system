<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\ReviewerQueueFilters;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('admin verification review HTTP', function () {
    it('denies unauthenticated, patient, doctor, and low-assurance admin queue access', function () {
        $pending = adminVerificationPendingCase('bola-queue');

        $this->getJson('/api/v1/admin/verification-cases')
            ->assertUnauthorized()
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');

        $patient = patientsActiveSession('bola-patient');
        $this->getJson('/api/v1/admin/verification-cases', ['Authorization' => 'Bearer '.$patient['token']])
            ->assertNotFound();

        $this->getJson('/api/v1/admin/verification-cases', doctorsAuth($pending['session']['token']))
            ->assertNotFound();

        $weak = adminVerificationInsertAdmin('weak');
        adminVerificationLogin($weak);
        DB::table('auth_sessions')->where('user_id', $weak['id'])->update([
            'assurance_level' => AssuranceLevel::Aal1Password->value,
        ]);
        adminVerificationGetJson('/api/v1/admin/verification-cases')->assertNotFound();
        expect(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())->toBeGreaterThan(0);
    });

    it('lists only pending doctor cases with a safe professional projection', function () {
        $pending = adminVerificationPendingCase('queue-visible');
        $draft = verificationPrepareDraftWithAvailableDocument('queue-draft');
        $decided = adminVerificationPendingCase('queue-decided');

        $admin = adminVerificationInsertAdmin('queue');
        adminVerificationLogin($admin);
        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$decided['case_id'].'/claim',
            ['expected_case_version' => $decided['case_version']],
        )->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$decided['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
            ],
            adminVerificationIdem('queue-decide'),
        )->assertOk();

        $unassigned = adminVerificationGetJson('/api/v1/admin/verification-cases')->assertOk();
        $ids = collect($unassigned->json('data'))->pluck('case_id')->all();
        expect($ids)->toContain($pending['case_id'])
            ->and($ids)->not->toContain($draft['case_id'])
            ->and($ids)->not->toContain($decided['case_id']);

        $item = collect($unassigned->json('data'))->firstWhere('case_id', $pending['case_id']);
        expect($item['case_type'])->toBe('doctor_verification')
            ->and($item['case_status'])->toBe('pending_review')
            ->and($item['assignment'])->toBe('unassigned')
            ->and($item['assigned_to_me'])->toBeFalse()
            ->and($item['doctor_id'])->toBe($pending['doctor_id'])
            ->and($item['professional_display_name'])->not->toBe('')
            ->and($item['specialty']['code'])->not->toBe('')
            ->and($item['specialty']['label_ar'])->not->toBe('')
            ->and($item['specialty']['label_en'])->not->toBe('')
            ->and($item['doctor_verification_status'])->toBe(DoctorVerificationStatus::PendingReview->value)
            ->and($item['doctor_public_status'])->toBe(DoctorPublicStatus::Hidden->value)
            ->and($item)->not->toHaveKey('assigned_reviewer_id')
            ->and($item)->not->toHaveKey('documents')
            ->and(json_encode($item, JSON_THROW_ON_ERROR))->not->toContain($pending['national_id'])
            ->and(json_encode($item, JSON_THROW_ON_ERROR))->not->toContain('hmac')
            ->and(json_encode($item, JSON_THROW_ON_ERROR))->not->toContain('national_id');

        $mine = adminVerificationGetJson('/api/v1/admin/verification-cases?assignment=mine')->assertOk();
        expect(collect($mine->json('data'))->pluck('case_id')->all())->not->toContain($pending['case_id']);

        $all = adminVerificationGetJson('/api/v1/admin/verification-cases?assignment=all')->assertOk();
        expect(collect($all->json('data'))->pluck('case_id')->all())->toContain($pending['case_id']);
    });

    it('pages the queue without duplicates and rejects unsafe cursors', function () {
        $first = adminVerificationPendingCase('page-a');
        $second = adminVerificationPendingCase('page-b');
        $third = adminVerificationPendingCase('page-c');
        DB::table('verification_cases')->where('id', $first['case_id'])->update(['submitted_at' => '2026-01-01 00:00:00+00']);
        DB::table('verification_cases')->where('id', $second['case_id'])->update(['submitted_at' => '2026-01-02 00:00:00+00']);
        DB::table('verification_cases')->where('id', $third['case_id'])->update(['submitted_at' => '2026-01-03 00:00:00+00']);

        $adminA = adminVerificationInsertAdmin('cursor-a');
        adminVerificationLogin($adminA);
        $page1 = adminVerificationGetJson('/api/v1/admin/verification-cases?limit=2')->assertOk();
        expect($page1->json('data.0.case_id'))->toBe($first['case_id'])
            ->and($page1->json('data.1.case_id'))->toBe($second['case_id'])
            ->and($page1->json('meta.pagination.has_more'))->toBeTrue()
            ->and($page1->json('meta.pagination.limit'))->toBe(2);
        $cursor = (string) $page1->json('meta.pagination.next');
        expect($cursor)->not->toBe('')
            ->and(strlen($cursor))->toBeLessThanOrEqual(512);

        $page2 = adminVerificationGetJson('/api/v1/admin/verification-cases?limit=2&cursor='.urlencode($cursor))->assertOk();
        $seen = array_merge($page1->json('data'), $page2->json('data'));
        $ids = collect($seen)->pluck('case_id')->all();
        expect($ids)->toContain($third['case_id'])
            ->and($ids)->toHaveCount(count(array_unique($ids)));

        adminVerificationGetJson('/api/v1/admin/verification-cases?limit=2&assignment=mine&cursor='.urlencode($cursor))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURSOR_INVALID');
        adminVerificationGetJson('/api/v1/admin/verification-cases?cursor=not-a-cursor')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURSOR_INVALID');
        adminVerificationGetJson('/api/v1/admin/verification-cases?cursor='.str_repeat('a', 513))
            ->assertStatus(422);
        $tampered = substr($cursor, 0, -2).'aa';
        adminVerificationGetJson('/api/v1/admin/verification-cases?limit=2&cursor='.urlencode($tampered))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURSOR_INVALID');
        $staleVersion = adminVerificationVersionedCursor(
            $adminA['id'],
            ['submitted_at' => (string) $page1->json('data.1.submitted_at'), 'case_id' => (string) $page1->json('data.1.case_id')],
            2,
        );
        adminVerificationGetJson('/api/v1/admin/verification-cases?limit=2&cursor='.urlencode($staleVersion))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURSOR_INVALID');
        adminVerificationGetJson('/api/v1/admin/verification-cases?limit=101')->assertStatus(422);
        adminVerificationGetJson('/api/v1/admin/verification-cases?sort=id')->assertStatus(422);

        adminVerificationLogout();
        $adminB = adminVerificationInsertAdmin('cursor-b');
        adminVerificationLogin($adminB);
        adminVerificationGetJson('/api/v1/admin/verification-cases?limit=2&cursor='.urlencode($cursor))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'CURSOR_INVALID');
    });

    it('hides documents until the current reviewer claims the case', function () {
        $pending = adminVerificationPendingCase('detail-docs');
        $admin = adminVerificationInsertAdmin('detail');
        adminVerificationLogin($admin);

        $before = adminVerificationGetJson('/api/v1/admin/verification-cases/'.$pending['case_id'])->assertOk();
        expect($before->json('data.documents'))->toBe([])
            ->and($before->json('data.assigned_to_me'))->toBeFalse()
            ->and($before->json('data.assignment'))->toBe('unassigned')
            ->and($before->json('data'))->not->toHaveKey('assigned_reviewer_id')
            ->and(json_encode($before->json('data'), JSON_THROW_ON_ERROR))->not->toContain($pending['national_id'])
            ->and(json_encode($before->json('data'), JSON_THROW_ON_ERROR))->not->toContain($pending['object_id']);

        adminVerificationGetJson('/api/v1/admin/verification-cases/'.Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01')->value)
            ->assertNotFound();

        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version'], 'reviewer_id' => $admin['id']],
        )->assertStatus(422);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => 1],
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');

        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertOk();
        expect($claimed->json('data.assigned_to_me'))->toBeTrue()
            ->and($claimed->json('data.assignment'))->toBe('mine')
            ->and($claimed->json('data.documents'))->not->toBe([])
            ->and($claimed->json('data.documents.0.status'))->toBe('available')
            ->and($claimed->json('data.documents.0.scan_status'))->toBe('clean')
            ->and($claimed->json('data.documents.0'))->not->toHaveKey('object_id')
            ->and((string) DB::table('verification_cases')->where('id', $pending['case_id'])->value('status'))->toBe('pending_review')
            ->and((string) DB::table('doctor_profiles')->where('id', $pending['doctor_id'])->value('verification_status'))->toBe('pending_review');

        $mine = adminVerificationGetJson('/api/v1/admin/verification-cases?assignment=mine')->assertOk();
        expect(collect($mine->json('data'))->pluck('case_id')->all())->toContain($pending['case_id']);
        $unassignedAfter = adminVerificationGetJson('/api/v1/admin/verification-cases')->assertOk();
        expect(collect($unassignedAfter->json('data'))->pluck('case_id')->all())->not->toContain($pending['case_id']);

        $replay = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertOk();
        expect($replay->json('data.case_version'))->toBe($claimed->json('data.case_version'));

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => 1],
        )->assertOk();

        adminVerificationLogout();
        $other = adminVerificationInsertAdmin('detail-other');
        adminVerificationLogin($other);
        $foreign = adminVerificationGetJson('/api/v1/admin/verification-cases/'.$pending['case_id'])->assertOk();
        expect($foreign->json('data.documents'))->toBe([])
            ->and($foreign->json('data.assigned_to_me'))->toBeFalse()
            ->and($foreign->json('data.assignment'))->toBe('other');
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => (int) $claimed->json('data.case_version')],
        )->assertStatus(409);
    });

    it('records approve, reject, and changes_requested without listing or notes leakage', function () {
        $approve = adminVerificationPendingCase('decide-ok');
        $reject = adminVerificationPendingCase('decide-no');
        $changes = adminVerificationPendingCase('decide-ch');
        $admin = adminVerificationInsertAdmin('decide');
        adminVerificationLogin($admin);

        $claimA = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$approve['case_id'].'/claim', [
            'expected_case_version' => $approve['case_version'],
        ])->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$approve['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimA->json('data.case_version'),
                'notes' => 'internal reviewer note must stay encrypted',
                'public_status' => 'listed',
            ],
            adminVerificationIdem('decide-ok'),
        )->assertStatus(422);

        $approved = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$approve['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimA->json('data.case_version'),
                'notes' => 'internal reviewer note must stay encrypted',
            ],
            adminVerificationIdem('decide-ok'),
        )->assertOk();
        expect($approved->json('data.decision'))->toBe('approved')
            ->and($approved->json('data'))->not->toHaveKey('notes')
            ->and((string) DB::table('doctor_profiles')->where('id', $approve['doctor_id'])->value('verification_status'))->toBe('approved')
            ->and((string) DB::table('doctor_profiles')->where('id', $approve['doctor_id'])->value('public_status'))->toBe('hidden')
            ->and((string) DB::table('verification_cases')->where('id', $approve['case_id'])->value('status'))->toBe('approved')
            ->and(DB::table('verification_decisions')->where('case_id', $approve['case_id'])->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(1)
            ->and((string) verificationOutboxPayload('doctor.verification_decided'))->not->toContain('internal reviewer note');

        $notes = DB::table('verification_decisions')->where('case_id', $approve['case_id'])->value('notes_ciphertext');
        expect($notes)->not->toBeNull()
            ->and(json_encode($notes, JSON_THROW_ON_ERROR))->not->toContain('internal reviewer note must stay encrypted');

        $replay = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$approve['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimA->json('data.case_version'),
                'notes' => 'internal reviewer note must stay encrypted',
            ],
            adminVerificationIdem('decide-ok'),
        )->assertOk();
        expect($replay->headers->get('Idempotent-Replay'))->toBe('true')
            ->and(DB::table('verification_decisions')->where('case_id', $approve['case_id'])->count())->toBe(1);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$approve['case_id'].'/decisions',
            [
                'decision' => 'rejected',
                'reason_code' => 'identity_mismatch',
                'expected_case_version' => (int) $claimA->json('data.case_version'),
            ],
            adminVerificationIdem('decide-ok'),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');

        $applicant = $this->getJson(
            '/api/v1/doctors/me/verification-status',
            doctorsAuth($approve['session']['token']),
        )->assertOk();
        expect($applicant->json('data.decision'))->toBe('approved')
            ->and($applicant->json('data.reason_code'))->toBe('approved')
            ->and($applicant->json('data'))->not->toHaveKey('notes')
            ->and(json_encode($applicant->json('data'), JSON_THROW_ON_ERROR))->not->toContain('internal reviewer note');

        $claimR = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$reject['case_id'].'/claim', [
            'expected_case_version' => $reject['case_version'],
        ])->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$reject['case_id'].'/decisions',
            [
                'decision' => 'rejected',
                'reason_code' => 'identity_mismatch',
                'expected_case_version' => (int) $claimR->json('data.case_version'),
            ],
            adminVerificationIdem('decide-no'),
        )->assertOk();
        expect((string) DB::table('doctor_profiles')->where('id', $reject['doctor_id'])->value('verification_status'))->toBe('rejected');

        $claimC = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$changes['case_id'].'/claim', [
            'expected_case_version' => $changes['case_version'],
        ])->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$changes['case_id'].'/decisions',
            [
                'decision' => 'changes_requested',
                'reason_code' => 'documents_illegible',
                'expected_case_version' => (int) $claimC->json('data.case_version'),
            ],
            adminVerificationIdem('decide-ch'),
        )->assertOk();
        expect((string) DB::table('doctor_profiles')->where('id', $changes['doctor_id'])->value('verification_status'))->toBe('changes_requested');

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$changes['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'fraud_internal',
                'expected_case_version' => (int) $claimC->json('data.case_version') + 1,
            ],
            adminVerificationIdem('decide-bad-reason'),
        )->assertStatus(422);
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$changes['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'documents_illegible',
                'expected_case_version' => (int) $claimC->json('data.case_version') + 1,
            ],
            adminVerificationIdem('decide-bad-pair'),
        )->assertStatus(422);
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$changes['case_id'].'/decisions',
            [
                'decision' => 'activate_clinical',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimC->json('data.case_version') + 1,
            ],
            adminVerificationIdem('decide-unknown'),
        )->assertStatus(422);

        $historical = adminVerificationGetJson('/api/v1/admin/verification-cases/'.$approve['case_id'])->assertOk();
        expect($historical->json('data.case_status'))->toBe('approved');
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$approve['case_id'].'/claim',
            ['expected_case_version' => (int) $historical->json('data.case_version')],
        )->assertStatus(409);
    });

    it('denies a second reviewer from deciding an assigned case', function () {
        $pending = adminVerificationPendingCase('bola-decide');
        $admin = adminVerificationInsertAdmin('owner');
        adminVerificationLogin($admin);
        $claimed = adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertOk();
        adminVerificationLogout();

        $other = adminVerificationInsertAdmin('intruder');
        adminVerificationLogin($other);
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
            ],
            adminVerificationIdem('bola-decide'),
        )->assertNotFound();
        expect(DB::table('verification_decisions')->where('case_id', $pending['case_id'])->count())->toBe(0);
    });

    it('denies self-review through the Admin HTTP slice', function () {
        $pending = adminVerificationPendingCase('self-http');
        $admin = adminVerificationInsertAdmin('self');
        DB::table('doctor_profiles')->where('id', $pending['doctor_id'])->update([
            'user_id' => $admin['id'],
        ]);
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertNotFound();
        expect((string) DB::table('verification_cases')->where('id', $pending['case_id'])->value('assigned_reviewer_id'))->toBe('');
    });

    it('denies cookie state changes without CSRF and password-change-required admins', function () {
        $pending = adminVerificationPendingCase('csrf-pw');
        $admin = adminVerificationInsertAdmin('csrf-pw');
        adminVerificationLogin($admin);

        adminVerificationPinCookie();
        $this->postJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertStatus(403)->assertJsonPath('errors.0.code', 'CSRF_MISMATCH');

        DB::table('users')->where('id', $admin['id'])->update(['password_must_change' => true]);
        adminVerificationGetJson('/api/v1/admin/verification-cases')->assertNotFound();
        adminVerificationPostJson('/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim', [
            'expected_case_version' => $pending['case_version'],
        ])->assertNotFound();
        expect((string) DB::table('verification_cases')->where('id', $pending['case_id'])->value('assigned_reviewer_id'))->toBe('');
    });

    it('denies an admin actor that lacks verification.case.review', function () {
        $pending = adminVerificationPendingCase('no-cap');
        $admin = verificationSeedAdmin('no-cap');
        $actor = new ActorContext(
            Identifier::fromTrusted($admin['user_id']),
            AccountType::Admin,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal2Totp,
            1,
            null,
            Identifier::fromTrusted($admin['user_id']),
            [],
            [],
        );
        $filters = new ReviewerQueueFilters(
            'unassigned',
            VerificationCaseType::DoctorVerification,
            VerificationCaseStatus::PendingReview,
            25,
        );
        expect(fn () => app(VerificationService::class)->listReviewQueue($actor, $filters, null))
            ->toThrow(AuthorizationDenied::class);
        expect(fn () => app(VerificationService::class)->claimCase(
            $actor,
            Identifier::fromTrusted($pending['case_id']),
            $pending['case_version'],
        ))->toThrow(AuthorizationDenied::class);
        expect(DB::table('audit_events')->where('event_name', 'auth.privileged_authorization_denied')->count())->toBeGreaterThan(0);
    });
});

describe('admin verification review service queue', function () {
    it('uses the reviewer queue keyset index', function () {
        $admin = adminVerificationInsertAdmin('explain');
        adminVerificationLogin($admin);
        adminVerificationPendingCase('explain-case');
        DB::statement('SET enable_seqscan = off');
        $json = DB::select(
            'EXPLAIN (FORMAT JSON) SELECT id FROM verification_cases WHERE case_type = ? AND status = ? ORDER BY submitted_at ASC, id ASC LIMIT 26',
            ['doctor_verification', 'pending_review'],
        );
        DB::statement('SET enable_seqscan = on');
        $plan = json_encode($json, JSON_THROW_ON_ERROR);
        expect($plan)->not->toContain('Seq Scan')
            ->and($plan)->toMatch('/verification_cases_reviewer_queue_keyset_index|verification_cases_queue_index/');
    });
});
