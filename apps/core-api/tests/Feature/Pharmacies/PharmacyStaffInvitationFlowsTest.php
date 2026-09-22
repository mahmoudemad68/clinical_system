<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Services\ExportSubjectDataService;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Services\AcceptPharmacyStaffInvitation;
use Modules\Pharmacies\Services\InvitePharmacyStaff;
use Modules\Pharmacies\Services\ResolveActivePharmacyMembership;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    pharmaciesUseHmacCurrentVersion(1);
});

describe('pharmacy staff invitation foundation', function () {
    it('invites a branch_operator, accepts, lists, and revokes without echoing phone', function () {
        $owner = pharmaciesApprovedOrganization('invite-happy');
        $branch = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-happy-br'),
        )->assertCreated();
        $branchId = $branch->json('data.branch_id');
        $operator = pharmaciesActiveSession('invite-happy-op');
        $phoneCanary = $operator['payload']['phone'];

        $invite = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $phoneCanary],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-happy-inv'),
        );
        $invite->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.target_phone_lookup_hmac')
            ->assertJsonMissingPath('data.role');
        expect($invite->getContent())->not->toContain($phoneCanary)
            ->and($invite->getContent())->not->toContain('hmac')
            ->and(DB::table('pharmacy_staff_invitations')->count())->toBe(1);

        $invitationId = $invite->json('data.invitation_id');
        $row = DB::table('pharmacy_staff_invitations')->first();
        expect((string) $row->role)->toBe('branch_operator')
            ->and((string) $row->organization_id)->toBe($owner['organization_id'])
            ->and((string) $row->branch_id)->toBe($branchId);

        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            ['branch_id' => $owner['branch_id'], 'role' => 'owner'],
            pharmaciesAuth($operator['token']) + pharmaciesIdem('psi-happy-mass'),
        )->assertStatus(422);

        $accepted = $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            [],
            pharmaciesAuth($operator['token']) + pharmaciesIdem('psi-happy-acc'),
        );
        $accepted->assertOk()
            ->assertJsonPath('data.role', 'branch_operator')
            ->assertJsonPath('data.status', PharmacyMembershipStatus::Active->value)
            ->assertJsonPath('data.branch_id', $branchId)
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.phone');
        $membershipId = $accepted->json('data.membership_id');
        expect($accepted->getContent())->not->toContain($phoneCanary)
            ->and(DB::table('pharmacy_memberships')->where('role', 'branch_operator')->count())->toBe(1)
            ->and(app(ResolveActivePharmacyMembership::class)->handle(
                Identifier::fromTrusted($operator['user_id']),
                Identifier::fromTrusted($owner['organization_id']),
                Identifier::fromTrusted($branchId),
            )?->membershipId->value)->toBe($membershipId);

        $listed = $this->getJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/memberships',
            pharmaciesAuth($owner['token']),
        );
        $listed->assertOk()
            ->assertJsonPath('data.0.membership_id', $membershipId)
            ->assertJsonPath('data.0.role', 'branch_operator')
            ->assertJsonPath('data.0.status', 'active');
        expect(collect($listed->json('data'))->pluck('role')->all())->not->toContain('owner')
            ->and($listed->getContent())->not->toContain($phoneCanary)
            ->and($listed->getContent())->not->toContain($operator['user_id']);

        $this->deleteJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/memberships/'.$owner['membership_id'],
            [],
            pharmaciesAuth($owner['token']),
        )->assertNotFound();

        $revoked = $this->deleteJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/memberships/'.$membershipId,
            [],
            pharmaciesAuth($owner['token']),
        );
        $revoked->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.version', 2);
        $again = $this->deleteJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/memberships/'.$membershipId,
            [],
            pharmaciesAuth($owner['token']),
        );
        $again->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.version', 2);
        expect(app(ResolveActivePharmacyMembership::class)->handle(
            Identifier::fromTrusted($operator['user_id']),
            Identifier::fromTrusted($owner['organization_id']),
            Identifier::fromTrusted($branchId),
        ))->toBeNull();

        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($operator['payload']['phone']),
            pharmaciesAuth($operator['token']) + pharmaciesIdem('psi-op-create'),
        )->assertNotFound();

        $audit = implode("\n", DB::table('audit_events')->pluck('metadata')->all());
        $outbox = implode("\n", DB::table('outbox_events')->pluck('payload')->all());
        expect($audit)->not->toContain($phoneCanary)
            ->and($outbox)->not->toContain($phoneCanary)
            ->and($outbox)->not->toContain('target_phone_lookup_hmac');
    });

    it('dedups a live pending invitation and replaces an expired one', function () {
        $owner = pharmaciesApprovedOrganization('invite-dedup');
        $branchId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-dedup-br'),
        )->json('data.branch_id');
        $operator = pharmaciesActiveSession('invite-dedup-op');
        $phoneCanary = $operator['payload']['phone'];
        $uri = '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations';

        $first = $this->postJson($uri, ['phone' => $phoneCanary], pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-dedup-1'));
        $first->assertCreated();
        $firstId = $first->json('data.invitation_id');
        $replay = $this->postJson($uri, ['phone' => $phoneCanary], pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-dedup-2'));
        $replay->assertOk()->assertJsonPath('data.invitation_id', $firstId);
        expect(DB::table('pharmacy_staff_invitations')->count())->toBe(1);

        DB::table('pharmacy_staff_invitations')->where('id', $firstId)->update([
            'expires_at' => now('UTC')->subHour(),
        ]);
        $replacement = $this->postJson($uri, ['phone' => $phoneCanary], pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-dedup-3'));
        $replacement->assertCreated();
        expect($replacement->json('data.invitation_id'))->not->toBe($firstId)
            ->and((string) DB::table('pharmacy_staff_invitations')->where('id', $firstId)->value('status'))->toBe('expired')
            ->and(DB::table('pharmacy_staff_invitations')->where('status', 'pending')->count())->toBe(1);
    });

    it('dedups a pending invitation stored under a previous HMAC after key rotation', function () {
        $owner = pharmaciesApprovedOrganization('invite-hmac');
        $branchId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-hmac-br'),
        )->json('data.branch_id');
        $operator = pharmaciesActiveSession('invite-hmac-op');
        $phoneCanary = $operator['payload']['phone'];
        $first = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $phoneCanary],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-hmac-1'),
        )->assertCreated();
        $firstId = $first->json('data.invitation_id');
        $storedV1 = BinaryColumn::asString(
            DB::table('pharmacy_staff_invitations')->where('id', $firstId)->value('target_phone_lookup_hmac'),
        );

        pharmaciesUseHmacCurrentVersion(2);
        $replay = app(InvitePharmacyStaff::class)->handle(
            pharmaciesPharmacyActor($owner['user_id']),
            Identifier::fromTrusted($owner['organization_id']),
            Identifier::fromTrusted($branchId),
            ['phone' => $phoneCanary],
        );
        expect($replay->created)->toBeFalse()
            ->and($replay->invitationId)->toBe($firstId)
            ->and(DB::table('pharmacy_staff_invitations')->where('status', 'pending')->count())->toBe(1)
            ->and(hash_equals($storedV1, BinaryColumn::asString(
                DB::table('pharmacy_staff_invitations')->where('id', $firstId)->value('target_phone_lookup_hmac'),
            )))->toBeTrue();
    });

    it('replaces an expired previous-HMAC invitation with a current-HMAC row', function () {
        $owner = pharmaciesApprovedOrganization('invite-hmac-exp');
        $branchId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-hmac-exp-br'),
        )->json('data.branch_id');
        $operator = pharmaciesActiveSession('invite-hmac-exp-op');
        $phoneCanary = $operator['payload']['phone'];
        $firstId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $phoneCanary],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-hmac-exp-1'),
        )->json('data.invitation_id');
        $storedV1 = BinaryColumn::asString(
            DB::table('pharmacy_staff_invitations')->where('id', $firstId)->value('target_phone_lookup_hmac'),
        );
        pharmaciesUseHmacCurrentVersion(2);
        DB::table('pharmacy_staff_invitations')->where('id', $firstId)->update([
            'expires_at' => now('UTC')->subHour(),
        ]);
        $replacement = app(InvitePharmacyStaff::class)->handle(
            pharmaciesPharmacyActor($owner['user_id']),
            Identifier::fromTrusted($owner['organization_id']),
            Identifier::fromTrusted($branchId),
            ['phone' => $phoneCanary],
        );
        $newId = $replacement->invitationId;
        $storedV2 = BinaryColumn::asString(
            DB::table('pharmacy_staff_invitations')->where('id', $newId)->value('target_phone_lookup_hmac'),
        );
        expect($replacement->created)->toBeTrue()
            ->and($newId)->not->toBe($firstId)
            ->and((string) DB::table('pharmacy_staff_invitations')->where('id', $firstId)->value('status'))->toBe('expired')
            ->and((int) DB::table('pharmacy_staff_invitations')->where('id', $newId)->value('target_phone_key_version'))->toBe(2)
            ->and(hash_equals($storedV1, $storedV2))->toBeFalse()
            ->and(DB::table('pharmacy_staff_invitations')->where('status', 'pending')->count())->toBe(1);
    });

    it('denies wrong recipient, expired, cancelled, and already-member unique org collisions', function () {
        $owner = pharmaciesApprovedOrganization('invite-sec');
        $branchId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-sec-br'),
        )->json('data.branch_id');
        $intended = pharmaciesActiveSession('invite-sec-int');
        $wrong = pharmaciesActiveSession('invite-sec-wrong');
        $invitationId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $intended['payload']['phone']],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-sec-inv'),
        )->json('data.invitation_id');

        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            [],
            pharmaciesAuth($wrong['token']) + pharmaciesIdem('psi-sec-wrong'),
        )->assertNotFound();

        DB::table('pharmacy_staff_invitations')->where('id', $invitationId)->update([
            'status' => 'cancelled',
        ]);
        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            [],
            pharmaciesAuth($intended['token']) + pharmaciesIdem('psi-sec-cancel'),
        )->assertNotFound();

        $invitationId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $intended['payload']['phone']],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-sec-reopen'),
        )->json('data.invitation_id');
        DB::table('pharmacy_staff_invitations')->where('id', $invitationId)->update([
            'expires_at' => now('UTC')->subHour(),
        ]);
        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            [],
            pharmaciesAuth($intended['token']) + pharmaciesIdem('psi-sec-exp'),
        )->assertNotFound();

        $freshId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $intended['payload']['phone']],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-sec-fresh'),
        )->json('data.invitation_id');
        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$freshId.'/accept',
            [],
            pharmaciesAuth($intended['token']) + pharmaciesIdem('psi-sec-ok'),
        )->assertOk();
        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$freshId.'/accept',
            [],
            pharmaciesAuth($intended['token']) + pharmaciesIdem('psi-sec-replay'),
        )->assertNotFound();
        expect(DB::table('pharmacy_memberships')->where('user_id', $intended['user_id'])->count())->toBe(1);

        $secondBranch = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone'], publicName: 'Second Branch'),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-sec-br2'),
        )->json('data.branch_id');
        $otherInvite = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$secondBranch.'/staff-invitations',
            ['phone' => $intended['payload']['phone']],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-sec-other'),
        )->json('data.invitation_id');
        $this->postJson(
            '/api/v1/pharmacy-staff-invitations/'.$otherInvite.'/accept',
            [],
            pharmaciesAuth($intended['token']) + pharmaciesIdem('psi-sec-move'),
        )->assertStatus(409);
        expect(DB::table('pharmacy_memberships')->where('user_id', $intended['user_id'])->count())->toBe(1);
    });

    it('exports a targeted pending invitation and erasure blocks acceptance', function () {
        $owner = pharmaciesApprovedOrganization('invite-erase');
        $branchId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-er-br'),
        )->json('data.branch_id');
        $operator = pharmaciesActiveSession('invite-erase-op');
        $phoneCanary = $operator['payload']['phone'];
        $invitationId = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
            ['phone' => $phoneCanary],
            pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-er-inv'),
        )->json('data.invitation_id');

        $export = app(ExportSubjectDataService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($operator['user_id']),
        );
        $json = json_encode($export->toArray(), JSON_THROW_ON_ERROR);
        expect(clinicHoldingCount($export->holdings, 'pharmacy_staff_invitations'))->toBe(1)
            ->and($json)->not->toContain($phoneCanary)
            ->and($json)->not->toContain('target_phone_lookup_hmac');

        app(EraseSubjectService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($operator['user_id']),
            'subject_erasure',
        );
        expect((string) DB::table('pharmacy_staff_invitations')->where('id', $invitationId)->value('status'))->toBe('cancelled');
        expect(fn () => app(AcceptPharmacyStaffInvitation::class)->handle(
            pharmaciesPharmacyActor($operator['user_id']),
            Identifier::fromTrusted($invitationId),
        ))->toThrow(AuthorizationDenied::class);
    });

    it('rejects BOLA invite/list/revoke across organizations', function () {
        $ownerA = pharmaciesApprovedOrganization('invite-bola-a');
        $ownerB = pharmaciesApprovedOrganization('invite-bola-b');
        $branchA = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesBranchBody($ownerA['payload']['phone']),
            pharmaciesAuth($ownerA['token']) + pharmaciesIdem('psi-bola-br'),
        )->json('data.branch_id');
        $operator = pharmaciesActiveSession('invite-bola-op');
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchA.'/staff-invitations',
            ['phone' => $operator['payload']['phone']],
            pharmaciesAuth($ownerB['token']) + pharmaciesIdem('psi-bola-inv'),
        )->assertNotFound();
        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchA.'/memberships',
            pharmaciesAuth($ownerB['token']),
        )->assertNotFound();
        $this->deleteJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchA.'/memberships/'.$ownerA['membership_id'],
            [],
            pharmaciesAuth($ownerB['token']),
        )->assertNotFound();
    });

    it('keeps the invitation composite FK from targeting a branch of another organization', function () {
        $ownerA = pharmaciesApprovedOrganization('invite-fk-a');
        $ownerB = pharmaciesApprovedOrganization('invite-fk-b');
        $composite = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = 'pharmacy_staff_invitations_organization_branch_fk'",
        );
        expect($composite)->not->toBeNull()
            ->and((string) $composite->definition)->toContain('FOREIGN KEY (organization_id, branch_id)')
            ->and((string) $composite->definition)->toContain('REFERENCES pharmacy_branches(organization_id, id)');

        $now = now('UTC');
        $row = [
            'id' => app(IdentityGenerator::class)->next()->value,
            'organization_id' => $ownerA['organization_id'],
            'branch_id' => $ownerB['branch_id'],
            'role' => 'branch_operator',
            'status' => 'pending',
            'target_phone_lookup_hmac' => BinaryColumn::bind(random_bytes(32)),
            'target_phone_key_version' => 1,
            'expires_at' => $now->copy()->addHours(72),
            'invited_at' => $now,
            'accepted_at' => null,
            'consumed_at' => null,
            'inviter_user_id' => $ownerA['user_id'],
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $sqlState = null;
        try {
            DB::transaction(function () use ($row): void {
                DB::table('pharmacy_staff_invitations')->insert($row);
            });
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
        }
        expect($sqlState)->toBe('23503')
            ->and(DB::table('pharmacy_staff_invitations')->count())->toBe(0);
    });
});
