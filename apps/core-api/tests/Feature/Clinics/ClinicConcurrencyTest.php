<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one active membership when the same invitation is accepted concurrently', function () {
    $doctor = clinicApprovedDoctor('race-accept');
    $locationId = test()->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(),
        doctorsAuth($doctor['token']) + clinicIdem('cl-race-accept-loc'),
    )->json('data.location_id');
    $secretary = clinicInsertSecretary('race-accept');
    $invitationId = test()->postJson(
        '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
        ['phone' => $secretary['phone']],
        doctorsAuth($doctor['token']) + clinicIdem('cl-race-accept-inv'),
    )->json('data.invitation_id');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'clinic_accept_invitation',
            'user_id' => $secretary['user_id'],
            'invitation_id' => $invitationId,
        ],
        [
            'op' => 'clinic_accept_invitation',
            'user_id' => $secretary['user_id'],
            'invitation_id' => $invitationId,
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('clinic_staff_memberships')->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('clinic_staff_profiles')->count())->toBe(1)
        ->and((string) DB::table('clinic_staff_invitations')->value('status'))->toBe('consumed');

    foreach ([$pair['left']['error_code'], $pair['right']['error_code']] as $code) {
        expect($code)->not->toBe('INTERNAL_ERROR');
    }
});

it('creates one pending invitation when the same secretary is invited concurrently', function () {
    $doctor = clinicApprovedDoctor('race-invite');
    $locationId = test()->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(),
        doctorsAuth($doctor['token']) + clinicIdem('cl-race-inv-loc'),
    )->json('data.location_id');
    $secretary = clinicInsertSecretary('race-invite');
    $body = ['phone' => $secretary['phone']];

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            'body' => $body,
            'access_token' => $doctor['token'],
            'idempotency_key' => 'clinic-test-idem-cl-race-inv-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            'body' => $body,
            'access_token' => $doctor['token'],
            'idempotency_key' => 'clinic-test-idem-cl-race-inv-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and(DB::table('clinic_staff_invitations')->count())->toBe(1);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 201], true))->toBeTrue();
    }
});

it('rejects a concurrent stale location patch', function () {
    $doctor = clinicApprovedDoctor('race-patch');
    $locationId = test()->postJson(
        '/api/v1/clinic-locations',
        clinicLocationBody(),
        doctorsAuth($doctor['token']) + clinicIdem('cl-race-patch-loc'),
    )->json('data.location_id');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'PATCH',
            'uri' => '/api/v1/clinic-locations/'.$locationId,
            'body' => ['expected_version' => 1, 'public_name' => 'Left Clinic'],
            'access_token' => $doctor['token'],
        ],
        [
            'op' => 'http',
            'method' => 'PATCH',
            'uri' => '/api/v1/clinic-locations/'.$locationId,
            'body' => ['expected_version' => 1, 'public_name' => 'Right Clinic'],
            'access_token' => $doctor['token'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and($statuses)->toContain(409)
        ->and((int) DB::table('clinic_locations')->value('version'))->toBe(2)
        ->and(DB::table('clinic_locations')->count())->toBe(1);
});
