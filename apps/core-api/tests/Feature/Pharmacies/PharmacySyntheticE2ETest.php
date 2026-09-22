<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Services\ResolveActivePharmacyMembership;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs the synthetic pharmacy additional-branch and branch_operator membership flow', function () {
    $ownerA = pharmaciesApprovedOrganization('e2e-a');
    $address = '1 Tahrir Square, Cairo';
    $created = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
        pharmaciesBranchBody($ownerA['payload']['phone'], publicName: 'Branch B', address: $address),
        pharmaciesAuth($ownerA['token']) + pharmaciesIdem('e2e-create'),
    )->assertCreated()
        ->assertJsonPath('data.status', PharmacyBranchStatus::Active->value)
        ->assertJsonPath('data.version', 1);
    $branchB = $created->json('data.branch_id');

    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB,
        pharmaciesAuth($ownerA['token']),
    )->assertOk()
        ->assertJsonPath('data.address', $address)
        ->assertJsonPath('data.status', 'active');

    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
        pharmaciesAuth($ownerA['token']),
    )->assertOk();

    $this->patchJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB,
        ['expected_version' => 1, 'public_name' => 'Cairo Nile Pharmacy'],
        pharmaciesAuth($ownerA['token']),
    )->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.public_name', 'Cairo Nile Pharmacy');

    $operator = pharmaciesActiveSession('e2e-op');
    $invite = $this->postJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB.'/staff-invitations',
        ['phone' => $operator['payload']['phone']],
        pharmaciesAuth($ownerA['token']) + pharmaciesIdem('e2e-invite'),
    )->assertCreated();
    $invitationId = $invite->json('data.invitation_id');

    $wrong = pharmaciesActiveSession('e2e-wrong');
    $this->postJson(
        '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
        [],
        pharmaciesAuth($wrong['token']) + pharmaciesIdem('e2e-wrong'),
    )->assertNotFound();

    $accepted = $this->postJson(
        '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
        [],
        pharmaciesAuth($operator['token']) + pharmaciesIdem('e2e-accept'),
    )->assertOk()->assertJsonPath('data.status', 'active');
    $membershipId = $accepted->json('data.membership_id');

    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB,
        pharmaciesAuth($operator['token']),
    )->assertOk()
        ->assertJsonPath('data.branch_id', $branchB)
        ->assertJsonMissingPath('data.address')
        ->assertJsonMissingPath('data.phone')
        ->assertJsonMissingPath('data.latitude')
        ->assertJsonMissingPath('data.longitude');
    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB.'/memberships',
        pharmaciesAuth($operator['token']),
    )->assertNotFound();

    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB.'/memberships',
        pharmaciesAuth($ownerA['token']),
    )->assertOk()->assertJsonPath('data.0.membership_id', $membershipId);

    $ownerB = pharmaciesApprovedOrganization('e2e-b');
    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB,
        pharmaciesAuth($ownerB['token']),
    )->assertNotFound();
    $this->postJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
        pharmaciesBranchBody($operator['payload']['phone']),
        pharmaciesAuth($operator['token']) + pharmaciesIdem('e2e-op-create'),
    )->assertNotFound();

    $this->deleteJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB.'/memberships/'.$membershipId,
        [],
        pharmaciesAuth($ownerA['token']),
    )->assertOk()->assertJsonPath('data.status', 'revoked');

    expect(app(ResolveActivePharmacyMembership::class)->handle(
        Identifier::fromTrusted($operator['user_id']),
        Identifier::fromTrusted($ownerA['organization_id']),
        Identifier::fromTrusted($branchB),
    ))->toBeNull();

    $this->getJson(
        '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchB,
        pharmaciesAuth($operator['token']),
    )->assertNotFound();

    expect(DB::table('outbox_events')->where('event_type', 'pharmacy.branch_changed')->count())->toBeGreaterThan(0)
        ->and(DB::table('outbox_events')->where('event_type', 'pharmacy.membership_changed')->count())->toBeGreaterThan(0);
});
