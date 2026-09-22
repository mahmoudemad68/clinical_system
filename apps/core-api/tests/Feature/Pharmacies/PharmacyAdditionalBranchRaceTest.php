<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one additional branch when the same idempotent create is retried concurrently', function () {
    $owner = pharmaciesApprovedOrganization('race-br');
    $body = pharmaciesBranchBody($owner['payload']['phone']);
    $uri = '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches';

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => $uri,
            'body' => $body,
            'access_token' => $owner['token'],
            'idempotency_key' => 'clinic-test-idem-pbr-race-same',
        ],
        [
            'op' => 'http',
            'method' => 'POST',
            'uri' => $uri,
            'body' => $body,
            'access_token' => $owner['token'],
            'idempotency_key' => 'clinic-test-idem-pbr-race-same',
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and(DB::table('pharmacy_branches')->where('organization_id', $owner['organization_id'])->count())->toBe(2);

    foreach ([$pair['left']['error_code'], $pair['right']['error_code']] as $code) {
        expect($code)->not->toBe('INTERNAL_ERROR');
    }
});

it('rejects a concurrent stale branch patch', function () {
    $owner = pharmaciesApprovedOrganization('race-patch');
    $branchId = test()->postJson(
        '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
        pharmaciesBranchBody($owner['payload']['phone']),
        pharmaciesAuth($owner['token']) + pharmaciesIdem('pbr-race-patch-br'),
    )->json('data.branch_id');

    $uri = '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId;
    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'http',
            'method' => 'PATCH',
            'uri' => $uri,
            'body' => ['expected_version' => 1, 'public_name' => 'Left Branch'],
            'access_token' => $owner['token'],
        ],
        [
            'op' => 'http',
            'method' => 'PATCH',
            'uri' => $uri,
            'body' => ['expected_version' => 1, 'public_name' => 'Right Branch'],
            'access_token' => $owner['token'],
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(200)
        ->and($statuses)->toContain(409)
        ->and((int) DB::table('pharmacy_branches')->where('id', $branchId)->value('version'))->toBe(2);
});
