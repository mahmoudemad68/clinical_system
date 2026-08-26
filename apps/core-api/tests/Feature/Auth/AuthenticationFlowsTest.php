<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Contracts\DeliverOtpSms;
use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use App\Modules\Auth\Domain\Contracts\TotpVerifier;
use App\Modules\Auth\Infrastructure\Adapters\RecordingDeliverOtpSms;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Infrastructure\Outbox\OutboxDispatcher;
use App\Modules\Platform\Infrastructure\Persistence\BinaryColumn;
use App\Modules\Platform\Infrastructure\Testing\SyntheticEgyptianData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function syntheticIdentity(): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);

    return [
        'name' => 'Synthetic Patient',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];
}

function lastOtp(string $purpose): string
{
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    return $sms->lastCodeByPurpose[$purpose];
}

function dispatchOutbox(): void
{
    app(OutboxDispatcher::class)->dispatchBatch();
}

/**
 * @return array{Idempotency-Key: string}
 */
function idem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}

describe('patient registration', function () {
    it('creates a pending user, delivers otp after commit, and issues a restricted session', function () {
        $payload = syntheticIdentity();

        $register = $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-key-patient-0001'));

        $register->assertCreated()
            ->assertJsonPath('data.status', 'otp_required')
            ->assertJsonMissingPath('data.access_token');

        dispatchOutbox();

        $challengeId = $register->json('data.challenge_id');
        expect(DB::table('users')->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('event_type', 'auth.otp_delivery_requested')->count())->toBe(1);

        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'test-device',
        ], idem('verify-key-patient-0001'));

        $verify->assertOk()
            ->assertJsonPath('data.status', 'pending_phone')
            ->assertJsonPath('data.session_kind', 'device');

        $token = $verify->json('data.access_token');
        expect($token)->toBeString()->not->toBeEmpty();

        $me = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token]);
        $me->assertOk()
            ->assertJsonPath('data.status', 'pending_phone')
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.phone');

        $denied = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'correct-horse-battery',
            'new_password' => 'another-horse-battery',
        ], ['Authorization' => 'Bearer '.$token]);

        $denied->assertNotFound();

        $stored = DB::table('idempotency_keys')->pluck('response_reference')->all();
        expect(implode("\n", $stored))->not->toContain('access_token')
            ->and(implode("\n", $stored))->not->toContain('refresh_token');
    });

    it('does not authenticate an existing active account via registration otp', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-a'))->assertCreated();
        dispatchOutbox();
        $firstChallenge = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'one',
        ], idem('ver-a'));
        $firstChallenge->assertOk();

        DB::table('users')->update([
            'status' => 'active',
            'phone_verified_at' => now('UTC'),
        ]);

        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-b'))->assertCreated();
        dispatchOutbox();
        $second = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->orderByDesc('created_at')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'two',
        ], idem('ver-b'));

        $second->assertOk()
            ->assertJsonPath('data.status', 'otp_required')
            ->assertJsonMissingPath('data.access_token');
    });
});

describe('privileged login', function () {
    it('requires totp before issuing a session and rejects a replayed step', function () {
        $payload = syntheticIdentity();
        $protector = app(NationalIdProtector::class);
        $phone = $protector->phone($payload['phone']);
        $now = now('UTC');
        $ids = app(IdentityGenerator::class);
        $userId = $ids->next()->value;

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Synthetic Doctor',
            'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($phone)),
            'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
            'phone_key_version' => 1,
            'password_hash' => app(PasswordHasher::class)->hash('correct-horse-battery'),
            'account_type' => 'doctor',
            'status' => 'active',
            'language' => 'en',
            'credential_version' => 1,
            'phone_verified_at' => $now,
            'last_authenticated_at' => null,
            'bootstrap_exempt' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $totp = app(TotpVerifier::class);
        $secret = $totp->generateSecret();
        DB::table('mfa_factors')->insert([
            'id' => $ids->next()->value,
            'user_id' => $userId,
            'factor_type' => 'totp',
            'secret_ciphertext' => BinaryColumn::bind($protector->encryptSecret('mfa_secret', $secret)),
            'key_version' => 1,
            'last_used_counter' => null,
            'last_used_at' => null,
            'verified_at' => $now,
            'disabled_at' => null,
            'disabled_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'phone' => $payload['phone'],
            'password' => 'correct-horse-battery',
            'client_class' => 'doctor_desktop',
            'platform' => 'linux',
            'device_label' => 'clinic-pc',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.status', 'mfa_required')
            ->assertJsonMissingPath('data.access_token');

        $clock = app(Clock::class)->now();
        $code = $totp->codeAt($secret, $clock);
        $challengeId = $login->json('data.challenge_id');

        $ok = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', ['code' => $code]);
        $ok->assertOk()->assertJsonPath('data.session_kind', 'device');

        $replay = $this->postJson('/api/v1/auth/mfa/challenges/'.$challengeId.'/verify', ['code' => $code]);
        $replay->assertUnprocessable();
    });
});

describe('refresh reuse', function () {
    it('revokes the family when a rotated refresh token is presented again', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-refresh'))->assertCreated();
        dispatchOutbox();
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-refresh'));
        $verify->assertOk();

        $firstRefresh = $verify->json('data.refresh_token');
        $rotated = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $firstRefresh,
        ], idem('ref-1'));
        $rotated->assertOk();

        $reuse = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $firstRefresh,
        ], idem('ref-2'));
        $reuse->assertUnauthorized();

        $second = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $rotated->json('data.refresh_token'),
        ], idem('ref-3'));
        $second->assertUnauthorized();
    });

    it('replays a lost refresh response for the same idempotency key', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-replay'))->assertCreated();
        dispatchOutbox();
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-replay'));
        $verify->assertOk();

        $firstRefresh = $verify->json('data.refresh_token');
        $rotated = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $firstRefresh,
        ], idem('ref-lost'));
        $rotated->assertOk();

        $replay = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $firstRefresh,
        ], idem('ref-lost'));
        $replay->assertOk()
            ->assertJsonPath('data.refresh_token', $rotated->json('data.refresh_token'));
    });

    it('revokes the family when a consumed generation is presented', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-n2'))->assertCreated();
        dispatchOutbox();
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-n2'));
        $original = $verify->json('data.refresh_token');

        $first = $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $original,
        ], idem('ref-n2-1'))->assertOk();
        $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $first->json('data.refresh_token'),
        ], idem('ref-n2-2'))->assertOk();

        $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $original,
        ], idem('ref-n2-old'))->assertUnauthorized();
    });

    it('revokes the device session on logout so refresh cannot continue', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-logout'))->assertCreated();
        dispatchOutbox();
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-logout'));
        $access = $verify->json('data.access_token');
        $refresh = $verify->json('data.refresh_token');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$access])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $refresh,
        ], idem('ref-after-logout'))->assertUnauthorized();
    });
});

describe('authorization matrix', function () {
    it('returns 404 for an unknown clinical action through capabilities only listing self-service', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-cap'))->assertCreated();
        dispatchOutbox();
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-cap'));
        $token = $verify->json('data.access_token');

        $this->getJson('/api/v1/me/capabilities', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonMissing(['clinical.record.read']);
    });
});

describe('enumeration and csrf', function () {
    it('returns the same unauthenticated envelope for unknown and wrong passwords', function () {
        $payload = syntheticIdentity();
        $unknown = $this->postJson('/api/v1/auth/login', [
            'phone' => $payload['phone'],
            'password' => 'correct-horse-battery',
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ]);

        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-enum'))->assertCreated();
        dispatchOutbox();
        $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-enum'))->assertOk();

        DB::table('users')->update([
            'status' => 'active',
            'phone_verified_at' => now('UTC'),
        ]);

        $wrong = $this->postJson('/api/v1/auth/login', [
            'phone' => $payload['phone'],
            'password' => 'definitely-not-the-password',
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ]);

        $unknown->assertUnauthorized();
        $wrong->assertUnauthorized();
        expect($unknown->json('errors.0.code'))->toBe($wrong->json('errors.0.code'))
            ->and($unknown->json('errors.0.message'))->toBe($wrong->json('errors.0.message'));
    });

    it('rejects a browser origin login without csrf even when credentials are valid', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-csrf'))->assertCreated();
        dispatchOutbox();
        $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-csrf'))->assertOk();

        DB::table('users')->update([
            'status' => 'active',
            'phone_verified_at' => now('UTC'),
        ]);

        $this->withHeaders(['Origin' => 'http://localhost'])
            ->postJson('/api/v1/auth/login', [
                'phone' => $payload['phone'],
                'password' => $payload['password'],
                'client_class' => 'patient_mobile',
                'platform' => 'android',
                'device_label' => 'phone',
            ])
            ->assertUnauthorized();
    });

    it('rejects unknown json properties on login', function () {
        $this->postJson('/api/v1/auth/login', [
            'phone' => '01900000001',
            'password' => 'correct-horse-battery',
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
            'admin' => true,
        ])->assertUnprocessable();
    });

    it('does not put destination or code facts in the otp outbox payload', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-redact'))->assertCreated();

        $event = DB::table('outbox_events')->where('event_type', 'auth.otp_delivery_requested')->first();
        expect($event)->not->toBeNull();
        $body = json_encode($event);
        expect($body)->not->toContain($payload['phone'])
            ->and($body)->not->toContain($payload['national_id'])
            ->and($body)->not->toContain('otp_code');
    });
});

describe('recovery and claim flags', function () {
    it('completes recovery, increments credential version, and rejects the old refresh token', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-rec'))->assertCreated();
        dispatchOutbox();
        $verify = $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => DB::table('otp_requests')->value('id'),
            'code' => lastOtp('registration'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-rec'));
        $verify->assertOk();
        $oldRefresh = $verify->json('data.refresh_token');

        $this->postJson('/api/v1/auth/recovery/start', [
            'phone' => $payload['phone'],
            'language' => 'en',
        ])->assertOk()->assertJsonPath('data.status', 'otp_required');
        dispatchOutbox();

        $challengeId = DB::table('otp_requests')->where('purpose', 'recovery')->orderByDesc('created_at')->value('id');
        $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => lastOtp('recovery'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'phone',
        ], idem('ver-rec-otp'))->assertOk()->assertJsonPath('data.status', 'recovery_verified');

        $this->postJson('/api/v1/auth/recovery/complete', [
            'challenge_id' => $challengeId,
            'code' => lastOtp('recovery'),
            'password' => 'recovered-horse-battery',
        ], idem('rec-complete'))->assertOk();

        $this->postJson('/api/v1/auth/token/refresh', [
            'refresh_token' => $oldRefresh,
        ], idem('ref-after-rec'))->assertUnauthorized();
    });

    it('hides profile-claim otp as not found while the flag is off', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/otp-requests', [
            'phone' => $payload['phone'],
            'purpose' => 'profile_claim',
            'language' => 'en',
        ], idem('otp-claim-off'))->assertNotFound();
    });
});

describe('otp consume-once and phone reuse', function () {
    it('rejects a second consume of the same registration challenge', function () {
        $payload = syntheticIdentity();
        $register = $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-once'));
        $register->assertCreated();
        dispatchOutbox();
        $challengeId = $register->json('data.challenge_id');
        $code = lastOtp('registration');

        $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'one',
        ], idem('ver-once-a'))->assertOk();

        $this->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'two',
        ], idem('ver-once-b'))->assertUnprocessable();
    });

    it('reuses a pending registration for the same phone instead of creating a second user', function () {
        $payload = syntheticIdentity();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-dup-a'))->assertCreated();
        $this->postJson('/api/v1/auth/registrations', $payload, idem('reg-dup-b'))->assertCreated();

        expect(DB::table('users')->count())->toBe(1);
    });
});

describe('inertia admin login surface', function () {
    it('renders the login page without storing tokens in the document', function () {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertCookie('XSRF-TOKEN')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Login')
                ->has('labels.title')
                ->missing('access_token')
            );

        expect($response->getContent())->not->toContain('localStorage');
    });
});
