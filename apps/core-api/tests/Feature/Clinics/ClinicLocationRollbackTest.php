<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Services\AcceptClinicStaffInvitation;
use Modules\Clinics\Services\CreateClinicLocation;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\Identifier;
use Tests\Support\FailOnceAppendAuditEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls back location creation when audit append fails', function () {
    $session = clinicApprovedDoctor('rollback-loc');
    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'clinic.location_created'));

    expect(fn () => app(CreateClinicLocation::class)->handle(
        clinicDoctorActor($session['user_id']),
        clinicLocationBody(),
    ))->toThrow(TransientProviderFailure::class);

    expect(DB::table('clinic_locations')->count())->toBe(0)
        ->and(DB::table('outbox_events')->where('event_type', 'clinic.location_changed')->count())->toBe(0);
});

it('rolls back invitation acceptance when audit append fails', function () {
    $doctor = clinicApprovedDoctor('rollback-accept');
    $locationId = $this->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(),
        doctorsAuth($doctor['token']) + clinicIdem('cl-rb-acc-loc'),
    )->json('data.location_id');
    $secretary = clinicInsertSecretary('rollback-accept');
    $invitationId = $this->postJson(
        '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
        ['phone' => $secretary['phone']],
        doctorsAuth($doctor['token']) + clinicIdem('cl-rb-acc-inv'),
    )->json('data.invitation_id');

    $inner = app(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new FailOnceAppendAuditEvent($inner, 'clinic.staff_invitation_accepted'));

    expect(fn () => app(AcceptClinicStaffInvitation::class)->handle(
        clinicSecretaryActor($secretary['user_id']),
        Identifier::fromTrusted($invitationId),
    ))->toThrow(TransientProviderFailure::class);

    expect(DB::table('clinic_staff_memberships')->count())->toBe(0)
        ->and((string) DB::table('clinic_staff_invitations')->value('status'))->toBe('pending')
        ->and(DB::table('outbox_events')->where('event_type', 'clinic.membership_changed')->count())->toBe(0);
});
