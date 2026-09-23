<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Services\GetClinicLocation;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Services\Telemetry\RedactingLogTap;
use Modules\Platform\Services\Telemetry\TelemetryGateway;
use Modules\Platform\Support\Identifier;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('clinic location foundation', function () {
    it('creates an active Cairo location for an approved doctor with encrypted address and PostGIS evidence', function () {
        $session = clinicApprovedDoctor('happy');
        $address = '12 Secret Street, Cairo';
        $body = clinicLocationBody(address: $address);
        $logHandler = new TestHandler(Level::Debug);
        $monolog = new MonologLogger('clinic-address-canary');
        $monolog->pushHandler($logHandler);
        app(RedactingLogTap::class)(new Logger($monolog));

        $response = $this->postJson(
            '/api/v1/clinic-locations',
            $body,
            doctorsAuth($session['token']) + clinicIdem('cl-happy'),
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', ClinicLocationStatus::Active->value)
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.latitude')
            ->assertJsonMissingPath('data.longitude')
            ->assertJsonMissingPath('data.doctor_id');

        $locationId = $response->json('data.location_id');
        expect($locationId)->toBeString()
            ->and($response->getContent())->not->toContain($address);

        $shown = $this->getJson('/api/v1/clinic-locations/'.$locationId, doctorsAuth($session['token']));
        $shown->assertOk()
            ->assertJsonPath('data.location_id', $locationId)
            ->assertJsonPath('data.public_name', 'Cairo Clinic')
            ->assertJsonPath('data.address', $address)
            ->assertJsonPath('data.country_code', 'EG')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 1);
        expect((float) round((float) $shown->json('data.latitude'), 4))->toBe(30.0444)
            ->and((float) round((float) $shown->json('data.longitude'), 4))->toBe(31.2357);

        $listed = $this->getJson('/api/v1/clinic-locations', doctorsAuth($session['token']));
        $listed->assertOk()->assertJsonPath('data.0.location_id', $locationId)
            ->assertJsonPath('data.0.address', $address);

        $row = DB::table('clinic_locations')->first();
        $geo = DB::selectOne('SELECT ST_X(geography_point::geometry) AS lng, ST_Y(geography_point::geometry) AS lat, ST_SRID(geography_point::geometry) AS srid FROM clinic_locations LIMIT 1');
        $gist = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'clinic_locations' AND indexname = 'clinic_locations_geography_point_gix'");
        $btree = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'clinic_locations' AND indexname = 'clinic_locations_doctor_status_index'");
        $plan = DB::select('EXPLAIN SELECT count(*) FROM clinic_locations WHERE ST_DWithin(geography_point, ST_SetSRID(ST_MakePoint(31.2357, 30.0444), 4326)::geography, 5000)');

        $cipher = BinaryColumn::asString($row->address_ciphertext);
        expect($row)->not->toBeNull()
            ->and((string) $row->doctor_id)->toBe($session['doctor_id'])
            ->and((string) $row->status)->toBe('active')
            ->and(str_contains($cipher, $address))->toBeFalse()
            ->and(strlen($cipher))->toBeGreaterThan(16)
            ->and((float) round((float) $geo->lat, 4))->toBe(30.0444)
            ->and((float) round((float) $geo->lng, 4))->toBe(31.2357)
            ->and((int) $geo->srid)->toBe(4326)
            ->and($gist)->not->toBeNull()
            ->and((string) $gist->indexdef)->toContain('USING gist')
            ->and($btree)->not->toBeNull()
            ->and($plan)->not->toBeEmpty();

        $created = DB::table('outbox_events')->where('event_type', 'clinic.location_changed')->first();
        expect($created)->not->toBeNull();
        $createdPayload = is_string($created->payload) ? $created->payload : json_encode($created->payload);
        expect($createdPayload)->not->toContain($address)
            ->and($createdPayload)->not->toContain('30.0444')
            ->and($createdPayload)->toContain('created')
            ->and($createdPayload)->toContain($locationId)
            ->and($createdPayload)->toContain($session['doctor_id']);

        $auditBlob = json_encode(
            DB::table('audit_events')->where('object_type', 'clinic_location')->get(['event_name', 'metadata', 'actor_id', 'object_id'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect($auditBlob)->not->toContain($address)
            ->and($auditBlob)->not->toContain('30.0444');

        $stored = (string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference');
        expect(strlen($stored))->toBeLessThanOrEqual(255)
            ->and($stored)->not->toContain($address)
            ->and($stored)->toContain($locationId);

        $logBlob = json_encode($logHandler->getRecords(), JSON_INVALID_UTF8_SUBSTITUTE);
        expect($logBlob)->not->toContain($address);

        $spanBlob = json_encode(app(TelemetryGateway::class)->httpSpans(), JSON_INVALID_UTF8_SUBSTITUTE);
        expect($spanBlob)->not->toContain($address);

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->not->toContain($address);

        $port = app(GetClinicLocation::class)->handle(Identifier::fromTrusted($locationId));
        expect($port)->not->toBeNull()
            ->and($port->isActive())->toBeTrue()
            ->and($port->doctorId->value)->toBe($session['doctor_id']);

        $caps = $this->getJson('/api/v1/me/capabilities', doctorsAuth($session['token']));
        $caps->assertOk();
        expect($caps->json('data.capabilities'))->toContain(Capabilities::CLINICS_LOCATION_WRITE)
            ->and($caps->json('data.capabilities'))->not->toContain('clinical.record.read')
            ->and($caps->json('data.capabilities'))->not->toContain('appointments.book');
    });

    it('replays a committed idempotent create without a second location', function () {
        $session = clinicApprovedDoctor('idem');
        $body = clinicLocationBody();
        $headers = doctorsAuth($session['token']) + clinicIdem('cl-idem-same');

        $first = $this->postJson('/api/v1/clinic-locations', $body, $headers);
        $first->assertCreated();
        $second = $this->postJson('/api/v1/clinic-locations', $body, $headers);
        $second->assertCreated()
            ->assertJsonPath('data.location_id', $first->json('data.location_id'))
            ->assertJsonPath('data.version', 1);

        expect(DB::table('clinic_locations')->count())->toBe(1);
    });

    it('rejects the same idempotency key when the payload differs', function () {
        $session = clinicApprovedDoctor('idem-conflict');
        $headers = doctorsAuth($session['token']) + clinicIdem('cl-idem-diff');
        $this->postJson('/api/v1/clinic-locations', clinicLocationBody('First Clinic'), $headers)->assertCreated();

        $this->postJson('/api/v1/clinic-locations', clinicLocationBody('Other Clinic'), $headers)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');

        expect(DB::table('clinic_locations')->count())->toBe(1)
            ->and((string) DB::table('clinic_locations')->value('public_name'))->toBe('First Clinic');
    });

    it('rejects unknown json fields and server-owned mass assignment', function () {
        $session = clinicApprovedDoctor('mass');
        $base = clinicLocationBody();

        foreach ([
            ['doctor_id' => $session['doctor_id']],
            ['status' => 'active'],
            ['version' => 99],
            ['created_by' => $session['user_id']],
            ['verification_status' => 'approved'],
            ['listed' => true],
        ] as $i => $extra) {
            $this->postJson(
                '/api/v1/clinic-locations',
                $base + $extra,
                doctorsAuth($session['token']) + clinicIdem('cl-mass-'.$i),
            )->assertUnprocessable()->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        }

        expect(DB::table('clinic_locations')->count())->toBe(0);
    });

    it('rejects invalid country, illegal WGS-84, and outside-Egypt coordinates', function () {
        $session = clinicApprovedDoctor('geo');

        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(countryCode: 'US'),
            doctorsAuth($session['token']) + clinicIdem('cl-geo-us'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(latitude: 91.0),
            doctorsAuth($session['token']) + clinicIdem('cl-geo-lat'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(latitude: 48.8566, longitude: 2.3522),
            doctorsAuth($session['token']) + clinicIdem('cl-geo-paris'),
        )->assertUnprocessable();

        expect(DB::table('clinic_locations')->count())->toBe(0);
    });

    it('denies unapproved doctors and non-owner account types', function () {
        $draft = doctorsActiveSession('unapproved');
        $specialty = doctorsSeedSpecialty('gp_unapproved');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($draft['payload']['national_id'], $specialty['id']),
            doctorsAuth($draft['token']) + doctorsIdem('clinic-draft-onboard'),
        )->assertCreated();
        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($draft['token']) + clinicIdem('cl-draft'),
        )->assertNotFound();

        foreach (['pending_review', 'rejected', 'suspended'] as $status) {
            $actor = clinicApprovedDoctor('deny-'.$status);
            clinicSetDoctorVerificationStatus($actor['user_id'], $status);
            $this->postJson(
                '/api/v1/clinic-locations',
                clinicLocationBody(),
                doctorsAuth($actor['token']) + clinicIdem('cl-'.$status),
            )->assertNotFound();
        }

        $patient = patientsActiveSession('clinic-patient');
        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            patientsAuth($patient['token']) + clinicIdem('cl-patient'),
        )->assertNotFound();

        $pharmacy = pharmaciesActiveSession('clinic-pharmacy');
        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            pharmaciesAuth($pharmacy['token']) + clinicIdem('cl-pharmacy'),
        )->assertNotFound();

        $secretary = clinicInsertSecretary('create-deny');
        clinicSecretaryLogin($secretary);
        clinicSecretaryPostJson('/api/v1/clinic-locations', clinicLocationBody(), clinicIdem('cl-secretary'))
            ->assertNotFound();

        $admin = adminVerificationInsertAdmin('clinic-deny');
        adminVerificationLogin($admin);
        adminVerificationPostJson('/api/v1/clinic-locations', clinicLocationBody(), clinicIdem('cl-admin'))
            ->assertNotFound();

        expect(DB::table('clinic_locations')->count())->toBe(0);
    });

    it('does not let doctor B read or patch doctor A locations', function () {
        $owner = clinicApprovedDoctor('bola-a');
        $created = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($owner['token']) + clinicIdem('cl-bola-a'),
        )->assertCreated();
        $locationId = $created->json('data.location_id');

        $other = clinicApprovedDoctor('bola-b');
        $this->getJson('/api/v1/clinic-locations/'.$locationId, doctorsAuth($other['token']))
            ->assertNotFound();
        $this->patchJson(
            '/api/v1/clinic-locations/'.$locationId,
            ['expected_version' => 1, 'public_name' => 'Stolen'],
            doctorsAuth($other['token']),
        )->assertNotFound();
        $this->postJson(
            '/api/v1/clinic-locations/'.$locationId.'/staff-invitations',
            ['phone' => $other['payload']['phone']],
            doctorsAuth($other['token']) + clinicIdem('cl-bola-invite'),
        )->assertNotFound();

        expect((string) DB::table('clinic_locations')->value('public_name'))->toBe('Cairo Clinic');
    });

    it('requires expected_version and rejects a stale patch', function () {
        $session = clinicApprovedDoctor('stale');
        $created = $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($session['token']) + clinicIdem('cl-stale-create'),
        )->assertCreated();
        $locationId = $created->json('data.location_id');

        $this->patchJson(
            '/api/v1/clinic-locations/'.$locationId,
            ['public_name' => 'No Version'],
            doctorsAuth($session['token']),
        )->assertUnprocessable();

        $this->patchJson(
            '/api/v1/clinic-locations/'.$locationId,
            ['expected_version' => 1, 'public_name' => 'Updated Clinic', 'address' => '2 Tahrir Square, Cairo'],
            doctorsAuth($session['token']),
        )->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.public_name', 'Updated Clinic')
            ->assertJsonPath('data.address', '2 Tahrir Square, Cairo');

        $this->patchJson(
            '/api/v1/clinic-locations/'.$locationId,
            ['expected_version' => 1, 'public_name' => 'Stale'],
            doctorsAuth($session['token']),
        )->assertStatus(409)->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');

        expect((string) DB::table('clinic_locations')->value('public_name'))->toBe('Updated Clinic')
            ->and((int) DB::table('clinic_locations')->value('version'))->toBe(2)
            ->and(DB::table('outbox_events')->where('event_type', 'clinic.location_changed')->count())->toBe(2);
    });

    it('rejects a database version below 1', function () {
        $session = clinicApprovedDoctor('version-check');
        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($session['token']) + clinicIdem('cl-version-check'),
        )->assertCreated();

        expect(fn () => DB::table('clinic_locations')->update(['version' => 0]))
            ->toThrow(QueryException::class);
    });

    it('lists own locations with a single doctor_profiles lookup', function () {
        $session = clinicApprovedDoctor('list-sql');
        $this->postJson(
            '/api/v1/clinic-locations',
            clinicLocationBody(),
            doctorsAuth($session['token']) + clinicIdem('cl-list-sql'),
        )->assertCreated();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/clinic-locations', doctorsAuth($session['token']))->assertOk();
        $sql = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        $doctorLookups = array_values(array_filter(
            $sql,
            static fn (string $query): bool => str_contains($query, 'from "doctor_profiles"')
                || str_contains($query, 'from doctor_profiles'),
        ));

        expect($doctorLookups)->toHaveCount(1);
    });
});
