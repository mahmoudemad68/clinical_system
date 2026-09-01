<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Patients\Enums\PatientSourceType;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Patients\Services\ResolvePatientHandle;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Services\Telemetry\RedactingLogTap;
use Modules\Platform\Services\Telemetry\TelemetryGateway;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('patient onboarding', function () {
    it('creates an authoritative profile, stores protected national id, and emits safe events', function () {
        $session = patientsActiveSession('happy');
        $body = patientsDemographics($session['payload']['national_id']);
        $canary = $session['payload']['national_id'];
        $logHandler = new TestHandler(Level::Debug);
        $monolog = new MonologLogger('patient-nid-canary');
        $monolog->pushHandler($logHandler);
        app(RedactingLogTap::class)(new Logger($monolog));

        $response = $this->postJson('/api/v1/patients/onboarding', $body, patientsAuth($session['token']) + patientsIdem('pon-happy'));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.profile')
            ->assertJsonMissingPath('data.full_name')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.national_id_ciphertext')
            ->assertJsonMissingPath('data.national_id_lookup_hmac')
            ->assertJsonMissingPath('data.national_id_key_version');

        $encoded = $response->getContent();
        expect($encoded)->not->toContain($canary);

        $me = $this->getJson('/api/v1/patients/me/profile', patientsAuth($session['token']));
        $me->assertOk()->assertJsonPath('data.full_name', 'Synthetic Patient')
            ->assertJsonPath('data.patient_id', $response->json('data.patient_id'));

        expect(DB::table('patient_profiles')->count())->toBe(1);
        $row = DB::table('patient_profiles')->first();
        expect($row)->not->toBeNull();
        $cipher = BinaryColumn::asString($row->national_id_ciphertext);
        $hmac = BinaryColumn::asString($row->national_id_lookup_hmac);
        expect((string) $row->user_id)->toBe($session['user_id'])
            ->and((string) $row->status)->toBe('active')
            ->and($cipher)->not->toBe($canary)
            ->and(strlen($cipher))->toBeGreaterThan(16)
            ->and(strlen($hmac))->toBeGreaterThan(16)
            ->and(str_contains($cipher, $canary))->toBeFalse()
            ->and(str_contains($hmac, $canary))->toBeFalse();

        $created = DB::table('outbox_events')->where('event_type', 'patient.profile_created')->first();
        $linked = DB::table('outbox_events')->where('event_type', 'patient.account_linked')->first();
        expect($created)->not->toBeNull()
            ->and($linked)->not->toBeNull();

        $createdPayload = is_string($created->payload) ? $created->payload : json_encode($created->payload);
        $linkedPayload = is_string($linked->payload) ? $linked->payload : json_encode($linked->payload);
        expect($createdPayload)->not->toContain($canary)
            ->and($createdPayload)->not->toContain('national_id')
            ->and($createdPayload)->toContain('self_onboarding')
            ->and($linkedPayload)->not->toContain($canary)
            ->and($linkedPayload)->not->toContain('national_id');

        $auditBlob = json_encode(
            DB::table('audit_events')->where('object_type', 'patient_profile')->orWhere('event_name', 'patient.onboarding_review_required')->get(['event_name', 'metadata', 'actor_id', 'object_id'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect(is_string($auditBlob))->toBeTrue()
            ->and($auditBlob)->not->toContain($canary);

        $revisionFields = DB::table('patient_demographic_revisions')->pluck('field_name')->all();
        expect($revisionFields)->toContain('full_name', 'gender', 'date_of_birth', 'height_cm', 'weight_kg', 'marital_status', 'blood_type')
            ->and($revisionFields)->not->toContain('national_id');
        $revisionBlob = json_encode(
            DB::table('patient_demographic_revisions')->get(['field_name', 'old_plain', 'new_plain', 'reason_code'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect($revisionBlob)->not->toContain($canary);

        $monolog->info('patient onboarding canary', ['national_id' => $canary]);
        $logRecords = $logHandler->getRecords();
        expect($logRecords)->not->toBeEmpty();
        $logBlob = json_encode($logRecords, JSON_INVALID_UTF8_SUBSTITUTE);
        expect($logBlob)->not->toContain($canary);

        $spans = app(TelemetryGateway::class)->httpSpans();
        expect($spans)->not->toBeEmpty();
        $spanBlob = json_encode($spans, JSON_INVALID_UTF8_SUBSTITUTE);
        expect($spanBlob)->not->toContain($canary);

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->toContain('clinic_http_responses_total')
            ->and($metrics)->not->toContain($canary);
    });

    it('replays a committed idempotent onboarding without creating a second profile', function () {
        $session = patientsActiveSession('idem');
        $body = patientsDemographics($session['payload']['national_id']);
        $headers = patientsAuth($session['token']) + patientsIdem('pon-idem-same');

        $first = $this->postJson('/api/v1/patients/onboarding', $body, $headers);
        $first->assertCreated()
            ->assertJsonPath('data.patient_id', $first->json('data.patient_id'))
            ->assertJsonMissingPath('data.profile');
        $second = $this->postJson('/api/v1/patients/onboarding', $body, $headers);
        $second->assertCreated()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonPath('data.patient_id', $first->json('data.patient_id'))
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.full_name');

        $stored = (string) DB::table('idempotency_keys')->orderByDesc('created_at')->value('response_reference');
        expect(strlen($stored))->toBeLessThanOrEqual(255)
            ->and($stored)->not->toContain('patient_profile')
            ->and($stored)->not->toContain('full_name')
            ->and($stored)->toContain($first->json('data.patient_id'));

        expect(DB::table('patient_profiles')->count())->toBe(1);
    });

    it('returns the existing own profile when the same user retries without a new key', function () {
        $session = patientsActiveSession('retry');
        $body = patientsDemographics($session['payload']['national_id']);
        $this->postJson('/api/v1/patients/onboarding', $body, patientsAuth($session['token']) + patientsIdem('pon-retry-1'))
            ->assertCreated();

        $this->postJson('/api/v1/patients/onboarding', $body, patientsAuth($session['token']) + patientsIdem('pon-retry-2'))
            ->assertOk()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonMissingPath('data.profile');

        expect(DB::table('patient_profiles')->count())->toBe(1);
    });

    it('does not disclose an existing unlinked profile while the claim flag is off', function () {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        $session = patientsActiveSession('claimoff');
        $nid = $session['payload']['national_id'];
        app(CreateUnlinkedPatientProfile::class)->handle(
            patientsUnlinkedActor(),
            patientsDemographics($nid, 'Walk In'),
            patientsCorrelationId(),
        );

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($nid),
            patientsAuth($session['token']) + patientsIdem('pon-claimoff'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'manual_review_required')
            ->assertJsonMissingPath('data.patient_id')
            ->assertJsonMissingPath('data.profile');
        expect($response->json('errors'))->toBeArray()->toBeEmpty();
        expect(DB::table('patient_profiles')->count())->toBe(1)
            ->and(DB::table('patient_profiles')->value('user_id'))->toBeNull()
            ->and($response->getContent())->not->toContain($nid);
    });

    it('invokes the claim boundary without attaching when the flag is on in an isolated test', function () {
        config(['identity.profile_claim_enabled' => true, 'app.env' => 'testing']);
        try {
            expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeTrue();

            $session = patientsActiveSession('claimon');
            $nid = $session['payload']['national_id'];
            $handle = app(CreateUnlinkedPatientProfile::class)->handle(
                patientsUnlinkedActor(),
                patientsDemographics($nid, 'Walk In'),
                patientsCorrelationId(),
            );

            $response = $this->postJson(
                '/api/v1/patients/onboarding',
                patientsDemographics($nid),
                patientsAuth($session['token']) + patientsIdem('pon-claimon'),
            );

            $response->assertOk()
                ->assertJsonPath('data.status', 'manual_review_required')
                ->assertJsonMissingPath('data.profile');

            expect(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
        } finally {
            config(['identity.profile_claim_enabled' => false, 'app.env' => 'testing']);
        }
    });

    it('rejects unknown json fields, mass assignment, and request-controlled status', function () {
        $session = patientsActiveSession('mass');
        $headers = patientsAuth($session['token']) + patientsIdem('pon-mass');
        $base = patientsDemographics($session['payload']['national_id']);

        $this->postJson('/api/v1/patients/onboarding', $base + ['user_id' => $session['user_id']], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');

        $this->postJson('/api/v1/patients/onboarding', $base + ['status' => 'archived'], patientsAuth($session['token']) + patientsIdem('pon-mass-status'))
            ->assertUnprocessable();

        $this->postJson('/api/v1/patients/onboarding', $base + ['version' => 99], patientsAuth($session['token']) + patientsIdem('pon-mass-ver'))
            ->assertUnprocessable();

        expect(DB::table('patient_profiles')->count())->toBe(0);
    });

    it('rejects invalid demographics without leaving a partial profile', function () {
        $session = patientsActiveSession('inv');
        $body = patientsDemographics($session['payload']['national_id']);
        $body['height_cm'] = 0;

        $this->postJson('/api/v1/patients/onboarding', $body, patientsAuth($session['token']) + patientsIdem('pon-inv'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'VALIDATION_FAILED');

        expect(DB::table('patient_profiles')->count())->toBe(0);
    });

    it('denies unauthenticated and pending callers', function () {
        $payload = patientsSyntheticIdentity();
        test()->postJson('/api/v1/auth/registrations', $payload, patientsIdem('preg-pend'))->assertCreated();
        app(OutboxDispatcher::class)->dispatchBatch();
        $sms = app(DeliverOtpSms::class);
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => (string) DB::table('otp_requests')->value('id'),
            'code' => $sms->lastCodeByPurpose['registration'],
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'pending',
        ], patientsIdem('pver-pend'));
        $pendingToken = $verify->json('data.access_token');

        $this->postJson('/api/v1/patients/onboarding', patientsDemographics($payload['national_id']), patientsIdem('pon-unauth'))
            ->assertUnauthorized();

        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($payload['national_id']),
            patientsAuth($pendingToken) + patientsIdem('pon-pend'),
        )->assertNotFound();
    });

    it('does not disclose or switch an owned profile when another user submits that national id', function () {
        $owner = patientsActiveSession('owned-a');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($owner['payload']['national_id']),
            patientsAuth($owner['token']) + patientsIdem('pon-owned-a'),
        )->assertCreated();

        $other = patientsActiveSession('owned-b');
        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($owner['payload']['national_id']),
            patientsAuth($other['token']) + patientsIdem('pon-owned-b'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'manual_review_required')
            ->assertJsonMissingPath('data.patient_id')
            ->assertJsonMissingPath('data.profile');
        expect($response->json('errors'))->toBeArray()->toBeEmpty();
        expect($response->getContent())->not->toContain($owner['payload']['national_id'])
            ->and(DB::table('patient_profiles')->count())->toBe(1)
            ->and((string) DB::table('patient_profiles')->value('user_id'))->toBe($owner['user_id']);
    });
});

describe('own patient profile', function () {
    it('returns the decrypted own projection and refuses another patient id route', function () {
        $session = patientsActiveSession('me');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id'], 'Own Name'),
            patientsAuth($session['token']) + patientsIdem('pon-me'),
        )->assertCreated();

        $me = $this->getJson('/api/v1/patients/me/profile', patientsAuth($session['token']));
        $me->assertOk()
            ->assertJsonPath('data.full_name', 'Own Name')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.national_id_ciphertext')
            ->assertJsonMissingPath('data.national_id_lookup_hmac');

        $other = patientsActiveSession('other');
        $this->getJson('/api/v1/patients/me/profile', patientsAuth($other['token']))
            ->assertNotFound();

        $patientId = $me->json('data.patient_id');
        $this->getJson('/api/v1/patients/'.$patientId, patientsAuth($other['token']))
            ->assertNotFound();
    });
});

describe('demographic updates', function () {
    it('updates allowlisted fields, writes a revision, and conflicts on a stale version', function () {
        $session = patientsActiveSession('patch');
        $created = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('pon-patch'),
        );
        $created->assertCreated();

        $ok = $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'height_cm' => 170,
            'marital_status' => 'married',
        ], patientsAuth($session['token']));

        $ok->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.marital_status', 'married');

        expect(DB::table('patient_demographic_revisions')->count())->toBeGreaterThan(0)
            ->and((int) DB::table('patient_profiles')->value('version'))->toBe(2);

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'weight_kg' => 70,
        ], patientsAuth($session['token']))
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'VERSION_CONFLICT');

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 2,
            'status' => 'archived',
        ], patientsAuth($session['token']))
            ->assertUnprocessable();

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 2,
            'user_id' => $session['user_id'],
        ], patientsAuth($session['token']))
            ->assertUnprocessable();

        expect((string) DB::table('patient_profiles')->value('status'))->toBe('active')
            ->and((string) DB::table('patient_profiles')->value('user_id'))->toBe($session['user_id']);

        expect(fn () => DB::table('patient_demographic_revisions')->update(['reason_code' => 'tamper']))
            ->toThrow(QueryException::class, 'patient_demographic_revisions is append-only');
    });
});

describe('unlinked handle resolution', function () {
    it('denies unlinked create and resolve without the reserved capability', function () {
        $session = patientsActiveSession('ul-deny');
        $nid = $session['payload']['national_id'];
        $actor = patientsSelfActor($session['user_id']);

        expect(fn () => app(CreateUnlinkedPatientProfile::class)->handle(
            $actor,
            patientsDemographics($nid, 'Walk In'),
            patientsCorrelationId(),
        ))->toThrow(AuthorizationDenied::class);

        expect(fn () => app(ResolvePatientHandle::class)->handle(
            $actor,
            $nid,
            patientsCorrelationId(),
        ))->toThrow(AuthorizationDenied::class);

        expect(DB::table('patient_profiles')->count())->toBe(0);
    });

    it('returns an opaque handle and never exposes a public national-id lookup route', function () {
        $synthetic = new SyntheticEgyptianData;
        $nid = $synthetic->nationalId();
        $actor = patientsUnlinkedActor();
        $handle = app(CreateUnlinkedPatientProfile::class)->handle(
            $actor,
            patientsDemographics($nid, 'Walk In'),
            patientsCorrelationId(),
        );

        $resolved = app(ResolvePatientHandle::class)->handle($actor, $nid, patientsCorrelationId());
        expect($resolved)->not->toBeNull()
            ->and($resolved->patientId->equals($handle->patientId))->toBeTrue();

        $again = app(CreateUnlinkedPatientProfile::class)->handle(
            $actor,
            patientsDemographics($nid, 'Walk In Again'),
            patientsCorrelationId(),
        );
        expect($again->patientId->equals($handle->patientId))->toBeTrue()
            ->and(DB::table('patient_profiles')->count())->toBe(1)
            ->and(json_encode(DB::table('outbox_events')->where('event_type', 'patient.profile_created')->value('payload')))
            ->toContain(PatientSourceType::WalkIn->value);

        $lookupAudit = json_encode(
            DB::table('audit_events')->where('event_name', 'patient.handle_lookup')->get(['event_name', 'metadata', 'actor_id', 'object_id'])->all(),
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        expect($lookupAudit)->not->toContain($nid)
            ->and($lookupAudit)->not->toContain('hmac')
            ->and($lookupAudit)->not->toContain('Walk In');

        $this->getJson('/api/v1/patients/lookup?national_id='.$nid)->assertNotFound();
        $this->postJson('/api/v1/patients/lookup', ['national_id' => $nid])->assertNotFound();
    });

    it('rejects a second authoritative row for the same national id blind index', function () {
        $session = patientsActiveSession('uniq');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('pon-uniq'),
        )->assertCreated();

        $row = DB::table('patient_profiles')->first();
        $ids = app(IdentityGenerator::class);

        expect(fn () => DB::table('patient_profiles')->insert([
            'id' => $ids->next()->value,
            'user_id' => null,
            'national_id_ciphertext' => BinaryColumn::bind(BinaryColumn::asString($row->national_id_ciphertext)),
            'national_id_lookup_hmac' => BinaryColumn::bind(BinaryColumn::asString($row->national_id_lookup_hmac)),
            'national_id_key_version' => $row->national_id_key_version,
            'full_name_ciphertext' => BinaryColumn::bind(BinaryColumn::asString($row->full_name_ciphertext)),
            'gender' => 'male',
            'status' => 'active',
            'created_by_type' => 'system',
            'created_by_id' => $ids->next()->value,
            'version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});
