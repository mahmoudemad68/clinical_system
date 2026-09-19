<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorSourceType;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Services\ListSpecialties;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Support\ActorContext;
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

describe('doctor onboarding', function () {
    it('creates a draft hidden profile, stores protected identifiers, and emits safe events', function () {
        $specialty = doctorsSeedSpecialty('gp_happy');
        $session = doctorsActiveSession('happy');
        $body = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id'], 'SYN-1001');
        $canary = $session['payload']['national_id'];
        $syndicateCanary = 'SYN-1001';
        $logHandler = new TestHandler(Level::Debug);
        $monolog = new MonologLogger('doctor-nid-canary');
        $monolog->pushHandler($logHandler);
        app(RedactingLogTap::class)(new Logger($monolog));

        $response = $this->postJson('/api/v1/doctors/onboarding', $body, doctorsAuth($session['token']) + doctorsIdem('don-happy'));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.profile')
            ->assertJsonMissingPath('data.professional_display_name')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.syndicate_number')
            ->assertJsonMissingPath('data.national_id_ciphertext')
            ->assertJsonMissingPath('data.national_id_lookup_hmac')
            ->assertJsonMissingPath('data.national_id_key_version')
            ->assertJsonMissingPath('data.syndicate_number_ciphertext');

        $encoded = $response->getContent();
        expect($encoded)->not->toContain($canary)
            ->and($encoded)->not->toContain($syndicateCanary);

        $me = $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($session['token']));
        $me->assertOk()
            ->assertJsonPath('data.professional_display_name', 'Synthetic Doctor')
            ->assertJsonPath('data.doctor_id', $response->json('data.doctor_id'))
            ->assertJsonPath('data.specialty_id', $specialty['id'])
            ->assertJsonPath('data.specialty_code', 'gp_happy')
            ->assertJsonPath('data.verification_status', DoctorVerificationStatus::Draft->value)
            ->assertJsonPath('data.public_status', DoctorPublicStatus::Hidden->value)
            ->assertJsonPath('data.approved_at', null)
            ->assertJsonPath('data.suspended_at', null)
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.syndicate_number')
            ->assertJsonMissingPath('data.user_id');

        expect($me->getContent())->not->toContain($canary)
            ->and($me->getContent())->not->toContain($syndicateCanary);

        expect(DB::table('doctor_profiles')->count())->toBe(1);
        $row = DB::table('doctor_profiles')->first();
        expect($row)->not->toBeNull();
        $cipher = BinaryColumn::asString($row->national_id_ciphertext);
        $hmac = BinaryColumn::asString($row->national_id_lookup_hmac);
        $synCipher = BinaryColumn::asString($row->syndicate_number_ciphertext);
        $synHmac = BinaryColumn::asString($row->syndicate_number_lookup_hmac);
        expect((string) $row->user_id)->toBe($session['user_id'])
            ->and((string) $row->verification_status)->toBe('draft')
            ->and((string) $row->public_status)->toBe('hidden')
            ->and($row->approved_at)->toBeNull()
            ->and($cipher)->not->toBe($canary)
            ->and(strlen($cipher))->toBeGreaterThan(16)
            ->and(strlen($hmac))->toBeGreaterThan(16)
            ->and(str_contains($cipher, $canary))->toBeFalse()
            ->and(str_contains($hmac, $canary))->toBeFalse()
            ->and(str_contains($synCipher, $syndicateCanary))->toBeFalse()
            ->and(str_contains($synHmac, $syndicateCanary))->toBeFalse();

        $created = DB::table('outbox_events')->where('event_type', 'doctor.profile_created')->first();
        expect($created)->not->toBeNull();
        $createdPayload = is_string($created->payload) ? $created->payload : json_encode($created->payload);
        expect($createdPayload)->not->toContain($canary)
            ->and($createdPayload)->not->toContain($syndicateCanary)
            ->and($createdPayload)->not->toContain('national_id')
            ->and($createdPayload)->not->toContain('syndicate')
            ->and($createdPayload)->toContain(DoctorSourceType::SelfOnboarding->value)
            ->and($createdPayload)->toContain($session['user_id']);

        $auditBlob = json_encode(
            DB::table('audit_events')->where('object_type', 'doctor_profile')->orWhere('event_name', 'doctor.onboarding_review_required')->get(['event_name', 'metadata', 'actor_id', 'object_id'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect(is_string($auditBlob))->toBeTrue()
            ->and($auditBlob)->not->toContain($canary)
            ->and($auditBlob)->not->toContain($syndicateCanary)
            ->and($auditBlob)->not->toContain('hmac');

        $monolog->info('doctor onboarding canary', ['national_id' => $canary]);
        $logBlob = json_encode($logHandler->getRecords(), JSON_INVALID_UTF8_SUBSTITUTE);
        expect($logBlob)->not->toContain($canary);

        $spans = app(TelemetryGateway::class)->httpSpans();
        expect($spans)->not->toBeEmpty();
        $spanBlob = json_encode($spans, JSON_INVALID_UTF8_SUBSTITUTE);
        expect($spanBlob)->not->toContain($canary)
            ->and($spanBlob)->not->toContain($syndicateCanary);

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->toContain('clinic_http_responses_total')
            ->and($metrics)->not->toContain($canary)
            ->and($metrics)->not->toContain($syndicateCanary);

        $caps = $this->getJson('/api/v1/me/capabilities', doctorsAuth($session['token']));
        $caps->assertOk()->assertJsonMissing(['clinical.record.read', 'clinical.encounter.write']);
        expect($caps->json('data.capabilities'))->toContain(Capabilities::DOCTORS_ONBOARDING)
            ->and($caps->json('data.capabilities'))->toContain(Capabilities::DOCTORS_PROFILE_READ_OWN)
            ->and($caps->json('data.capabilities'))->not->toContain('clinical.record.read');
    });

    it('replays a committed idempotent onboarding without creating a second profile', function () {
        $specialty = doctorsSeedSpecialty('gp_idem');
        $session = doctorsActiveSession('idem');
        $body = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']);
        $headers = doctorsAuth($session['token']) + doctorsIdem('don-idem-same');

        $first = $this->postJson('/api/v1/doctors/onboarding', $body, $headers);
        $first->assertCreated()->assertJsonMissingPath('data.profile');
        $second = $this->postJson('/api/v1/doctors/onboarding', $body, $headers);
        $second->assertCreated()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonPath('data.doctor_id', $first->json('data.doctor_id'))
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.professional_display_name');

        $stored = (string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference');
        expect(strlen($stored))->toBeLessThanOrEqual(255)
            ->and($stored)->not->toContain('doctor_profile')
            ->and($stored)->not->toContain('professional_display_name')
            ->and($stored)->toContain($first->json('data.doctor_id'));

        expect(DB::table('doctor_profiles')->count())->toBe(1);
    });

    it('rejects the same idempotency key when the payload differs', function () {
        $specialty = doctorsSeedSpecialty('gp_idem_conflict');
        $session = doctorsActiveSession('idem-conflict');
        $headers = doctorsAuth($session['token']) + doctorsIdem('don-idem-diff');
        $first = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id'], null, 'First Name');
        $this->postJson('/api/v1/doctors/onboarding', $first, $headers)->assertCreated();

        $second = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id'], null, 'Other Name');
        $this->postJson('/api/v1/doctors/onboarding', $second, $headers)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');

        expect(DB::table('doctor_profiles')->count())->toBe(1)
            ->and((string) DB::table('doctor_profiles')->value('professional_display_name'))->toBe('First Name');
    });

    it('returns the existing own profile when the same user retries without a new key', function () {
        $specialty = doctorsSeedSpecialty('gp_retry');
        $session = doctorsActiveSession('retry');
        $body = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']);
        $this->postJson('/api/v1/doctors/onboarding', $body, doctorsAuth($session['token']) + doctorsIdem('don-retry-1'))
            ->assertCreated();

        $this->postJson('/api/v1/doctors/onboarding', $body, doctorsAuth($session['token']) + doctorsIdem('don-retry-2'))
            ->assertOk()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonMissingPath('data.profile');

        expect(DB::table('doctor_profiles')->count())->toBe(1);
    });

    it('rejects unknown json fields and server-owned mass assignment', function () {
        $specialty = doctorsSeedSpecialty('gp_mass');
        $session = doctorsActiveSession('mass');
        $base = doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']);

        foreach ([
            ['user_id' => $session['user_id']],
            ['verification_status' => 'approved'],
            ['public_status' => 'listed'],
            ['approved_at' => '2026-01-01T00:00:00Z'],
            ['suspended_at' => '2026-01-01T00:00:00Z'],
            ['version' => 99],
            ['account_type' => 'doctor'],
            ['national_id_ciphertext' => 'x'],
            ['national_id_lookup_hmac' => 'x'],
            ['national_id_key_version' => 1],
            ['syndicate_number_ciphertext' => 'x'],
            ['syndicate_number_lookup_hmac' => 'x'],
            ['syndicate_number_key_version' => 1],
        ] as $i => $extra) {
            $this->postJson(
                '/api/v1/doctors/onboarding',
                $base + $extra,
                doctorsAuth($session['token']) + doctorsIdem('don-mass-'.$i),
            )->assertUnprocessable()->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');
        }

        expect(DB::table('doctor_profiles')->count())->toBe(0);
    });

    it('rejects an inactive or unknown specialty without leaving a profile', function () {
        $inactive = doctorsSeedSpecialty('inactive_card', ['active' => false, 'sort_order' => 99]);
        $session = doctorsActiveSession('spec');
        $missing = app(IdentityGenerator::class)->next()->value;

        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $inactive['id']),
            doctorsAuth($session['token']) + doctorsIdem('don-spec-inactive'),
        )->assertUnprocessable();

        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $missing),
            doctorsAuth($session['token']) + doctorsIdem('don-spec-missing'),
        )->assertUnprocessable();

        expect(DB::table('doctor_profiles')->count())->toBe(0);
    });

    it('denies unauthenticated, pending, and non-doctor callers', function () {
        $specialty = doctorsSeedSpecialty('gp_authz');
        $payload = doctorsSyntheticIdentity();

        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($payload['national_id'], $specialty['id']),
            doctorsIdem('don-unauth'),
        )->assertUnauthorized();

        $pending = doctorsActiveSession('pend', 'pending_phone');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($pending['payload']['national_id'], $specialty['id']),
            doctorsAuth($pending['token']) + doctorsIdem('don-pend'),
        )->assertNotFound();

        $patient = patientsActiveSession('as-patient');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($patient['payload']['national_id'], $specialty['id']),
            patientsAuth($patient['token']) + doctorsIdem('don-patient'),
        )->assertNotFound();

        $this->getJson('/api/v1/doctors/me/profile', patientsAuth($patient['token']))
            ->assertNotFound();

        expect(DB::table('doctor_profiles')->count())->toBe(0);
    });

    it('does not disclose or switch an owned profile when another doctor submits that national id', function () {
        $specialty = doctorsSeedSpecialty('gp_owned');
        $owner = doctorsActiveSession('owned-a');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($owner['payload']['national_id'], $specialty['id']),
            doctorsAuth($owner['token']) + doctorsIdem('don-owned-a'),
        )->assertCreated();

        $other = doctorsActiveSession('owned-b');
        $response = $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($owner['payload']['national_id'], $specialty['id']),
            doctorsAuth($other['token']) + doctorsIdem('don-owned-b'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'manual_review_required')
            ->assertJsonMissingPath('data.doctor_id')
            ->assertJsonMissingPath('data.profile');
        expect($response->json('errors'))->toBeArray()->toBeEmpty();
        expect($response->getContent())->not->toContain($owner['payload']['national_id'])
            ->and($response->getContent())->not->toContain($owner['user_id'])
            ->and(DB::table('doctor_profiles')->count())->toBe(1)
            ->and((string) DB::table('doctor_profiles')->value('user_id'))->toBe($owner['user_id']);
    });

    it('does not disclose a syndicate collision', function () {
        $specialty = doctorsSeedSpecialty('gp_syn');
        $owner = doctorsActiveSession('syn-a');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($owner['payload']['national_id'], $specialty['id'], 'SYN-COLLIDE'),
            doctorsAuth($owner['token']) + doctorsIdem('don-syn-a'),
        )->assertCreated();

        $other = doctorsActiveSession('syn-b');
        $response = $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($other['payload']['national_id'], $specialty['id'], 'SYN-COLLIDE'),
            doctorsAuth($other['token']) + doctorsIdem('don-syn-b'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'manual_review_required')
            ->assertJsonMissingPath('data.doctor_id');
        expect($response->getContent())->not->toContain('SYN-COLLIDE')
            ->and($response->getContent())->not->toContain($owner['user_id'])
            ->and(DB::table('doctor_profiles')->count())->toBe(1);
    });

    it('routes a bound-identity mismatch to generic manual review', function () {
        $specialty = doctorsSeedSpecialty('gp_bound');
        $session = doctorsActiveSession('bound');
        $otherNid = doctorsSyntheticIdentity()['national_id'];
        doctorsBindIdentityNationalId($session['user_id'], $otherNid);

        $response = $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']),
            doctorsAuth($session['token']) + doctorsIdem('don-bound'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'manual_review_required')
            ->assertJsonMissingPath('data.doctor_id');
        expect(DB::table('doctor_profiles')->count())->toBe(0)
            ->and($response->getContent())->not->toContain($session['payload']['national_id'])
            ->and($response->getContent())->not->toContain($otherNid);
    });
});

describe('own doctor profile', function () {
    it('returns the own projection and refuses another doctor id route', function () {
        $specialty = doctorsSeedSpecialty('gp_me');
        $session = doctorsActiveSession('me');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $specialty['id'], null, 'Own Clinic Name'),
            doctorsAuth($session['token']) + doctorsIdem('don-me'),
        )->assertCreated();

        $me = $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($session['token']));
        $me->assertOk()
            ->assertJsonPath('data.professional_display_name', 'Own Clinic Name')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.syndicate_number');

        $other = doctorsActiveSession('other');
        $this->getJson('/api/v1/doctors/me/profile', doctorsAuth($other['token']))
            ->assertNotFound();

        $doctorId = $me->json('data.doctor_id');
        $this->getJson('/api/v1/doctors/'.$doctorId, doctorsAuth($other['token']))
            ->assertNotFound();
        $this->getJson('/api/v1/doctors/me/verification-status', doctorsAuth($session['token']))
            ->assertNotFound();
        $this->postJson('/api/v1/doctors/me/verification-submissions', [], doctorsAuth($session['token']) + doctorsIdem('don-ver'))
            ->assertNotFound();
        $this->getJson('/api/v1/specialties', doctorsAuth($session['token']))
            ->assertNotFound();
    });
});

describe('specialty catalogue', function () {
    it('lists active specialties only through the Doctors-owned service', function () {
        doctorsSeedSpecialty('alpha_gp', ['sort_order' => 20, 'label_en' => 'Alpha']);
        doctorsSeedSpecialty('beta_card', ['sort_order' => 10, 'label_en' => 'Beta', 'label_ar' => 'قلب']);
        doctorsSeedSpecialty('zzz_inactive', ['active' => false, 'sort_order' => 1, 'label_en' => 'Hidden']);

        $listed = app(ListSpecialties::class)->handle();
        expect($listed)->toHaveCount(2)
            ->and($listed[0]->code)->toBe('beta_card')
            ->and($listed[1]->code)->toBe('alpha_gp');

        $blob = json_encode(array_map(static fn ($row) => $row->toArray(), $listed), JSON_THROW_ON_ERROR);
        expect($blob)->not->toContain('zzz_inactive')
            ->and($blob)->not->toContain('Hidden');
    });
});

describe('doctor identity uniqueness', function () {
    it('rejects a second row for the same national id blind index', function () {
        $specialty = doctorsSeedSpecialty('gp_uniq');
        $session = doctorsActiveSession('uniq');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']),
            doctorsAuth($session['token']) + doctorsIdem('don-uniq'),
        )->assertCreated();

        $row = DB::table('doctor_profiles')->first();
        $ids = app(IdentityGenerator::class);
        $other = doctorsActiveSession('uniq-b');

        expect(fn () => DB::table('doctor_profiles')->insert([
            'id' => $ids->next()->value,
            'user_id' => $other['user_id'],
            'national_id_ciphertext' => BinaryColumn::bind(BinaryColumn::asString($row->national_id_ciphertext)),
            'national_id_lookup_hmac' => BinaryColumn::bind(BinaryColumn::asString($row->national_id_lookup_hmac)),
            'national_id_key_version' => $row->national_id_key_version,
            'specialty_id' => $specialty['id'],
            'professional_display_name' => 'Duplicate',
            'verification_status' => 'draft',
            'public_status' => 'hidden',
            'version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('rejects listing a doctor that is not approved', function () {
        $specialty = doctorsSeedSpecialty('gp_listed');
        $session = doctorsActiveSession('listed');
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($session['payload']['national_id'], $specialty['id']),
            doctorsAuth($session['token']) + doctorsIdem('don-listed'),
        )->assertCreated();

        expect(fn () => DB::table('doctor_profiles')->update(['public_status' => 'listed']))
            ->toThrow(QueryException::class);
    });
});

describe('doctor subject privacy', function () {
    it('tombstones protected doctor fields without leaking identifiers', function () {
        $specialty = doctorsSeedSpecialty('gp_erase');
        $session = doctorsActiveSession('erase');
        $canary = $session['payload']['national_id'];
        $this->postJson(
            '/api/v1/doctors/onboarding',
            doctorsOnboardingBody($canary, $specialty['id'], 'SYN-ERASE'),
            doctorsAuth($session['token']) + doctorsIdem('don-erase'),
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

        $row = DB::table('doctor_profiles')->where('user_id', $session['user_id'])->first();
        expect($row)->not->toBeNull()
            ->and((string) $row->professional_display_name)->toBe('erased')
            ->and((string) $row->user_id)->toBe($session['user_id'])
            ->and(BinaryColumn::asString($row->national_id_ciphertext))->not->toContain($canary)
            ->and(BinaryColumn::asString($row->syndicate_number_ciphertext))->not->toContain('SYN-ERASE');
    });
});
