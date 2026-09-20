<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacySourceType;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Services\PharmacyApplicantService;
use Modules\Pharmacies\Services\PharmacyReviewerService;
use Modules\Platform\Contracts\IdentityGenerator;
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

describe('pharmacy organization onboarding', function () {
    it('creates a draft organization, initial branch, and owner membership with protected fields', function () {
        $session = pharmaciesActiveSession('happy');
        $registration = $session['payload']['registration'];
        $legalName = 'Synthetic Pharmacy LLC';
        $address = '12 Test Street, Cairo';
        $body = pharmaciesOnboardingBody($registration, $session['payload']['phone'], legalName: $legalName, address: $address);
        $phoneCanary = $session['payload']['phone'];
        $logHandler = new TestHandler(Level::Debug);
        $monolog = new MonologLogger('pharmacy-reg-canary');
        $monolog->pushHandler($logHandler);
        app(RedactingLogTap::class)(new Logger($monolog));

        $response = $this->postJson('/api/v1/pharmacy-organizations/onboarding', $body, pharmaciesAuth($session['token']) + pharmaciesIdem('pon-happy'));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'organization_ready')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.legal_registration_identifier')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.latitude')
            ->assertJsonMissingPath('data.longitude')
            ->assertJsonMissingPath('data.registration_ciphertext')
            ->assertJsonMissingPath('data.registration_lookup_hmac')
            ->assertJsonMissingPath('data.registration_key_version');

        $encoded = $response->getContent();
        expect($encoded)->not->toContain($registration)
            ->and($encoded)->not->toContain($legalName)
            ->and($encoded)->not->toContain($address)
            ->and($encoded)->not->toContain($phoneCanary);

        $me = $this->getJson('/api/v1/pharmacy-organizations/me', pharmaciesAuth($session['token']));
        $me->assertOk()
            ->assertJsonPath('data.organization_id', $response->json('data.organization_id'))
            ->assertJsonPath('data.public_name', 'Synthetic Pharmacy')
            ->assertJsonPath('data.verification_status', PharmacyVerificationStatus::Draft->value)
            ->assertJsonPath('data.status', PharmacyOrganizationStatus::Draft->value)
            ->assertJsonPath('data.initial_branch.branch_id', $response->json('data.branch_id'))
            ->assertJsonPath('data.initial_branch.country_code', 'EG')
            ->assertJsonPath('data.membership.membership_id', $response->json('data.membership_id'))
            ->assertJsonPath('data.membership.role', PharmacyMembershipRole::Owner->value)
            ->assertJsonPath('data.membership.status', PharmacyMembershipStatus::Pending->value)
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.legal_registration_identifier')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.latitude')
            ->assertJsonMissingPath('data.user_id');

        expect($me->getContent())->not->toContain($registration)
            ->and($me->getContent())->not->toContain($legalName)
            ->and($me->getContent())->not->toContain($address)
            ->and($me->getContent())->not->toContain($phoneCanary);

        expect(DB::table('pharmacy_organizations')->count())->toBe(1)
            ->and(DB::table('pharmacy_branches')->count())->toBe(1)
            ->and(DB::table('pharmacy_memberships')->count())->toBe(1);

        $org = DB::table('pharmacy_organizations')->first();
        $branch = DB::table('pharmacy_branches')->first();
        $membership = DB::table('pharmacy_memberships')->first();
        $geo = DB::selectOne('SELECT ST_X(geography_point::geometry) AS lng, ST_Y(geography_point::geometry) AS lat, ST_SRID(geography_point::geometry) AS srid FROM pharmacy_branches LIMIT 1');
        $gist = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'pharmacy_branches' AND indexdef ILIKE '%gist%'");

        expect($org)->not->toBeNull()
            ->and((string) $org->verification_status)->toBe('draft')
            ->and((string) $org->status)->toBe('draft')
            ->and(str_contains(BinaryColumn::asString($org->registration_ciphertext), $registration))->toBeFalse()
            ->and(str_contains(BinaryColumn::asString($org->registration_lookup_hmac), $registration))->toBeFalse()
            ->and(str_contains(BinaryColumn::asString($org->legal_name_ciphertext), $legalName))->toBeFalse()
            ->and((string) $membership->user_id)->toBe($session['user_id'])
            ->and((string) $membership->role)->toBe('owner')
            ->and($membership->branch_id)->toBeNull()
            ->and(str_contains(BinaryColumn::asString($branch->address_ciphertext), $address))->toBeFalse()
            ->and(str_contains(BinaryColumn::asString($branch->phone_ciphertext), $phoneCanary))->toBeFalse()
            ->and((float) round((float) $geo->lat, 4))->toBe(30.0444)
            ->and((float) round((float) $geo->lng, 4))->toBe(31.2357)
            ->and((int) $geo->srid)->toBe(4326)
            ->and($gist)->not->toBeNull();

        $created = DB::table('outbox_events')->where('event_type', 'pharmacy.organization_created')->first();
        expect($created)->not->toBeNull();
        $createdPayload = is_string($created->payload) ? $created->payload : json_encode($created->payload);
        expect($createdPayload)->not->toContain($registration)
            ->and($createdPayload)->not->toContain($legalName)
            ->and($createdPayload)->not->toContain($address)
            ->and($createdPayload)->not->toContain($phoneCanary)
            ->and($createdPayload)->not->toContain('legal_registration')
            ->and($createdPayload)->toContain(PharmacySourceType::SelfOnboarding->value)
            ->and($createdPayload)->toContain($session['user_id']);

        $auditBlob = json_encode(
            DB::table('audit_events')->where('object_type', 'pharmacy_organization')->orWhere('event_name', 'pharmacy.onboarding_review_required')->get(['event_name', 'metadata', 'actor_id', 'object_id'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect(is_string($auditBlob))->toBeTrue()
            ->and($auditBlob)->not->toContain($registration)
            ->and($auditBlob)->not->toContain($legalName)
            ->and($auditBlob)->not->toContain($address)
            ->and($auditBlob)->not->toContain('hmac');

        $logBlob = json_encode($logHandler->getRecords(), JSON_INVALID_UTF8_SUBSTITUTE);
        expect($logBlob)->not->toContain($registration)
            ->and($logBlob)->not->toContain($legalName)
            ->and($logBlob)->not->toContain($address);

        $spans = app(TelemetryGateway::class)->httpSpans();
        expect($spans)->not->toBeEmpty();
        $spanBlob = json_encode($spans, JSON_INVALID_UTF8_SUBSTITUTE);
        expect($spanBlob)->not->toContain($registration)
            ->and($spanBlob)->not->toContain($address);

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->toContain('clinic_http_responses_total')
            ->and($metrics)->not->toContain($registration)
            ->and($metrics)->not->toContain($phoneCanary);

        $caps = $this->getJson('/api/v1/me/capabilities', pharmaciesAuth($session['token']));
        $caps->assertOk();
        expect($caps->json('data.capabilities'))->toContain(Capabilities::PHARMACIES_ONBOARDING)
            ->and($caps->json('data.capabilities'))->toContain(Capabilities::PHARMACIES_ORGANIZATION_READ_OWN)
            ->and($caps->json('data.capabilities'))->not->toContain('inventory.adjust')
            ->and($caps->json('data.capabilities'))->not->toContain('pos.sale.complete')
            ->and($caps->json('data.capabilities'))->not->toContain('purchasing.order.create')
            ->and($caps->json('data.capabilities'))->not->toContain('catalog.medication.publish')
            ->and($caps->json('data.capabilities'))->not->toContain('clinical.record.read');

        $applicant = app(PharmacyApplicantService::class)->findByUserId(Identifier::fromTrusted($session['user_id']));
        expect($applicant)->not->toBeNull()
            ->and($applicant->organizationId->value)->toBe($response->json('data.organization_id'))
            ->and($applicant->verificationStatus)->toBe(PharmacyVerificationStatus::Draft);

        $reviewer = app(PharmacyReviewerService::class)->findById(Identifier::fromTrusted($response->json('data.organization_id')));
        expect($reviewer)->not->toBeNull();
        $reviewerBlob = json_encode($reviewer->toArray(), JSON_THROW_ON_ERROR);
        expect($reviewerBlob)->not->toContain($registration)
            ->and($reviewerBlob)->not->toContain($legalName)
            ->and($reviewerBlob)->not->toContain($address)
            ->and($reviewerBlob)->not->toContain('latitude');
    });

    it('replays a committed idempotent onboarding without creating a second organization', function () {
        $session = pharmaciesActiveSession('idem');
        $body = pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']);
        $headers = pharmaciesAuth($session['token']) + pharmaciesIdem('pon-idem-same');

        $first = $this->postJson('/api/v1/pharmacy-organizations/onboarding', $body, $headers);
        $first->assertCreated();
        $second = $this->postJson('/api/v1/pharmacy-organizations/onboarding', $body, $headers);
        $second->assertCreated()
            ->assertJsonPath('data.status', 'organization_ready')
            ->assertJsonPath('data.organization_id', $first->json('data.organization_id'))
            ->assertJsonPath('data.branch_id', $first->json('data.branch_id'))
            ->assertJsonPath('data.membership_id', $first->json('data.membership_id'))
            ->assertJsonPath('data.version', 1);

        $stored = (string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference');
        expect(strlen($stored))->toBeLessThanOrEqual(255)
            ->and($stored)->not->toContain('legal_name')
            ->and($stored)->not->toContain($session['payload']['registration'])
            ->and($stored)->toContain($first->json('data.organization_id'));

        expect(DB::table('pharmacy_organizations')->count())->toBe(1)
            ->and(DB::table('pharmacy_branches')->count())->toBe(1)
            ->and(DB::table('pharmacy_memberships')->count())->toBe(1);
    });

    it('rejects the same idempotency key when the payload differs', function () {
        $session = pharmaciesActiveSession('idem-conflict');
        $headers = pharmaciesAuth($session['token']) + pharmaciesIdem('pon-idem-diff');
        $first = pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], 'First Pharmacy');
        $this->postJson('/api/v1/pharmacy-organizations/onboarding', $first, $headers)->assertCreated();

        $second = pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], 'Other Pharmacy');
        $this->postJson('/api/v1/pharmacy-organizations/onboarding', $second, $headers)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');

        expect(DB::table('pharmacy_organizations')->count())->toBe(1)
            ->and((string) DB::table('pharmacy_organizations')->value('public_name'))->toBe('First Pharmacy');
    });

    it('returns the existing own organization when the same user retries without a new key', function () {
        $session = pharmaciesActiveSession('retry');
        $body = pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']);
        $this->postJson('/api/v1/pharmacy-organizations/onboarding', $body, pharmaciesAuth($session['token']) + pharmaciesIdem('pon-retry-1'))
            ->assertCreated();

        $this->postJson('/api/v1/pharmacy-organizations/onboarding', $body, pharmaciesAuth($session['token']) + pharmaciesIdem('pon-retry-2'))
            ->assertOk()
            ->assertJsonPath('data.status', 'organization_ready');

        expect(DB::table('pharmacy_organizations')->count())->toBe(1);
    });

    it('rejects unknown json fields and server-owned mass assignment', function () {
        $session = pharmaciesActiveSession('mass');
        $base = pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']);

        foreach ([
            ['user_id' => $session['user_id']],
            ['organization_id' => $session['user_id']],
            ['verification_status' => 'approved'],
            ['status' => 'active'],
            ['role' => 'owner'],
            ['membership_status' => 'active'],
            ['version' => 99],
            ['account_type' => 'pharmacy'],
            ['registration_ciphertext' => 'x'],
            ['registration_lookup_hmac' => 'x'],
            ['registration_key_version' => 1],
            ['operating_mode' => 'NATIVE'],
        ] as $i => $extra) {
            $this->postJson(
                '/api/v1/pharmacy-organizations/onboarding',
                $base + $extra,
                pharmaciesAuth($session['token']) + pharmaciesIdem('pon-mass-'.$i),
            )->assertUnprocessable()->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        }

        expect(DB::table('pharmacy_organizations')->count())->toBe(0)
            ->and(DB::table('pharmacy_branches')->count())->toBe(0)
            ->and(DB::table('pharmacy_memberships')->count())->toBe(0);
    });

    it('rejects invalid coordinates, unsupported country, and out-of-area points without leaving rows', function () {
        $session = pharmaciesActiveSession('geo');

        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], latitude: 91.0),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-geo-lat'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], longitude: 181.0),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-geo-lng'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], latitude: 48.8566, longitude: 2.3522),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-geo-paris'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], countryCode: 'US'),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-geo-us'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']) + ['country_codes' => ['EG', 'SA']],
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-geo-multi'),
        )->assertUnprocessable();

        expect(DB::table('pharmacy_organizations')->count())->toBe(0)
            ->and(DB::table('pharmacy_branches')->count())->toBe(0);
    });

    it('denies unauthenticated, pending, and non-pharmacy callers', function () {
        $payload = pharmaciesSyntheticIdentity();

        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($payload['registration'], $payload['phone']),
            pharmaciesIdem('pon-unauth'),
        )->assertUnauthorized();

        $pending = pharmaciesActiveSession('pend', 'pending_phone');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($pending['payload']['registration'], $pending['payload']['phone']),
            pharmaciesAuth($pending['token']) + pharmaciesIdem('pon-pend'),
        )->assertNotFound();

        $patient = patientsActiveSession('as-patient');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($patient['payload']['national_id'], $patient['payload']['phone']),
            patientsAuth($patient['token']) + pharmaciesIdem('pon-patient'),
        )->assertNotFound();

        $this->getJson('/api/v1/pharmacy-organizations/me', patientsAuth($patient['token']))
            ->assertNotFound();

        expect(DB::table('pharmacy_organizations')->count())->toBe(0);
    });

    it('does not disclose or switch an owned organization when another pharmacy submits that registration', function () {
        $owner = pharmaciesActiveSession('owned-a');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($owner['payload']['registration'], $owner['payload']['phone']),
            pharmaciesAuth($owner['token']) + pharmaciesIdem('pon-owned-a'),
        )->assertCreated();

        $other = pharmaciesActiveSession('owned-b');
        $response = $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($owner['payload']['registration'], $other['payload']['phone']),
            pharmaciesAuth($other['token']) + pharmaciesIdem('pon-owned-b'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'manual_review_required')
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.branch_id');
        expect($response->json('errors'))->toBeArray()->toBeEmpty();
        expect($response->getContent())->not->toContain($owner['payload']['registration'])
            ->and($response->getContent())->not->toContain($owner['user_id'])
            ->and(DB::table('pharmacy_organizations')->count())->toBe(1)
            ->and((string) DB::table('pharmacy_memberships')->value('user_id'))->toBe($owner['user_id']);
    });
});

describe('own pharmacy organization', function () {
    it('returns the own projection and refuses another organization id route', function () {
        $session = pharmaciesActiveSession('me');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone'], 'Own Pharmacy'),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-me'),
        )->assertCreated();

        $me = $this->getJson('/api/v1/pharmacy-organizations/me', pharmaciesAuth($session['token']));
        $me->assertOk()
            ->assertJsonPath('data.public_name', 'Own Pharmacy')
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.legal_registration_identifier');

        $other = pharmaciesActiveSession('other');
        $this->getJson('/api/v1/pharmacy-organizations/me', pharmaciesAuth($other['token']))
            ->assertNotFound();

        $organizationId = $me->json('data.organization_id');
        $this->getJson('/api/v1/pharmacy-organizations/'.$organizationId, pharmaciesAuth($other['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/pharmacy-organizations/'.$organizationId, pharmaciesAuth($session['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/pharmacy-organizations/'.$organizationId.'/verification-status', pharmaciesAuth($session['token']))
            ->assertNotFound();
    });
});

describe('pharmacy membership organization/branch integrity', function () {
    it('creates exactly one pending owner membership with a null branch_id', function () {
        $session = pharmaciesActiveSession('tenant-owner');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-tenant-owner'),
        )->assertCreated();

        expect(DB::table('pharmacy_organizations')->count())->toBe(1)
            ->and(DB::table('pharmacy_branches')->count())->toBe(1)
            ->and(DB::table('pharmacy_memberships')->count())->toBe(1);

        $membership = DB::table('pharmacy_memberships')->first();
        expect($membership)->not->toBeNull()
            ->and((string) $membership->user_id)->toBe($session['user_id'])
            ->and((string) $membership->role)->toBe(PharmacyMembershipRole::Owner->value)
            ->and((string) $membership->status)->toBe(PharmacyMembershipStatus::Pending->value)
            ->and($membership->branch_id)->toBeNull()
            ->and((string) $membership->organization_id)->toBe((string) DB::table('pharmacy_organizations')->value('id'));
    });

    it('rejects a branch_operator whose branch belongs to another organization', function () {
        $left = pharmaciesActiveSession('tenant-a');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($left['payload']['registration'], $left['payload']['phone']),
            pharmaciesAuth($left['token']) + pharmaciesIdem('pon-tenant-a'),
        )->assertCreated();
        $right = pharmaciesActiveSession('tenant-b');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($right['payload']['registration'], $right['payload']['phone']),
            pharmaciesAuth($right['token']) + pharmaciesIdem('pon-tenant-b'),
        )->assertCreated();

        $orgA = (string) DB::table('pharmacy_memberships')->where('user_id', $left['user_id'])->value('organization_id');
        $orgB = (string) DB::table('pharmacy_memberships')->where('user_id', $right['user_id'])->value('organization_id');
        $branchA = (string) DB::table('pharmacy_branches')->where('organization_id', $orgA)->value('id');
        $branchB = (string) DB::table('pharmacy_branches')->where('organization_id', $orgB)->value('id');

        expect($orgA)->not->toBe($orgB)
            ->and($branchA)->not->toBe($branchB)
            ->and(DB::table('pharmacy_organizations')->where('id', $orgA)->exists())->toBeTrue()
            ->and(DB::table('pharmacy_branches')->where('id', $branchB)->exists())->toBeTrue();

        $composite = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = 'pharmacy_memberships_organization_branch_fk'",
        );
        expect($composite)->not->toBeNull()
            ->and((string) $composite->definition)->toContain('FOREIGN KEY (organization_id, branch_id)')
            ->and((string) $composite->definition)->toContain('REFERENCES pharmacy_branches(organization_id, id)');
        expect(DB::selectOne(
            "SELECT 1 AS ok FROM pg_constraint WHERE conname = 'pharmacy_memberships_branch_fk'",
        ))->toBeNull();
        expect(DB::selectOne(
            "SELECT 1 AS ok FROM pg_constraint WHERE conname = 'pharmacy_branches_organization_id_id_unique'",
        ))->not->toBeNull();

        $sameOrgOperator = User::factory()->create(['account_type' => AccountType::Pharmacy->value]);
        DB::table('pharmacy_memberships')->insert(pharmaciesMembershipRow(
            $orgA,
            (string) $sameOrgOperator->id,
            $branchA,
            PharmacyMembershipRole::BranchOperator->value,
        ));
        expect(DB::table('pharmacy_memberships')->where('role', PharmacyMembershipRole::BranchOperator->value)->count())->toBe(1);

        $crossOrgOperator = User::factory()->create(['account_type' => AccountType::Pharmacy->value]);
        $crossRow = pharmaciesMembershipRow(
            $orgA,
            (string) $crossOrgOperator->id,
            $branchB,
            PharmacyMembershipRole::BranchOperator->value,
        );

        $sqlState = null;
        try {
            DB::transaction(function () use ($crossRow): void {
                DB::table('pharmacy_memberships')->insert($crossRow);
            });
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
        }

        expect($sqlState)->toBe('23503')
            ->and(DB::table('pharmacy_memberships')->where('id', $crossRow['id'])->exists())->toBeFalse()
            ->and(DB::table('pharmacy_memberships')->where('role', PharmacyMembershipRole::Owner->value)->count())->toBe(2)
            ->and(DB::table('pharmacy_memberships')->where('role', PharmacyMembershipRole::BranchOperator->value)->count())->toBe(1)
            ->and(DB::table('pharmacy_organizations')->count())->toBe(2)
            ->and(DB::table('pharmacy_branches')->count())->toBe(2);
    });
});

describe('pharmacy identity uniqueness', function () {
    it('rejects a second row for the same registration blind index', function () {
        $session = pharmaciesActiveSession('uniq');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-uniq'),
        )->assertCreated();

        $row = DB::table('pharmacy_organizations')->first();
        $ids = app(IdentityGenerator::class);

        expect(fn () => DB::table('pharmacy_organizations')->insert([
            'id' => $ids->next()->value,
            'legal_name_ciphertext' => BinaryColumn::bind(BinaryColumn::asString($row->legal_name_ciphertext)),
            'legal_name_key_version' => $row->legal_name_key_version,
            'public_name' => 'Duplicate',
            'registration_ciphertext' => BinaryColumn::bind(BinaryColumn::asString($row->registration_ciphertext)),
            'registration_lookup_hmac' => BinaryColumn::bind(BinaryColumn::asString($row->registration_lookup_hmac)),
            'registration_key_version' => $row->registration_key_version,
            'verification_status' => 'draft',
            'status' => 'draft',
            'version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('rejects activating an organization that is not approved and rejects invalid versions', function () {
        $session = pharmaciesActiveSession('listed');
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($session['payload']['registration'], $session['payload']['phone']),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-listed'),
        )->assertCreated();

        expect(fn () => DB::table('pharmacy_organizations')->update(['status' => 'active']))
            ->toThrow(QueryException::class);
        expect(fn () => DB::table('pharmacy_organizations')->update(['version' => 0]))
            ->toThrow(QueryException::class);
        expect(fn () => DB::table('pharmacy_branches')->update(['version' => 0]))
            ->toThrow(QueryException::class);
    });
});

describe('pharmacy subject privacy', function () {
    it('tombstones protected pharmacy fields without leaking identifiers', function () {
        $session = pharmaciesActiveSession('erase');
        $registration = $session['payload']['registration'];
        $this->postJson(
            '/api/v1/pharmacy-organizations/onboarding',
            pharmaciesOnboardingBody($registration, $session['payload']['phone'], legalName: 'Erase Pharmacy LLC', address: '99 Hidden Road'),
            pharmaciesAuth($session['token']) + pharmaciesIdem('pon-erase'),
        )->assertCreated();

        $admin = User::factory()->create([
            'account_type' => AccountType::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);
        $operator = new ActorContext(
            Identifier::fromTrusted((string) $admin->id),
            AccountType::Admin,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal2Totp,
            1,
            null,
            Identifier::fromTrusted((string) $admin->id),
            [],
            Capabilities::forActor('admin', true),
        );

        app(EraseSubjectService::class)->handle(
            $operator,
            Identifier::fromTrusted($session['user_id']),
            'subject_erasure',
        );

        $org = DB::table('pharmacy_organizations')->first();
        $branch = DB::table('pharmacy_branches')->first();
        expect($org)->not->toBeNull()
            ->and((string) $org->public_name)->toBe('erased')
            ->and(BinaryColumn::asString($org->registration_ciphertext))->not->toContain($registration)
            ->and(BinaryColumn::asString($org->legal_name_ciphertext))->not->toContain('Erase Pharmacy LLC')
            ->and((string) $branch->public_name)->toBe('erased')
            ->and(BinaryColumn::asString($branch->address_ciphertext))->not->toContain('99 Hidden Road');
    });
});
