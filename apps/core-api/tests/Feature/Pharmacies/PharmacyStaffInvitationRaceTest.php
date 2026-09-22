<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one pending invitation when the same operator is invited concurrently', function () {
    $owner = pharmaciesApprovedOrganization('race-inv');
    $branchId = test()->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-race-inv-br'),
    )->json('data.branch_id');
    $operator = pharmaciesActiveSession('race-inv-op');
    $body = ['phone' => $operator['payload']['phone']];
    $uri = '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations';

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => $uri,
            'body' => $body,
            'access_token' => $owner['token'],
            'idempotency_key' => 'clinic-test-idem-psi-race-inv-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => $uri,
            'body' => $body,
            'access_token' => $owner['token'],
            'idempotency_key' => 'clinic-test-idem-psi-race-inv-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and(DB::table('pharmacy_staff_invitations')->count())->toBe(1);

    foreach ($statuses as $status) {
        expect(in_array($status, [200, 201], true))->toBeTrue();
    }
});

it('creates one active membership when the same invitation is accepted concurrently', function () {
    $owner = pharmaciesApprovedOrganization('race-acc');
    $branchId = test()->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-race-acc-br'),
    )->json('data.branch_id');
    $operator = pharmaciesActiveSession('race-acc-op');
    $invitationId = test()->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
        ['phone' => $operator['payload']['phone']],
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-race-acc-inv'),
    )->json('data.invitation_id');

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            'body' => [],
            'access_token' => $operator['token'],
            'idempotency_key' => 'clinic-test-idem-psi-race-acc-L',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
            'body' => [],
            'access_token' => $operator['token'],
            'idempotency_key' => 'clinic-test-idem-psi-race-acc-R',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('pharmacy_memberships')->where('role', 'branch_operator')->where('status', 'active')->count())->toBe(1)
        ->and((string) DB::table('pharmacy_staff_invitations')->value('status'))->toBe('consumed');

    foreach ([$pair['left']['error_code'], $pair['right']['error_code']] as $code) {
        expect($code)->not->toBe('INTERNAL_ERROR');
    }
});

it('converges concurrent revoke of the same membership', function () {
    $owner = pharmaciesApprovedOrganization('race-rev');
    $branchId = test()->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-race-rev-br'),
    )->json('data.branch_id');
    $operator = pharmaciesActiveSession('race-rev-op');
    $invitationId = test()->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/staff-invitations',
        ['phone' => $operator['payload']['phone']],
        pharmaciesAuth($owner['token']) + pharmaciesIdem('psi-race-rev-inv'),
    )->json('data.invitation_id');
    $membershipId = test()->postJson(
        '/api/v1/pharmacy-staff-invitations/'.$invitationId.'/accept',
        [],
        pharmaciesAuth($operator['token']) + pharmaciesIdem('psi-race-rev-acc'),
    )->json('data.membership_id');

    $uri = '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId.'/memberships/'.$membershipId;
    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'DELETE',
            'uri' => $uri,
            'body' => [],
            'access_token' => $owner['token'],
        ],
        [
            'op' => 'http',
            'method' => 'DELETE',
            'uri' => $uri,
            'body' => [],
            'access_token' => $owner['token'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and(DB::table('pharmacy_memberships')->where('id', $membershipId)->value('status'))->toBe('revoked')
        ->and((int) DB::table('pharmacy_memberships')->where('id', $membershipId)->value('version'))->toBe(2);

    foreach ([$pair['left']['error_code'], $pair['right']['error_code']] as $code) {
        expect($code)->not->toBe('INTERNAL_ERROR');
    }
});
