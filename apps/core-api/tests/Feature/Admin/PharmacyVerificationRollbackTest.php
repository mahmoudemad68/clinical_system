<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationService;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{admin: array{user_id: string, actor: ActorContext}, version: int, case_id: string, organization_id: string, branch_id: string, membership_id: string}
 */
function pharmacyVerificationRollbackClaimed(string $key): array
{
    $pending = pharmacyVerificationPendingCase($key);
    $claimed = pharmacyVerificationClaimPending($pending, $key);

    return [
        'admin' => $claimed['admin'],
        'version' => $claimed['version'],
        'case_id' => $pending['case_id'],
        'organization_id' => $pending['organization_id'],
        'branch_id' => $pending['branch_id'],
        'membership_id' => $pending['membership_id'],
    ];
}

function pharmacyVerificationAssertDecisionRolledBack(string $caseId, string $organizationId): void
{
    expect(DB::table('verification_decisions')->where('case_id', $caseId)->count())->toBe(0)
        ->and((string) DB::table('verification_cases')->where('id', $caseId)->value('status'))->toBe('pending_review')
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(0);
    pharmacyVerificationAssertAggregate(
        $organizationId,
        PharmacyVerificationStatus::PendingReview->value,
        PharmacyOrganizationStatus::Pending->value,
        'pending',
        PharmacyMembershipStatus::Pending->value,
    );
}

it('rolls back when PostgreSQL rejects the pharmacy decision insert', function () {
    $claimed = pharmacyVerificationRollbackClaimed('p-rb-insert');
    DB::statement('
        CREATE OR REPLACE FUNCTION clinic_test_fail_pharmacy_verification_decision() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION \'clinic_test_fail_pharmacy_verification_decision\';
        END;
        $$ LANGUAGE plpgsql
    ');
    DB::statement('
        CREATE TRIGGER clinic_test_fail_pharmacy_verification_decision
        BEFORE INSERT ON verification_decisions
        FOR EACH ROW EXECUTE FUNCTION clinic_test_fail_pharmacy_verification_decision()
    ');

    try {
        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($claimed['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        ))->toThrow(QueryException::class);
        pharmacyVerificationAssertDecisionRolledBack($claimed['case_id'], $claimed['organization_id']);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS clinic_test_fail_pharmacy_verification_decision ON verification_decisions');
        DB::statement('DROP FUNCTION IF EXISTS clinic_test_fail_pharmacy_verification_decision()');
    }
});

it('rolls back the decision when the pharmacy aggregate transition is no longer legal', function () {
    $claimed = pharmacyVerificationRollbackClaimed('p-rb-org');
    DB::table('pharmacy_organizations')->where('id', $claimed['organization_id'])->update([
        'verification_status' => PharmacyVerificationStatus::Draft->value,
        'status' => PharmacyOrganizationStatus::Draft->value,
    ]);

    expect(fn () => app(VerificationService::class)->recordDecision(
        $claimed['admin']['actor'],
        Identifier::fromTrusted($claimed['case_id']),
        'approved',
        'approved',
        $claimed['version'],
    ))->toThrow(StateConflict::class);

    expect(DB::table('verification_decisions')->where('case_id', $claimed['case_id'])->count())->toBe(0)
        ->and((string) DB::table('verification_cases')->where('id', $claimed['case_id'])->value('status'))->toBe('pending_review')
        ->and((string) DB::table('pharmacy_organizations')->where('id', $claimed['organization_id'])->value('status'))->not->toBe('active')
        ->and((string) DB::table('pharmacy_branches')->where('id', $claimed['branch_id'])->value('status'))->not->toBe('active')
        ->and((string) DB::table('pharmacy_memberships')->where('id', $claimed['membership_id'])->value('status'))->not->toBe('active')
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.verification_decided')->count())->toBe(0);
});

it('rolls back when pharmacy decision audit append fails', function () {
    $claimed = pharmacyVerificationRollbackClaimed('p-rb-audit');
    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'verification.decision_recorded'));

    expect(fn () => app(VerificationService::class)->recordDecision(
        $claimed['admin']['actor'],
        Identifier::fromTrusted($claimed['case_id']),
        'approved',
        'approved',
        $claimed['version'],
    ))->toThrow(TransientProviderFailure::class);

    pharmacyVerificationAssertDecisionRolledBack($claimed['case_id'], $claimed['organization_id']);
});

it('rolls back pharmacy aggregate changes when the outbox insert fails', function () {
    $claimed = pharmacyVerificationRollbackClaimed('p-rb-outbox');
    DB::statement('
        CREATE OR REPLACE FUNCTION clinic_test_fail_pharmacy_verification_outbox() RETURNS trigger AS $$
        BEGIN
            IF NEW.event_type = \'pharmacy.verification_decided\' THEN
                RAISE EXCEPTION \'clinic_test_fail_pharmacy_verification_outbox\';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
    ');
    DB::statement('
        CREATE TRIGGER clinic_test_fail_pharmacy_verification_outbox
        BEFORE INSERT ON outbox_events
        FOR EACH ROW EXECUTE FUNCTION clinic_test_fail_pharmacy_verification_outbox()
    ');

    try {
        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($claimed['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        ))->toThrow(QueryException::class);
        pharmacyVerificationAssertDecisionRolledBack($claimed['case_id'], $claimed['organization_id']);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS clinic_test_fail_pharmacy_verification_outbox ON outbox_events');
        DB::statement('DROP FUNCTION IF EXISTS clinic_test_fail_pharmacy_verification_outbox()');
    }
});
