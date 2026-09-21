<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Services\AcceptClinicStaffInvitation;
use Modules\Clinics\Services\GetClinicLocation;
use Modules\Clinics\Services\ResolveActiveClinicMembership;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs the synthetic clinic location and secretary membership flow', function () {
    $doctor = clinicApprovedDoctor('e2e');
    $address = '1 Tahrir Square, Cairo';
    $created = $this->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(address: $address),
        doctorsAuth($doctor['token']) + clinicIdem('e2e-create'),
    )->assertCreated()
        ->assertJsonPath('data.status', ClinicLocationStatus::Active->value)
        ->assertJsonPath('data.version', 1);
    $locationId = $created->json('data.location_id');

    $own = $this->getJson('/api/v1/clinic-locations/'.$locationId, doctorsAuth($doctor['token']));
    $own->assertOk()
        ->assertJsonPath('data.address', $address)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.version', 1);

    $port = app(GetClinicLocation::class)->handle(Identifier::fromTrusted($locationId));
    expect($port)->not->toBeNull()
        ->and($port->isActive())->toBeTrue()
        ->and($port->doctorId->value)->toBe($doctor['doctor_id']);

    $patched = $this->patchJson(
        '/api/v1/clinic-locations/'.$locationId,
        ['expected_version' => 1, 'public_name' => 'Cairo Nile Clinic'],
        doctorsAuth($doctor['token']),
    );
    $patched->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.public_name', 'Cairo Nile Clinic');

    $secretary = clinicInsertSecretary('e2e');
    $invite = $this->postJson(
        '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
        ['phone' => $secretary['phone']],
        doctorsAuth($doctor['token']) + clinicIdem('e2e-invite'),
    )->assertCreated();
    $invitationId = $invite->json('data.invitation_id');

    $accepted = app(AcceptClinicStaffInvitation::class)->handle(
        clinicSecretaryActor($secretary['user_id']),
        Identifier::fromTrusted($invitationId),
    );
    $membershipId = $accepted->membershipId;

    expect(DB::table('clinic_staff_memberships')->where('status', 'active')->count())->toBe(1)
        ->and(app(ResolveActiveClinicMembership::class)->handle(
            Identifier::fromTrusted($secretary['user_id']),
            Identifier::fromTrusted($locationId),
        )?->membershipId->value)->toBe($membershipId);

    $this->getJson('/api/v1/clinic-locations/'.$locationId.'/memberships', doctorsAuth($doctor['token']))
        ->assertOk()
        ->assertJsonPath('data.0.membership_id', $membershipId)
        ->assertJsonPath('data.0.role', 'secretary')
        ->assertJsonPath('data.0.status', 'active');

    $other = clinicApprovedDoctor('e2e-other');
    $this->getJson('/api/v1/clinic-locations/'.$locationId, doctorsAuth($other['token']))->assertNotFound();
    $this->deleteJson(
        '/api/v1/clinic-locations/'.$locationId.'/memberships/'.$membershipId,
        [],
        doctorsAuth($other['token']),
    )->assertNotFound();

    $this->deleteJson(
        '/api/v1/clinic-locations/'.$locationId.'/memberships/'.$membershipId,
        [],
        doctorsAuth($doctor['token']),
    )->assertOk()->assertJsonPath('data.status', 'revoked');

    expect(app(ResolveActiveClinicMembership::class)->handle(
        Identifier::fromTrusted($secretary['user_id']),
        Identifier::fromTrusted($locationId),
    ))->toBeNull()
        ->and(DB::table('audit_events')->where('event_name', 'clinic.location_created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event_name', 'clinic.location_updated')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event_name', 'clinic.staff_invitation_created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event_name', 'clinic.staff_invitation_accepted')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event_name', 'clinic.staff_membership_revoked')->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'clinic.location_changed')->count())->toBe(2)
        ->and(DB::table('outbox_events')->where('event_type', 'clinic.membership_changed')->count())->toBe(2);

    $otherLocationId = $this->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(publicName: 'Other Clinic'),
        doctorsAuth($other['token']) + clinicIdem('e2e-other-loc'),
    )->json('data.location_id');
    $this->deleteJson(
        '/api/v1/clinic-locations/'.$otherLocationId.'/memberships/'.$membershipId,
        [],
        doctorsAuth($other['token']),
    )->assertNotFound();

    $unapproved = doctorsActiveSession('e2e-unapproved');
    $specialty = doctorsSeedSpecialty('gp_e2e_unapproved');
    $this->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($unapproved['payload']['national_id'], $specialty['id']),
        doctorsAuth($unapproved['token']) + doctorsIdem('e2e-unapproved-onboard'),
    )->assertCreated();
    $this->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(),
        doctorsAuth($unapproved['token']) + clinicIdem('e2e-unapproved'),
    )->assertNotFound();

    $this->patchJson(
        '/api/v1/clinic-locations/'.$locationId,
        ['expected_version' => 1, 'public_name' => 'Stale E2E'],
        doctorsAuth($doctor['token']),
    )->assertStatus(409);

    $wrong = clinicInsertSecretary('e2e-wrong');
    expect(fn () => app(AcceptClinicStaffInvitation::class)->handle(
        clinicSecretaryActor($wrong['user_id']),
        Identifier::fromTrusted($invitationId),
    ))->toThrow(AuthorizationDenied::class);

    expect(fn () => app(AcceptClinicStaffInvitation::class)->handle(
        clinicSecretaryActor($secretary['user_id']),
        Identifier::fromTrusted($invitationId),
    ))->toThrow(AuthorizationDenied::class);

    $expiredSecretary = clinicInsertSecretary('e2e-expired');
    $expiredInvite = $this->postJson(
        '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
        ['phone' => $expiredSecretary['phone']],
        doctorsAuth($doctor['token']) + clinicIdem('e2e-expired-inv'),
    )->assertCreated();
    DB::table('clinic_staff_invitations')->where('id', $expiredInvite->json('data.invitation_id'))->update([
        'expires_at' => now('UTC')->subHour(),
    ]);
    expect(fn () => app(AcceptClinicStaffInvitation::class)->handle(
        clinicSecretaryActor($expiredSecretary['user_id']),
        Identifier::fromTrusted($expiredInvite->json('data.invitation_id')),
    ))->toThrow(AuthorizationDenied::class);
});
