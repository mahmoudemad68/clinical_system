<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Pharmacies\Services\AcceptPharmacyStaffInvitation;
use Modules\Pharmacies\Services\CreatePharmacyBranch;
use Modules\Pharmacies\Services\InvitePharmacyStaff;
use Modules\Pharmacies\Services\ManagePharmacyMemberships;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\Identifier;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls back additional branch creation when audit append fails', function () {
    $owner = pharmaciesApprovedOrganization('rollback-br');
    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'pharmacy.branch_created'));

    expect(fn () => app(CreatePharmacyBranch::class)->handle(
        pharmaciesPharmacyActor($owner['user_id']),
        Identifier::fromTrusted($owner['organization_id']),
        pharmaciesBranchBody($owner['payload']['phone']),
    ))->toThrow(TransientProviderFailure::class);

    expect(DB::table('pharmacy_branches')->where('organization_id', $owner['organization_id'])->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.branch_changed')->count())->toBe(0);
});

it('rolls back invitation acceptance when audit append fails', function () {
    $owner = pharmaciesApprovedOrganization('rollback-acc');
    $branchId = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-rb-br'),
    )->json('data.branch_id');
    $operator = pharmaciesActiveSession('rollback-acc-op');
    $invitationId = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
        ['phone' => $operator['payload']['phone']],
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-rb-inv'),
    )->json('data.invitation_id');

    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'pharmacy.staff_invitation_accepted'));

    expect(fn () => app(AcceptPharmacyStaffInvitation::class)->handle(
        pharmaciesPharmacyActor($operator['user_id']),
        Identifier::fromTrusted($invitationId),
    ))->toThrow(TransientProviderFailure::class);

    expect(DB::table('pharmacy_memberships')->where('role', 'branch_operator')->count())->toBe(0)
        ->and((string) DB::table('pharmacy_staff_invitations')->value('status'))->toBe('pending')
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.membership_changed')->count())->toBe(0);
});

it('rolls back staff invitation issue when audit append fails', function () {
    $owner = pharmaciesApprovedOrganization('rollback-inv');
    $branchId = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-rb-inv-br'),
    )->json('data.branch_id');
    $operator = pharmaciesActiveSession('rollback-inv-op');

    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'pharmacy.staff_invitation_created'));

    expect(fn () => app(InvitePharmacyStaff::class)->handle(
        pharmaciesPharmacyActor($owner['user_id']),
        Identifier::fromTrusted($owner['organization_id']),
        Identifier::fromTrusted($branchId),
        ['phone' => $operator['payload']['phone']],
    ))->toThrow(TransientProviderFailure::class);

    expect(DB::table('pharmacy_staff_invitations')->count())->toBe(0);
});

it('rolls back membership revoke when audit append fails', function () {
    $owner = pharmaciesApprovedOrganization('rollback-rev');
    $branchId = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-rb-rev-br'),
    )->json('data.branch_id');
    $operator = pharmaciesActiveSession('rollback-rev-op');
    $invitationId = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
        ['phone' => $operator['payload']['phone']],
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-rb-rev-inv'),
    )->json('data.invitation_id');
    $membershipId = $this->postJson(
        '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
        [],
        pharmaciesAuth($operator['token']) + pharmaciesIdem('psi-rb-rev-acc'),
    )->json('data.membership_id');

    $beforeEvents = (int) DB::table('outbox_events')->where('event_type', 'pharmacy.membership_changed')->count();

    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'pharmacy.staff_membership_revoked'));

    expect(fn () => app(ManagePharmacyMemberships::class)->revoke(
        pharmaciesPharmacyActor($owner['user_id']),
        Identifier::fromTrusted($owner['organization_id']),
        Identifier::fromTrusted($branchId),
        Identifier::fromTrusted($membershipId),
    ))->toThrow(TransientProviderFailure::class);

    expect((string) DB::table('pharmacy_memberships')->where('id', $membershipId)->value('status'))->toBe('active')
        ->and((int) DB::table('pharmacy_memberships')->where('id', $membershipId)->value('version'))->toBe(1)
        ->and((int) DB::table('outbox_events')->where('event_type', 'pharmacy.membership_changed')->count())->toBe($beforeEvents);
});
