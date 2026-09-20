<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Services\Time\FrozenClock;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\ReviewerDocumentUrlSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('admin pharmacy verification review HTTP', function () {
    it('keeps the default queue on doctor_verification and lists pharmacy cases only when asked', function () {
        $doctor = adminVerificationPendingCase('q-doc');
        $pharmacy = pharmacyVerificationPendingCase('q-pharm');
        $admin = adminVerificationInsertAdmin('q-mix');
        adminVerificationLogin($admin);

        $default = adminVerificationGetJson('/api/v1/admin/verification-cases')->assertOk();
        $defaultIds = collect($default->json('data'))->pluck('case_id')->all();
        expect($defaultIds)->toContain($doctor['case_id'])
            ->and($defaultIds)->not->toContain($pharmacy['case_id']);

        $pharmacyQueue = adminVerificationGetJson('/api/v1/admin/verification-cases?case_type=pharmacy_verification')->assertOk();
        $ids = collect($pharmacyQueue->json('data'))->pluck('case_id')->all();
        expect($ids)->toContain($pharmacy['case_id'])
            ->and($ids)->not->toContain($doctor['case_id']);

        $item = collect($pharmacyQueue->json('data'))->firstWhere('case_id', $pharmacy['case_id']);
        expect($item['case_type'])->toBe('pharmacy_verification')
            ->and($item['applicant_type'])->toBe('pharmacy')
            ->and($item['case_status'])->toBe('pending_review')
            ->and($item['organization_id'])->toBe($pharmacy['organization_id'])
            ->and($item['verification_status'])->toBe(PharmacyVerificationStatus::PendingReview->value)
            ->and($item['status'])->toBe(PharmacyOrganizationStatus::Pending->value)
            ->and($item['initial_branch']['branch_id'])->toBe($pharmacy['branch_id'])
            ->and($item)->not->toHaveKey('doctor_id')
            ->and($item)->not->toHaveKey('assigned_reviewer_id')
            ->and($item)->not->toHaveKey('documents');
        pharmacyVerificationAssertNoCanaries(json_encode($item, JSON_THROW_ON_ERROR), $pharmacy);
        expect(json_encode($item, JSON_THROW_ON_ERROR))->not->toContain($pharmacy['object_id']);
    });

    it('hides pharmacy documents until claim and returns a safe pharmacy projection', function () {
        $pending = pharmacyVerificationPendingCase('detail');
        $admin = adminVerificationInsertAdmin('detail-p');
        adminVerificationLogin($admin);

        $before = adminVerificationGetJson('/api/v1/admin/verification-cases/'.$pending['case_id'])->assertOk();
        expect($before->json('data.documents'))->toBe([])
            ->and($before->json('data.assigned_to_me'))->toBeFalse()
            ->and($before->json('data.applicant_type'))->toBe('pharmacy')
            ->and($before->json('data.organization_id'))->toBe($pending['organization_id'])
            ->and($before->json('data'))->not->toHaveKey('doctor_id');
        pharmacyVerificationAssertNoCanaries((string) $before->getContent(), $pending);

        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version'], 'reviewer_id' => $admin['id']],
        )->assertStatus(422);

        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertOk();
        expect($claimed->json('data.assigned_to_me'))->toBeTrue()
            ->and($claimed->json('data.documents'))->not->toBe([])
            ->and($claimed->json('data.documents.0.requirement_code'))->toBe('organization_registration_evidence')
            ->and($claimed->json('data.documents.0.status'))->toBe('available')
            ->and($claimed->json('data.documents.0.scan_status'))->toBe('clean')
            ->and($claimed->json('data.documents.0'))->not->toHaveKey('object_id');
        pharmacyVerificationAssertNoCanaries((string) $claimed->getContent(), $pending);

        pharmacyVerificationAssertAggregate(
            $pending['organization_id'],
            PharmacyVerificationStatus::PendingReview->value,
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
    });

    it('approves a pharmacy case once and never grants business capabilities', function () {
        $pending = pharmacyVerificationPendingCase('decide');
        $admin = adminVerificationInsertAdmin('decide-p');
        adminVerificationLogin($admin);
        $claimed = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertOk();

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
                'organization_status' => 'active',
                'capabilities' => ['inventory.adjust'],
            ],
            adminVerificationIdem('p-decide-mass'),
        )->assertStatus(422);

        $approved = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
                'notes' => 'pharmacy reviewer note must stay encrypted',
            ],
            adminVerificationIdem('p-decide-ok'),
        )->assertOk();
        expect($approved->json('data.decision'))->toBe('approved')
            ->and($approved->json('data'))->not->toHaveKey('notes');
        pharmacyVerificationAssertNoCanaries((string) $approved->getContent(), $pending);

        pharmacyVerificationAssertAggregate(
            $pending['organization_id'],
            PharmacyVerificationStatus::Approved->value,
            PharmacyOrganizationStatus::Active->value,
            'active',
            PharmacyMembershipStatus::Active->value,
        );
        expect(DB::table('verification_decisions')->where('case_id', $pending['case_id'])->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(1);

        $replay = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/decisions',
            [
                'decision' => 'approved',
                'reason_code' => 'approved',
                'expected_case_version' => (int) $claimed->json('data.case_version'),
                'notes' => 'pharmacy reviewer note must stay encrypted',
            ],
            adminVerificationIdem('p-decide-ok'),
        )->assertOk();
        expect($replay->headers->get('Idempotent-Replay'))->toBe('true')
            ->and(DB::table('verification_decisions')->where('case_id', $pending['case_id'])->count())->toBe(1);

        $caps = $this->getJson('/api/v1/me/capabilities', pharmaciesAuth($pending['session']['token']))->assertOk();
        foreach (pharmacyVerificationForbiddenCapabilities() as $capability) {
            expect($caps->json('data.capabilities'))->not->toContain($capability);
        }
    });

    it('denies an admin from self-reviewing a pharmacy organization they own', function () {
        $pending = pharmacyVerificationPendingCase('self-http');
        $admin = adminVerificationInsertAdmin('self-p');
        DB::table('pharmacy_memberships')->where('id', $pending['membership_id'])->update([
            'user_id' => $admin['id'],
        ]);
        adminVerificationLogin($admin);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertNotFound();

        expect(DB::table('verification_cases')->where('id', $pending['case_id'])->value('assigned_reviewer_id'))->toBeNull()
            ->and(DB::table('verification_decisions')->count())->toBe(0);
        pharmacyVerificationAssertAggregate(
            $pending['organization_id'],
            PharmacyVerificationStatus::PendingReview->value,
            PharmacyOrganizationStatus::Pending->value,
            'pending',
            PharmacyMembershipStatus::Pending->value,
        );
    });

    it('issues pharmacy document access only to the assigned reviewer and denies expired or rebound grants', function () {
        $pending = pharmacyVerificationPendingCanonicalCase('doc-p');
        $other = pharmacyVerificationPendingCanonicalCase('doc-other');
        $admin = adminVerificationInsertAdmin('doc-p');
        $stranger = adminVerificationInsertAdmin('doc-stranger');
        adminVerificationLogin($admin);

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertNotFound();

        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/claim',
            ['expected_case_version' => $pending['case_version']],
        )->assertOk();
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$other['case_id'].'/claim',
            ['expected_case_version' => $other['case_version']],
        )->assertOk();

        $grant = adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertOk();
        $url = (string) $grant->json('data.url');
        adminVerificationAssertApplicationDownloadUrl($url, $pending);
        pharmacyVerificationAssertNoCanaries($url, $pending);
        pharmacyVerificationAssertNoCanaries((string) $grant->getContent(), $pending);
        expect($url)->not->toContain($pending['canonical_storage_locator'])
            ->and(adminVerificationAuditJson('verification.document_access_granted'))->not->toContain($url);

        adminVerificationDownload($url)->assertOk();

        adminVerificationLogout();
        adminVerificationLogin($stranger);
        adminVerificationPostJson(
            '/api/v1/admin/verification-cases/'.$pending['case_id'].'/documents/'.$pending['document_id'].'/access',
            [],
        )->assertNotFound();
        adminVerificationDownload($url)->assertOk();

        $frozen = new FrozenClock(app(Clock::class)->now());
        app()->instance(Clock::class, $frozen);
        $reboundDocument = str_replace($pending['document_id'], $other['document_id'], $url);
        adminVerificationDownload($reboundDocument)->assertNotFound();
        $reboundCase = str_replace($pending['case_id'], $other['case_id'], $url);
        adminVerificationDownload($reboundCase)->assertNotFound();

        $frozen->advance(121);
        adminVerificationDownload($url)->assertNotFound();

        $signer = app(ReviewerDocumentUrlSigner::class);
        $expires = app(Clock::class)->now()->modify('+120 seconds');
        adminVerificationDownload($signer->sign(
            Identifier::fromTrusted($other['case_id']),
            Identifier::fromTrusted($pending['document_id']),
            $expires,
        ))->assertNotFound();
    });
});
