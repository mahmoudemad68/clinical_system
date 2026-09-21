<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Clinics\Services\AcceptClinicStaffInvitation;
use Modules\Clinics\Services\ResolveActiveClinicMembership;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Services\ExportSubjectDataService;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    if ((int) config('identity.hmac.current_version') !== 1) {
        clinicUseHmacCurrentVersion(1);
    }
});

describe('clinic staff invitation and membership', function () {
    it('invites a secretary, accepts once, lists, and revokes without leaking phone', function () {
        $doctor = clinicApprovedDoctor('invite-happy');
        $created = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-loc'),
        )->assertCreated();
        $locationId = $created->json('data.location_id');
        $secretary = clinicInsertSecretary('invite-happy');
        $phoneCanary = $secretary['phone'];

        $invite = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-inv'),
        );
        $invite->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.target_phone_lookup_hmac');
        expect($invite->getContent())->not->toContain($phoneCanary)
            ->and($invite->getContent())->not->toContain('hmac');

        $replayInvite = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-inv-dup'),
        );
        $replayInvite->assertOk()->assertJsonPath('data.invitation_id', $invite->json('data.invitation_id'));
        expect(DB::table('clinic_staff_invitations')->count())->toBe(1);

        $invitationId = $invite->json('data.invitation_id');
        $row = DB::table('clinic_staff_invitations')->first();
        expect(str_contains(BinaryColumn::asString($row->target_phone_lookup_hmac), $phoneCanary))->toBeFalse()
            ->and((string) $row->role)->toBe('secretary');

        clinicSecretaryLogin($secretary);
        $accepted = clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-accept'),
        );
        $accepted->assertOk()
            ->assertJsonPath('data.role', ClinicStaffRole::Secretary->value)
            ->assertJsonPath('data.status', ClinicMembershipStatus::Active->value)
            ->assertJsonMissingPath('data.phone');
        expect($accepted->getContent())->not->toContain($phoneCanary)
            ->and(DB::table('clinic_staff_memberships')->where('status', 'active')->count())->toBe(1);

        $membershipId = $accepted->json('data.membership_id');
        $resolved = app(ResolveActiveClinicMembership::class)->handle(
            Identifier::fromTrusted($secretary['user_id']),
            Identifier::fromTrusted($locationId),
        );
        expect($resolved)->not->toBeNull()
            ->and($resolved->membershipId->value)->toBe($membershipId)
            ->and($resolved->role)->toBe(ClinicStaffRole::Secretary);

        $listed = $this->getJson(
            '/api/v1/clinic-locations/'.$locationId.'/memberships',
            doctorsAuth($doctor['token']),
        );
        $listed->assertOk()
            ->assertJsonPath('data.0.membership_id', $membershipId)
            ->assertJsonPath('data.0.role', 'secretary')
            ->assertJsonPath('data.0.status', 'active');
        expect($listed->getContent())->not->toContain($phoneCanary);

        $staffLocation = clinicSecretaryGetJson('/api/v1/clinic-locations/'.$locationId);
        $staffLocation->assertOk()
            ->assertJsonPath('data.location_id', $locationId)
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.latitude');
        clinicClearBrowserSession();

        $event = DB::table('outbox_events')->where('event_type', 'clinic.membership_changed')->first();
        expect($event)->not->toBeNull();
        $payload = is_string($event->payload) ? $event->payload : json_encode($event->payload);
        expect($payload)->not->toContain($phoneCanary)
            ->and($payload)->toContain('clinic_location')
            ->and($payload)->toContain($membershipId);

        $auditBlob = json_encode(
            DB::table('audit_events')->whereIn('event_name', [
                'clinic.staff_invitation_created',
                'clinic.staff_invitation_accepted',
            ])->get(['event_name', 'metadata'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect($auditBlob)->not->toContain($phoneCanary)->and($auditBlob)->not->toContain('hmac');

        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-accept-replay'),
        )->assertNotFound();

        $revoked = $this->deleteJson(
            '/api/v1/clinic-locations/'.$locationId.'/memberships/'.$membershipId,
            [],
            doctorsAuth($doctor['token']),
        );
        $revoked->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.version', 2);

        $this->deleteJson(
            '/api/v1/clinic-locations/'.$locationId.'/memberships/'.$membershipId,
            [],
            doctorsAuth($doctor['token']),
        )->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.version', 2);

        expect(app(ResolveActiveClinicMembership::class)->handle(
            Identifier::fromTrusted($secretary['user_id']),
            Identifier::fromTrusted($locationId),
        ))->toBeNull()
            ->and(DB::table('clinic_staff_memberships')->count())->toBe(1)
            ->and((string) DB::table('clinic_staff_memberships')->value('status'))->toBe('revoked');
    });

    it('rejects client-supplied invitation role, user, and membership fields', function () {
        $doctor = clinicApprovedDoctor('invite-mass');
        $created = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-mass-loc'),
        )->assertCreated();
        $locationId = $created->json('data.location_id');
        $secretary = clinicInsertSecretary('invite-mass');

        foreach ([
            ['phone' => $secretary['phone'], 'role' => 'doctor'],
            ['phone' => $secretary['phone'], 'user_id' => $secretary['user_id']],
            ['phone' => $secretary['phone'], 'status' => 'active'],
            ['phone' => $secretary['phone'], 'inviter_id' => $doctor['user_id']],
        ] as $i => $body) {
            $this->postJson(
                '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
                $body,
                doctorsAuth($doctor['token']) + clinicIdem('csi-mass-'.$i),
            )->assertUnprocessable();
        }

        expect(DB::table('clinic_staff_invitations')->count())->toBe(0);
    });

    it('does not distinguish unknown phones from existing accounts', function () {
        $doctor = clinicApprovedDoctor('invite-enum');
        $created = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-enum-loc'),
        )->assertCreated();
        $locationId = $created->json('data.location_id');
        $known = clinicInsertSecretary('invite-enum');
        $missingPhone = (new SyntheticEgyptianData)->mobileNumber();

        $existing = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $known['phone']],
            doctorsAuth($doctor['token']) + clinicIdem('csi-enum-known'),
        );
        $missing = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $missingPhone],
            doctorsAuth($doctor['token']) + clinicIdem('csi-enum-missing'),
        );

        $existing->assertCreated();
        $missing->assertCreated();
        expect($existing->json('data.status'))->toBe($missing->json('data.status'))
            ->and(array_keys($existing->json('data')))->toBe(array_keys($missing->json('data')));
    });

    it('denies expired, stolen, wrong-secretary, and non-secretary acceptance', function () {
        $doctor = clinicApprovedDoctor('invite-deny');
        $created = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-deny-loc'),
        )->assertCreated();
        $locationId = $created->json('data.location_id');
        $intended = clinicInsertSecretary('invite-deny');
        $wrong = clinicInsertSecretary('invite-wrong');
        $invite = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $intended['phone']],
            doctorsAuth($doctor['token']) + clinicIdem('csi-deny-inv'),
        )->assertCreated();
        $invitationId = $invite->json('data.invitation_id');

        $this->postJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            doctorsAuth($doctor['token']) + clinicIdem('csi-deny-doctor'),
        )->assertNotFound();

        $patient = patientsActiveSession('csi-patient');
        $this->postJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            patientsAuth($patient['token']) + clinicIdem('csi-deny-patient'),
        )->assertNotFound();

        clinicSecretaryLogin($wrong);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-deny-wrong'),
        )->assertNotFound();

        DB::table('clinic_staff_invitations')->where('id', $invitationId)->update([
            'expires_at' => now('UTC')->subHour(),
        ]);
        clinicSecretaryLogin($intended);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-deny-expired'),
        )->assertNotFound();

        expect(DB::table('clinic_staff_memberships')->count())->toBe(0);
    });

    it('denies cross-location revoke', function () {
        $ownerA = clinicApprovedDoctor('revoke-a');
        $ownerB = clinicApprovedDoctor('revoke-b');
        $locationA = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody('Clinic A'),
            doctorsAuth($ownerA['token']) + clinicIdem('csi-rev-a'),
        )->json('data.location_id');
        $locationB = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody('Clinic B'),
            doctorsAuth($ownerB['token']) + clinicIdem('csi-rev-b'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('revoke-cross');
        $invitationId = $this->postJson(
            '/api/v1/clinic-locations/'.$locationA.'/staff-invitations',
            ['phone' => $secretary['phone']],
            doctorsAuth($ownerA['token']) + clinicIdem('csi-rev-inv'),
        )->json('data.invitation_id');
        clinicSecretaryLogin($secretary);
        $membershipId = clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-rev-accept'),
        )->json('data.membership_id');
        clinicClearBrowserSession();

        $this->deleteJson(
            '/api/v1/clinic-locations/'.$locationB.'/memberships/'.$membershipId,
            [],
            doctorsAuth($ownerB['token']),
        )->assertNotFound();
        $this->deleteJson(
            '/api/v1/clinic-locations/'.$locationA.'/memberships/'.$membershipId,
            [],
            doctorsAuth($ownerB['token']),
        )->assertNotFound();

        expect((string) DB::table('clinic_staff_memberships')->value('status'))->toBe('active');
    });

    it('rejects a second active grant for the same staff profile and location', function () {
        $doctor = clinicApprovedDoctor('uniq-grant');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-uniq-loc'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('uniq-grant');
        $invitationId = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $secretary['phone']],
            doctorsAuth($doctor['token']) + clinicIdem('csi-uniq-inv'),
        )->json('data.invitation_id');
        clinicSecretaryLogin($secretary);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-uniq-accept'),
        )->assertOk();

        $membership = DB::table('clinic_staff_memberships')->first();
        $ids = app(IdentityGenerator::class);
        $now = now('UTC');

        expect(fn () => DB::table('clinic_staff_memberships')->insert([
            'id' => $ids->next()->value,
            'staff_profile_id' => $membership->staff_profile_id,
            'location_id' => $membership->location_id,
            'role' => 'secretary',
            'status' => 'active',
            'invited_at' => $now,
            'accepted_at' => $now,
            'revoked_at' => null,
            'inviter_user_id' => $doctor['user_id'],
            'revoker_user_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('revokes secretary grants and cancels pending invitations on subject erasure', function () {
        $doctor = clinicApprovedDoctor('erase-sec');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-erase-loc'),
        )->json('data.location_id');

        $activeSecretary = clinicInsertSecretary('erase-sec-active');
        $activeInvite = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $activeSecretary['phone']],
            doctorsAuth($doctor['token']) + clinicIdem('csi-erase-active-inv'),
        )->json('data.invitation_id');
        clinicSecretaryLogin($activeSecretary);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$activeInvite.'/accept',
            [],
            clinicIdem('csi-erase-active-accept'),
        )->assertOk();

        $pendingSecretary = clinicInsertSecretary('erase-sec-pending');
        $pendingInvite = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $pendingSecretary['phone']],
            doctorsAuth($doctor['token']) + clinicIdem('csi-erase-pending'),
        )->json('data.invitation_id');

        app(EraseSubjectService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($activeSecretary['user_id']),
            'subject_erasure',
        );
        app(EraseSubjectService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($pendingSecretary['user_id']),
            'subject_erasure',
        );

        expect((string) DB::table('clinic_staff_memberships')->value('status'))->toBe('revoked')
            ->and(app(ResolveActiveClinicMembership::class)->handle(
                Identifier::fromTrusted($activeSecretary['user_id']),
                Identifier::fromTrusted($locationId),
            ))->toBeNull()
            ->and((string) DB::table('clinic_staff_invitations')->where('id', $pendingInvite)->value('status'))->toBe('cancelled')
            ->and((string) DB::table('users')->where('id', $pendingSecretary['user_id'])->value('status'))->toBe('closed');
    });

    it('closes owned locations and attached memberships when the doctor identity is erased', function () {
        $doctor = clinicApprovedDoctor('erase-doc');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(address: '99 Hidden Clinic Road'),
            doctorsAuth($doctor['token']) + clinicIdem('csi-edoc-loc'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('erase-doc');
        $invitationId = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $secretary['phone']],
            doctorsAuth($doctor['token']) + clinicIdem('csi-edoc-inv'),
        )->json('data.invitation_id');
        clinicSecretaryLogin($secretary);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$invitationId.'/accept',
            [],
            clinicIdem('csi-edoc-accept'),
        )->assertOk();

        app(EraseSubjectService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($doctor['user_id']),
            'subject_erasure',
        );

        $location = DB::table('clinic_locations')->first();
        expect((string) $location->status)->toBe('closed')
            ->and((string) $location->public_name)->toBe('erased')
            ->and(str_contains(BinaryColumn::asString($location->address_ciphertext), '99 Hidden Clinic Road'))->toBeFalse()
            ->and((string) DB::table('clinic_staff_memberships')->value('status'))->toBe('revoked')
            ->and(app(ResolveActiveClinicMembership::class)->handle(
                Identifier::fromTrusted($secretary['user_id']),
                Identifier::fromTrusted($locationId),
            ))->toBeNull()
            ->and((string) DB::table('clinic_staff_invitations')->value('status'))->not->toBe('pending');
    });

    it('expires a stale pending invitation and lets the same doctor invite again', function () {
        $doctor = clinicApprovedDoctor('invite-replace');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-rep-loc'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('invite-replace');
        $phoneCanary = $secretary['phone'];

        $first = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-rep-first'),
        )->assertCreated();
        $firstId = $first->json('data.invitation_id');

        $replay = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-rep-replay'),
        );
        $replay->assertOk()->assertJsonPath('data.invitation_id', $firstId);
        expect(DB::table('clinic_staff_invitations')->count())->toBe(1)
            ->and((string) DB::table('clinic_staff_invitations')->value('status'))->toBe('pending');

        DB::table('clinic_staff_invitations')->where('id', $firstId)->update([
            'expires_at' => now('UTC')->subHour(),
        ]);

        $replacement = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-rep-new'),
        );
        $replacement->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.target_phone_lookup_hmac');
        expect($replacement->getContent())->not->toContain($phoneCanary)
            ->and($replacement->getContent())->not->toContain('hmac')
            ->and($replacement->json('data.invitation_id'))->not->toBe($firstId)
            ->and((string) DB::table('clinic_staff_invitations')->where('id', $firstId)->value('status'))->toBe('expired')
            ->and(DB::table('clinic_staff_invitations')->count())->toBe(2)
            ->and(DB::table('clinic_staff_invitations')->where('status', 'pending')->count())->toBe(1)
            ->and(DB::table('clinic_staff_invitations')->where('status', 'expired')->count())->toBe(1);

        $newId = $replacement->json('data.invitation_id');
        clinicSecretaryLogin($secretary);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$firstId.'/accept',
            [],
            clinicIdem('csi-rep-old-accept'),
        )->assertNotFound();
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$newId.'/accept',
            [],
            clinicIdem('csi-rep-new-accept'),
        )->assertOk()->assertJsonPath('data.status', ClinicMembershipStatus::Active->value);
        expect(DB::table('clinic_staff_memberships')->where('status', 'active')->count())->toBe(1);
    });

    it('dedups a pending invitation stored under a previous HMAC after key rotation', function () {
        $doctor = clinicApprovedDoctor('invite-hmac');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-hmac-loc'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('invite-hmac');
        $phoneCanary = $secretary['phone'];

        $first = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-hmac-first'),
        )->assertCreated();
        $firstId = $first->json('data.invitation_id');
        $storedV1 = BinaryColumn::asString(
            DB::table('clinic_staff_invitations')->where('id', $firstId)->value('target_phone_lookup_hmac'),
        );
        expect((int) DB::table('clinic_staff_invitations')->where('id', $firstId)->value('target_phone_key_version'))->toBe(1);

        clinicUseHmacCurrentVersion(2);

        $replay = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-hmac-replay'),
        );
        $replay->assertOk()
            ->assertJsonPath('data.invitation_id', $firstId)
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.target_phone_lookup_hmac');
        expect($replay->getContent())->not->toContain($phoneCanary)
            ->and($replay->getContent())->not->toContain('hmac')
            ->and(DB::table('clinic_staff_invitations')->count())->toBe(1)
            ->and(DB::table('clinic_staff_invitations')->where('status', 'pending')->count())->toBe(1)
            ->and(hash_equals($storedV1, BinaryColumn::asString(
                DB::table('clinic_staff_invitations')->where('id', $firstId)->value('target_phone_lookup_hmac'),
            )))->toBeTrue();

        clinicSecretaryLogin($secretary);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$firstId.'/accept',
            [],
            clinicIdem('csi-hmac-accept'),
        )->assertOk()->assertJsonPath('data.status', ClinicMembershipStatus::Active->value);
        clinicClearBrowserSession();
    });

    it('replaces an expired previous-HMAC invitation with a current-HMAC row', function () {
        $doctor = clinicApprovedDoctor('invite-hmac-exp');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-hmac-exp-loc'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('invite-hmac-exp');
        $phoneCanary = $secretary['phone'];

        $first = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-hmac-exp-first'),
        )->assertCreated();
        $firstId = $first->json('data.invitation_id');
        $storedV1 = BinaryColumn::asString(
            DB::table('clinic_staff_invitations')->where('id', $firstId)->value('target_phone_lookup_hmac'),
        );

        clinicUseHmacCurrentVersion(2);
        DB::table('clinic_staff_invitations')->where('id', $firstId)->update([
            'expires_at' => now('UTC')->subHour(),
        ]);

        $replacement = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-hmac-exp-new'),
        );
        $newId = $replacement->json('data.invitation_id');
        $storedV2 = BinaryColumn::asString(
            DB::table('clinic_staff_invitations')->where('id', $newId)->value('target_phone_lookup_hmac'),
        );
        $replacement->assertCreated();
        expect($newId)->not->toBe($firstId)
            ->and((string) DB::table('clinic_staff_invitations')->where('id', $firstId)->value('status'))->toBe('expired')
            ->and((int) DB::table('clinic_staff_invitations')->where('id', $newId)->value('target_phone_key_version'))->toBe(2)
            ->and(hash_equals($storedV1, $storedV2))->toBeFalse()
            ->and(DB::table('clinic_staff_invitations')->where('status', 'pending')->count())->toBe(1)
            ->and($replacement->getContent())->not->toContain($phoneCanary)
            ->and($replacement->getContent())->not->toContain('hmac');

        clinicSecretaryLogin($secretary);
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$firstId.'/accept',
            [],
            clinicIdem('csi-hmac-exp-old'),
        )->assertNotFound();
        clinicSecretaryPostJson(
            '/api/v1/clinic-staff-invitations/'.$newId.'/accept',
            [],
            clinicIdem('csi-hmac-exp-accept'),
        )->assertOk();
        clinicClearBrowserSession();
    });

    it('exports a secretary-targeted pending invitation and erasure blocks acceptance', function () {
        $doctor = clinicApprovedDoctor('export-target');
        $locationId = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($doctor['token']) + clinicIdem('csi-exp-loc'),
        )->json('data.location_id');
        $secretary = clinicInsertSecretary('export-target');
        $phoneCanary = $secretary['phone'];
        $invitationId = $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $phoneCanary],
            doctorsAuth($doctor['token']) + clinicIdem('csi-exp-inv'),
        )->json('data.invitation_id');
        $hmacBefore = BinaryColumn::asString(
            DB::table('clinic_staff_invitations')->where('id', $invitationId)->value('target_phone_lookup_hmac'),
        );

        $secretaryExport = app(ExportSubjectDataService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($secretary['user_id']),
        );
        $doctorExport = app(ExportSubjectDataService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($doctor['user_id']),
        );
        $secretaryJson = json_encode($secretaryExport->toArray(), JSON_THROW_ON_ERROR);
        $doctorJson = json_encode($doctorExport->toArray(), JSON_THROW_ON_ERROR);

        expect(clinicHoldingCount($secretaryExport->holdings, 'clinic_staff_invitations'))->toBe(1)
            ->and(clinicHoldingCount($secretaryExport->holdings, 'clinic_staff_memberships'))->toBe(0)
            ->and(clinicHoldingCount($secretaryExport->holdings, 'clinic_staff_profiles'))->toBe(0)
            ->and(clinicHoldingCount($doctorExport->holdings, 'clinic_staff_invitations'))->toBe(1)
            ->and(clinicHoldingCount($doctorExport->holdings, 'clinic_locations'))->toBe(1)
            ->and($secretaryJson)->not->toContain($phoneCanary)
            ->and($secretaryJson)->not->toContain($doctor['user_id'])
            ->and($secretaryJson)->not->toContain('hmac')
            ->and($secretaryJson)->not->toContain(bin2hex($hmacBefore))
            ->and($doctorJson)->not->toContain($phoneCanary)
            ->and($doctorJson)->not->toContain($secretary['user_id'])
            ->and($doctorJson)->not->toContain('hmac');

        app(EraseSubjectService::class)->handle(
            clinicEraseOperator(),
            Identifier::fromTrusted($secretary['user_id']),
            'subject_erasure',
        );

        $hmacAfter = BinaryColumn::asString(
            DB::table('clinic_staff_invitations')->where('id', $invitationId)->value('target_phone_lookup_hmac'),
        );
        expect((string) DB::table('clinic_staff_invitations')->where('id', $invitationId)->value('status'))->toBe('cancelled')
            ->and(hash_equals($hmacBefore, $hmacAfter))->toBeFalse();
        expect(fn () => app(AcceptClinicStaffInvitation::class)->handle(
            clinicSecretaryActor($secretary['user_id']),
            Identifier::fromTrusted($invitationId),
        ))->toThrow(AuthorizationDenied::class);
    });
});
