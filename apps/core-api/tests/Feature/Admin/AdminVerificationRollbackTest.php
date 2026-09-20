<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationService;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{admin: array{user_id: string, actor: ActorContext}, version: int, case_id: string, doctor_id: string}
 */
function adminVerificationRollbackClaimed(string $key): array
{
    $draft = verificationPrepareDraftWithAvailableDocument($key);
    test()->postJson(
        '/api/v1/doctors/me/verification-submissions',
        verificationSubmitBody($draft['case_version'], $draft['profile_version']),
        doctorsAuth($draft['session']['token']) + doctorsIdem('admin-rb-sub-'.$key),
    )->assertOk();
    $claimed = verificationClaimPending($draft, $key);

    return [
        'admin' => $claimed['admin'],
        'version' => $claimed['version'],
        'case_id' => $draft['case_id'],
        'doctor_id' => $draft['doctor_id'],
    ];
}

function adminVerificationAssertDecisionRolledBack(string $caseId, string $doctorId): void
{
    expect(DB::table('verification_decisions')->where('case_id', $caseId)->count())->toBe(0)
        ->and((string) DB::table('verification_cases')->where('id', $caseId)->value('status'))->toBe('pending_review')
        ->and((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('verification_status'))->toBe(DoctorVerificationStatus::PendingReview->value)
        ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(0);
}

it('rolls back when PostgreSQL rejects the decision insert', function () {
    $claimed = adminVerificationRollbackClaimed('rb-insert');
    DB::statement('
        CREATE OR REPLACE FUNCTION clinic_test_fail_verification_decision() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION \'clinic_test_fail_verification_decision\';
        END;
        $$ LANGUAGE plpgsql
    ');
    DB::statement('
        CREATE TRIGGER clinic_test_fail_verification_decision
        BEFORE INSERT ON verification_decisions
        FOR EACH ROW EXECUTE FUNCTION clinic_test_fail_verification_decision()
    ');

    try {
        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($claimed['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        ))->toThrow(QueryException::class);
        adminVerificationAssertDecisionRolledBack($claimed['case_id'], $claimed['doctor_id']);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS clinic_test_fail_verification_decision ON verification_decisions');
        DB::statement('DROP FUNCTION IF EXISTS clinic_test_fail_verification_decision()');
    }
});

it('rolls back when the doctor transition is no longer legal', function () {
    $claimed = adminVerificationRollbackClaimed('rb-doctor');
    DB::table('doctor_profiles')->where('id', $claimed['doctor_id'])->update([
        'verification_status' => DoctorVerificationStatus::Draft->value,
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
        ->and(DB::table('outbox_events')->where('event_type', 'doctor.verification_decided')->count())->toBe(0);
});

it('rolls back when decision audit append fails', function () {
    $claimed = adminVerificationRollbackClaimed('rb-audit');
    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'verification.decision_recorded'));

    expect(fn () => app(VerificationService::class)->recordDecision(
        $claimed['admin']['actor'],
        Identifier::fromTrusted($claimed['case_id']),
        'approved',
        'approved',
        $claimed['version'],
    ))->toThrow(TransientProviderFailure::class);

    adminVerificationAssertDecisionRolledBack($claimed['case_id'], $claimed['doctor_id']);
});

it('rolls back when the outbox insert fails', function () {
    $claimed = adminVerificationRollbackClaimed('rb-outbox');
    DB::statement('
        CREATE OR REPLACE FUNCTION clinic_test_fail_verification_outbox() RETURNS trigger AS $$
        BEGIN
            IF NEW.event_type = \'doctor.verification_decided\' THEN
                RAISE EXCEPTION \'clinic_test_fail_verification_outbox\';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
    ');
    DB::statement('
        CREATE TRIGGER clinic_test_fail_verification_outbox
        BEFORE INSERT ON outbox_events
        FOR EACH ROW EXECUTE FUNCTION clinic_test_fail_verification_outbox()
    ');

    try {
        expect(fn () => app(VerificationService::class)->recordDecision(
            $claimed['admin']['actor'],
            Identifier::fromTrusted($claimed['case_id']),
            'approved',
            'approved',
            $claimed['version'],
        ))->toThrow(QueryException::class);
        adminVerificationAssertDecisionRolledBack($claimed['case_id'], $claimed['doctor_id']);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS clinic_test_fail_verification_outbox ON outbox_events');
        DB::statement('DROP FUNCTION IF EXISTS clinic_test_fail_verification_outbox()');
    }
});
