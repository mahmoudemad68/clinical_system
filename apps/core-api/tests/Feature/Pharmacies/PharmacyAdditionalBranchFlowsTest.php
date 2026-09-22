<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Telemetry\RedactingLogTap;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('pharmacy additional branch foundation', function () {
    it('creates an active additional branch for an approved owner with encrypted address/phone and PostGIS evidence', function () {
        $owner = pharmaciesApprovedOrganization('happy');
        $address = '12 Secret Street, Cairo';
        $phone = $owner['payload']['phone'];
        $body = pharmaciesBranchBody($phone, address: $address);
        $logHandler = new TestHandler(Level::Debug);
        $monolog = new MonologLogger('pharmacy-branch-canary');
        $monolog->pushHandler($logHandler);
        app(RedactingLogTap::class)(new Logger($monolog));

        $response = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            $body,
            pharmaciesAuth($owner['token']) + pharmaciesIdem('pbr-happy'),
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', PharmacyBranchStatus::Active->value)
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.latitude')
            ->assertJsonMissingPath('data.longitude');

        $branchId = $response->json('data.branch_id');
        expect($branchId)->toBeString()
            ->and($response->getContent())->not->toContain($address)
            ->and($response->getContent())->not->toContain($phone);

        $shown = $this->getJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId,
            pharmaciesAuth($owner['token']),
        );
        $shown->assertOk()
            ->assertJsonPath('data.branch_id', $branchId)
            ->assertJsonPath('data.organization_id', $owner['organization_id'])
            ->assertJsonPath('data.public_name', 'Nasr City Branch')
            ->assertJsonPath('data.address', $address)
            ->assertJsonPath('data.country_code', 'EG')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.address_ciphertext')
            ->assertJsonMissingPath('data.phone_ciphertext');
        expect((float) round((float) $shown->json('data.latitude'), 4))->toBe(30.0444)
            ->and((float) round((float) $shown->json('data.longitude'), 4))->toBe(31.2357);

        $listed = $this->getJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesAuth($owner['token']),
        );
        $listed->assertOk();
        $ids = collect($listed->json('data'))->pluck('branch_id')->all();
        expect($ids)->toContain($branchId)
            ->and($ids)->toContain($owner['branch_id']);

        $row = DB::table('pharmacy_branches')->where('id', $branchId)->first();
        $geo = DB::selectOne('SELECT ST_X(geography_point::geometry) AS lng, ST_Y(geography_point::geometry) AS lat, ST_SRID(geography_point::geometry) AS srid FROM pharmacy_branches WHERE id = ?', [$branchId]);
        $gist = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'pharmacy_branches' AND indexname = 'pharmacy_branches_geography_point_gix'");
        $plan = DB::select('EXPLAIN SELECT count(*) FROM pharmacy_branches WHERE ST_DWithin(geography_point, ST_SetSRID(ST_MakePoint(31.2357, 30.0444), 4326)::geography, 5000)');

        $addressCipher = BinaryColumn::asString($row->address_ciphertext);
        $phoneCipher = BinaryColumn::asString($row->phone_ciphertext);
        expect($row)->not->toBeNull()
            ->and((string) $row->organization_id)->toBe($owner['organization_id'])
            ->and((string) $row->status)->toBe('active')
            ->and(str_contains($addressCipher, $address))->toBeFalse()
            ->and(str_contains($phoneCipher, $phone))->toBeFalse()
            ->and(strlen($addressCipher))->toBeGreaterThan(16)
            ->and((float) round((float) $geo->lat, 4))->toBe(30.0444)
            ->and((float) round((float) $geo->lng, 4))->toBe(31.2357)
            ->and((int) $geo->srid)->toBe(4326)
            ->and($gist)->not->toBeNull()
            ->and((string) $gist->indexdef)->toContain('USING gist')
            ->and($plan)->not->toBeEmpty();

        $logs = json_encode($logHandler->getRecords(), JSON_THROW_ON_ERROR);
        expect($logs)->not->toContain($address)
            ->and($logs)->not->toContain($phone);

        $createdEvent = DB::table('outbox_events')->where('event_type', 'pharmacy.branch_changed')->first();
        expect($createdEvent)->not->toBeNull();
        $audit = json_encode(DB::table('audit_events')->where('event_name', 'pharmacy.branch_created')->value('metadata'));
        $outbox = is_string($createdEvent->payload) ? $createdEvent->payload : json_encode($createdEvent->payload);
        expect($audit)->not->toBe('null')
            ->and($audit)->not->toContain($address)
            ->and($audit)->not->toContain($phone)
            ->and($outbox)->not->toContain($address)
            ->and($outbox)->not->toContain($phone)
            ->and($outbox)->not->toContain('30.0444');

        $caps = $this->getJson('/api/v1/me/capabilities', pharmaciesAuth($owner['token']));
        expect($caps->json('data.capabilities'))->toContain(Capabilities::PHARMACIES_BRANCH_WRITE)
            ->and($caps->json('data.capabilities'))->not->toContain('inventory.adjust')
            ->and($caps->json('data.capabilities'))->not->toContain('pos.sale.complete');
    });

    it('replays the same Idempotency-Key to one branch', function () {
        $owner = pharmaciesApprovedOrganization('idem');
        $body = pharmaciesBranchBody($owner['payload']['phone']);
        $headers = pharmaciesAuth($owner['token']) + pharmaciesIdem('pbr-idem');
        $first = $this->postJson('/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches', $body, $headers);
        $first->assertCreated();
        $second = $this->postJson('/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches', $body, $headers);
        $second->assertCreated()->assertJsonPath('data.branch_id', $first->json('data.branch_id'));
        expect(DB::table('pharmacy_branches')->where('organization_id', $owner['organization_id'])->count())->toBe(2);
    });

    it('rejects stale expected_version with VERSION_CONFLICT', function () {
        $owner = pharmaciesApprovedOrganization('conflict');
        $created = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('pbr-conf-create'),
        )->assertCreated();
        $branchId = $created->json('data.branch_id');

        $this->patchJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId,
            ['expected_version' => 1, 'public_name' => 'Updated Branch'],
            pharmaciesAuth($owner['token']),
        )->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.public_name', 'Updated Branch');

        $stale = $this->patchJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches/'.$branchId,
            ['expected_version' => 1, 'public_name' => 'Stale Branch'],
            pharmaciesAuth($owner['token']),
        );
        $stale->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');
        expect((string) DB::table('pharmacy_branches')->where('id', $branchId)->value('public_name'))->toBe('Updated Branch')
            ->and((int) DB::table('pharmacy_branches')->where('id', $branchId)->value('version'))->toBe(2);
    });

    it('rejects coordinates outside Egypt and non-EG country without clamping', function () {
        $owner = pharmaciesApprovedOrganization('geo');
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone'], latitude: 48.8566, longitude: 2.3522),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('pbr-paris'),
        )->assertStatus(422);
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$owner['organization_id'].'/branches',
            pharmaciesBranchBody($owner['payload']['phone'], countryCode: 'SA'),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('pbr-sa'),
        )->assertStatus(422);
        expect(DB::table('pharmacy_branches')->where('organization_id', $owner['organization_id'])->count())->toBe(1);
    });

    it('denies pending organizations, other owners, operators, patients, doctors, and anonymous', function () {
        $pending = pharmaciesActiveSession('pending');
        $onboard = $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($pending['payload']['registration'], $pending['payload']['phone']),
            pharmaciesAuth($pending['token']) + pharmaciesIdem('pbr-pend-on'),
        )->assertCreated();
        $pendingOrg = (string) $onboard->json('data.organization_id');
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$pendingOrg.'/branches',
            pharmaciesBranchBody($pending['payload']['phone']),
            pharmaciesAuth($pending['token']) + pharmaciesIdem('pbr-pend-create'),
        )->assertNotFound();

        $ownerA = pharmaciesApprovedOrganization('bola-a');
        $ownerB = pharmaciesApprovedOrganization('bola-b');
        $created = $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesBranchBody($ownerA['payload']['phone']),
            pharmaciesAuth($ownerA['token']) + pharmaciesIdem('pbr-bola-create'),
        )->assertCreated();
        $branchA = $created->json('data.branch_id');

        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesAuth($ownerB['token']),
        )->assertNotFound();
        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchA,
            pharmaciesAuth($ownerB['token']),
        )->assertNotFound();
        $this->patchJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches/'.$branchA,
            ['expected_version' => 1, 'public_name' => 'Hijack'],
            pharmaciesAuth($ownerB['token']),
        )->assertNotFound();
        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$ownerB['organization_id'].'/branches/'.$branchA,
            pharmaciesAuth($ownerA['token']),
        )->assertNotFound();
        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/verification-status',
            pharmaciesAuth($ownerB['token']),
        )->assertNotFound();
        $this->getJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/verification-status',
            pharmaciesAuth($ownerA['token']),
        )->assertOk()->assertJsonPath('data.organization_id', $ownerA['organization_id']);

        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesBranchBody($ownerA['payload']['phone']) + ['status' => 'active', 'role' => 'owner'],
            pharmaciesAuth($ownerA['token']) + pharmaciesIdem('pbr-mass'),
        )->assertStatus(422);

        auth()->forgetGuards();
        $this->flushSession();
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesBranchBody($ownerA['payload']['phone']),
            pharmaciesIdem('pbr-anon'),
        )->assertUnauthorized();

        $patient = patientsActiveSession('pbr-patient');
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesBranchBody($ownerA['payload']['phone']),
            pharmaciesAuth($patient['token']) + pharmaciesIdem('pbr-patient'),
        )->assertNotFound();
        $doctor = doctorsActiveSession('pbr-doctor');
        $this->postJson(
            '/api/v1/pharmacy-organizations/'.$ownerA['organization_id'].'/branches',
            pharmaciesBranchBody($ownerA['payload']['phone']),
            pharmaciesAuth($doctor['token']) + pharmaciesIdem('pbr-doctor'),
        )->assertNotFound();
    });
});
