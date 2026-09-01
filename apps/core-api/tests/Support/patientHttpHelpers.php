<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;

/**
 * @return array{name: string, phone: string, national_id: string, password: string, language: string}
 */
function patientsSyntheticIdentity(): array
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

/**
 * @return array{token: string, payload: array<string, string>, user_id: string}
 */
function patientsActiveSession(string $key): array
{
    auth()->forgetGuards();

    $payload = patientsSyntheticIdentity();
    test()->postJson('/api/v1/auth/registrations', $payload, ['Idempotency-Key' => 'clinic-test-idem-preg-'.$key])->assertCreated();
    app(OutboxDispatcher::class)->dispatchBatch();

    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);
    $code = $sms->lastCodeByPurpose['registration'];

    $verify = test()->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => (string) DB::table('otp_requests')->orderByDesc('created_at')->value('id'),
        'code' => $code,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone-'.$key,
    ], ['Idempotency-Key' => 'clinic-test-idem-pver-'.$key]);
    $verify->assertOk();
    $token = $verify->json('data.access_token');
    expect($token)->toBeString()->not->toBeEmpty();

    $userId = (string) DB::table('users')->orderByDesc('created_at')->value('id');
    DB::table('users')->where('id', $userId)->update([
        'status' => 'active',
        'phone_verified_at' => now('UTC'),
    ]);

    return [
        'token' => $token,
        'payload' => $payload,
        'user_id' => $userId,
    ];
}

/**
 * @return array<string, string>
 */
function patientsAuth(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array<string, mixed>
 */
function patientsDemographics(string $nationalId, string $name = 'Synthetic Patient'): array
{
    return [
        'national_id' => $nationalId,
        'full_name' => $name,
        'gender' => 'female',
        'date_of_birth' => '1990-01-15',
        'height_cm' => 165.5,
        'weight_kg' => 62.3,
        'marital_status' => 'single',
        'blood_type' => 'A+',
    ];
}

/**
 * @return array{Idempotency-Key: string}
 */
function patientsIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-'.$name];
}
