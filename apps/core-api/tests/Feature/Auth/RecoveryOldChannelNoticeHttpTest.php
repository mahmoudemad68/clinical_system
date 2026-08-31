<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\SendPush;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\RecordingSendPush;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function oldChannelSms(): RecordingDeliverOtpSms
{
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    return $sms;
}

/**
 * @return list<array{kind: string, locale: string, destination: string, body: string}>
 */
function oldChannelNotices(): array
{
    return oldChannelSms()->notices;
}

function oldChannelSeedPushDevice(string $userId, string $fingerprint): void
{
    $now = now('UTC');
    $ids = app(IdentityGenerator::class);
    $protector = app(NationalIdProtector::class);

    DB::table('user_devices')->insert([
        'id' => $ids->next()->value,
        'user_id' => $userId,
        'platform' => 'android',
        'device_label' => 'pre-recovery-device',
        'token_hash' => BinaryColumn::bind(hash('sha256', $fingerprint, true)),
        'refresh_token_hash' => BinaryColumn::bind(hash('sha256', $fingerprint.'refresh', true)),
        'previous_refresh_token_hash' => null,
        'refresh_family_id' => $ids->next()->value,
        'refresh_generation' => 1,
        'credential_version' => 1,
        'last_seen_at' => $now,
        'expires_at' => $now->addHour(),
        'refresh_expires_at' => $now->addDay(),
        'revoked_at' => null,
        'revoked_reason' => null,
        'push_token_ciphertext' => BinaryColumn::bind($protector->encryptSecret('push_token', $fingerprint)),
        'created_ip_prefix' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

describe('old-channel recovery notification HTTP matrix', function () {
    it('queues an old-channel notice when recovery enters cooling_off', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $push = new RecordingSendPush;
        app()->instance(SendPush::class, $push);
        $patient = recoveryApplyRegisterActivePatient('notice-cool');
        oldChannelSeedPushDevice($patient['id'], 'pre-recovery-device-fingerprint');

        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'notice-cool', 'cooling_off');
        app(OutboxDispatcher::class)->dispatchBatch();

        $notices = oldChannelNotices();
        expect($notices)->toHaveCount(1)
            ->and($notices[0]['kind'])->toBe('queued')
            ->and($notices[0]['body'])->toBe('A recovery request was received for your account. Contact support if this was unexpected.')
            ->and($notices[0]['body'])->not->toContain('password')
            ->and(DB::table('recovery_requests')->where('id', $requestId)->value('applied_at'))->toBeNull()
            ->and(DB::table('notifications')->where('notifiable_id', $patient['id'])->pluck('data')->implode(''))->toContain('auth.recovery_cooling_off')
            ->and($push->sent)->toHaveCount(1)
            ->and($push->sent[0]['token'])->toBe('pre-recovery-device-fingerprint')
            ->and($push->sent[0]['type'])->toBe('auth.recovery_queued');
    });

    it('queues an old-channel notice when privileged recovery enters manual_review', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'notice-manual', 'manual_review');
        app(OutboxDispatcher::class)->dispatchBatch();

        $notices = oldChannelNotices();
        expect($notices)->toHaveCount(1)
            ->and($notices[0]['kind'])->toBe('queued')
            ->and($notices[0]['body'])->not->toContain('password')
            ->and($notices[0]['body'])->not->toContain('changed')
            ->and(DB::table('recovery_requests')->where('id', $requestId)->value('applied_at'))->toBeNull()
            ->and(DB::table('notifications')->where('notifiable_id', $subject['id'])->pluck('data')->implode(''))->toContain('auth.recovery_manual_review');
    });

    it('does not send an applied notice after a failed recovery OTP', function () {
        $patient = recoveryApplyRegisterActivePatient('notice-bad-otp');
        test()->postJson('/api/v1/auth/recovery/start', [
            'phone' => $patient['phone'],
            'language' => 'en',
        ])->assertOk();
        recoveryApplyDispatchOutbox();
        $challengeId = (string) DB::table('otp_requests')->where('purpose', 'recovery')->orderByDesc('created_at')->value('id');
        test()->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => recoveryApplyOtp('recovery'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'recovery',
        ], recoveryApplyIdem('notice-bad-otp-ver'))->assertOk();

        test()->postJson('/api/v1/auth/recovery/complete', [
            'challenge_id' => $challengeId,
            'code' => '000000',
            'password' => 'recovered-horse-battery',
        ], recoveryApplyIdem('notice-bad-otp-complete'))->assertUnprocessable();

        app(OutboxDispatcher::class)->dispatchBatch();

        expect(DB::table('recovery_requests')->count())->toBe(0)
            ->and(oldChannelNotices())->toBe([])
            ->and(DB::table('outbox_events')->where('event_type', 'auth.recovery_old_channel_notice_requested')->count())->toBe(0);
    });

    it('does not send an applied notice when early apply is denied', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('notice-early');
        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'notice-early', 'cooling_off');
        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);

        recoveryApplyPost($requestId, cookieCsrf: true)->assertUnprocessable();
        app(OutboxDispatcher::class)->dispatchBatch();

        $kinds = array_column(oldChannelNotices(), 'kind');
        expect($kinds)->toBe(['queued'])
            ->and(DB::table('recovery_requests')->where('id', $requestId)->value('applied_at'))->toBeNull();
    });

    it('sends exactly one applied notice after a successful operator apply', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'notice-apply', 'manual_review');
        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);

        recoveryApplyPost($requestId, cookieCsrf: true)->assertOk()->assertJsonPath('data.status', 'applied');
        app(OutboxDispatcher::class)->dispatchBatch();

        $kinds = array_column(oldChannelNotices(), 'kind');
        expect($kinds)->toBe(['queued', 'applied'])
            ->and(oldChannelNotices()[1]['body'])->toBe('Account recovery was completed. Contact support if this was unexpected.')
            ->and(DB::table('notifications')->where('notifiable_id', $subject['id'])->pluck('data')->implode(''))->toContain('auth.recovery_applied');
    });

    it('sends exactly one applied notice after a successful scheduled apply', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('notice-due');
        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'notice-due', 'cooling_off');
        $until = new DateTimeImmutable((string) DB::table('recovery_requests')->where('id', $requestId)->value('cooling_off_until'));
        app()->instance(Clock::class, new FrozenClock($until->modify('+1 second')));

        test()->artisan('identity:apply-due-recoveries')->assertSuccessful();
        app(OutboxDispatcher::class)->dispatchBatch();

        $kinds = array_column(oldChannelNotices(), 'kind');
        expect($kinds)->toBe(['queued', 'applied'])
            ->and((string) DB::table('recovery_requests')->where('id', $requestId)->value('status'))->toBe('applied');
    });

    it('does not enqueue a duplicate applied notice when apply is replayed', function () {
        $subject = recoveryApplyInsertPrivileged('doctor');
        $requestId = recoveryApplyComplete($subject['phone'], 'recovered-horse-battery', 'doctor_desktop', 'linux', 'notice-replay', 'manual_review');
        $admin = recoveryApplyInsertPrivileged('admin');
        recoveryApplyLoginAdminWeb($admin['phone'], $admin['password'], $admin['totp_secret']);

        recoveryApplyPost($requestId, cookieCsrf: true)->assertOk();
        recoveryApplyPost($requestId, cookieCsrf: true)->assertUnprocessable();
        app(OutboxDispatcher::class)->dispatchBatch();

        $applied = array_values(array_filter(oldChannelNotices(), fn (array $notice): bool => $notice['kind'] === 'applied'));
        expect($applied)->toHaveCount(1)
            ->and(DB::table('outbox_events')->where('event_type', 'auth.recovery_old_channel_notice_requested')->where('payload->notice_kind', 'applied')->count())->toBe(1);
    });

    it('delivers the notice to the persisted account phone rather than request destination ciphertext', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('notice-dest');
        $protector = app(NationalIdProtector::class);
        $accountE164 = $protector->phone($patient['phone'])->e164();
        $otherE164 = $protector->phone((new SyntheticEgyptianData)->mobileNumber())->e164();

        test()->postJson('/api/v1/auth/recovery/start', [
            'phone' => $patient['phone'],
            'language' => 'en',
        ])->assertOk();
        recoveryApplyDispatchOutbox();
        $challengeId = (string) DB::table('otp_requests')->where('purpose', 'recovery')->orderByDesc('created_at')->value('id');
        test()->postJson('/api/v1/auth/otp-verifications', [
            'challenge_id' => $challengeId,
            'code' => recoveryApplyOtp('recovery'),
            'client_class' => 'patient_mobile',
            'platform' => 'android',
            'device_label' => 'recovery',
        ], recoveryApplyIdem('notice-dest-ver'))->assertOk();

        DB::table('otp_requests')->where('id', $challengeId)->update([
            'destination_ciphertext' => BinaryColumn::bind($protector->encryptPhone($protector->phone($otherE164))),
        ]);

        test()->postJson('/api/v1/auth/recovery/complete', [
            'challenge_id' => $challengeId,
            'code' => recoveryApplyOtp('recovery'),
            'password' => 'recovered-horse-battery',
        ], recoveryApplyIdem('notice-dest-complete'))->assertOk()->assertJsonPath('data.status', 'cooling_off');
        app(OutboxDispatcher::class)->dispatchBatch();

        $notices = oldChannelNotices();
        expect($notices)->toHaveCount(1)
            ->and($notices[0]['destination'])->toBe($accountE164)
            ->and($notices[0]['destination'])->not->toBe($otherE164);
    });

    it('keeps OTP, password, and recovery secrets out of notice payloads', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('notice-redact');
        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'notice-redact', 'cooling_off');
        app(OutboxDispatcher::class)->dispatchBatch();

        $event = DB::table('outbox_events')->where('event_type', 'auth.recovery_old_channel_notice_requested')->first();
        $notice = oldChannelNotices()[0];
        $inbox = (string) DB::table('notifications')->where('notifiable_id', $patient['id'])->value('data');
        $audit = json_encode(DB::table('audit_events')->where('object_id', $patient['id'])->pluck('metadata')->all());
        $payload = is_array($event->payload) ? json_encode($event->payload) : (string) $event->payload;

        expect($event)->not->toBeNull()
            ->and($payload)->toContain($requestId)
            ->and($payload)->not->toContain($patient['phone'])
            ->and($payload)->not->toContain($notice['destination'])
            ->and($payload)->not->toContain(recoveryApplyOtp('recovery'))
            ->and($notice['body'])->not->toContain(recoveryApplyOtp('recovery'))
            ->and($notice['body'])->not->toContain('recovered-horse-battery')
            ->and($inbox)->not->toContain('recovered-horse-battery')
            ->and($inbox)->not->toContain(recoveryApplyOtp('recovery'))
            ->and($audit)->not->toContain('recovered-horse-battery')
            ->and($audit)->not->toContain(recoveryApplyOtp('recovery'));
    });

    it('retries a failed old-channel send without rolling back a successful apply', function () {
        $patient = recoveryApplyRegisterActivePatient('notice-fail');
        oldChannelSms()->failNotices = true;
        $requestId = recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'notice-fail', 'applied');
        app(OutboxDispatcher::class)->dispatchBatch();

        $row = DB::table('outbox_events')->where('event_type', 'auth.recovery_old_channel_notice_requested')->first();
        expect((string) DB::table('recovery_requests')->where('id', $requestId)->value('status'))->toBe('applied')
            ->and(DB::table('recovery_requests')->where('id', $requestId)->value('applied_at'))->not->toBeNull()
            ->and((int) DB::table('users')->where('id', $patient['id'])->value('credential_version'))->toBe(2)
            ->and($row)->not->toBeNull()
            ->and((string) $row->status)->toBe('FAILED')
            ->and(oldChannelNotices())->toBe([]);
    });

    it('leaves OTP delivery unchanged after an old-channel notice', function () {
        config(['identity.recovery.cooling_off_seconds' => 86400]);
        $patient = recoveryApplyRegisterActivePatient('notice-otp');
        recoveryApplyComplete($patient['phone'], 'recovered-horse-battery', 'patient_mobile', 'android', 'notice-otp', 'cooling_off');
        app(OutboxDispatcher::class)->dispatchBatch();
        $recoveryCode = recoveryApplyOtp('recovery');

        $payload = syntheticIdentity();
        test()->postJson('/api/v1/auth/registrations', $payload, recoveryApplyIdem('notice-otp-reg'))->assertCreated();
        recoveryApplyDispatchOutbox();

        expect(recoveryApplyOtp('registration'))->not->toBe('')
            ->and(recoveryApplyOtp('recovery'))->toBe($recoveryCode)
            ->and(array_column(oldChannelSms()->sent, 'purpose'))->toContain('registration')
            ->and(array_column(oldChannelSms()->sent, 'purpose'))->toContain('recovery');
    });
});
