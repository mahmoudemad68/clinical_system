<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('refuses the patient Flutter fixture seeder outside local/testing', function () {
    config(['app.env' => 'production']);

    $result = Artisan::call('e2e:seed-patient-flutter', ['--write' => '/tmp/clinic-e2e-patient-flutter.json']);

    expect($result)->toBe(1)
        ->and(Artisan::output())->toContain('disabled outside local/testing');
});

it('refuses to write the patient Flutter fixture outside /tmp', function () {
    config(['app.env' => 'testing']);

    $result = Artisan::call('e2e:seed-patient-flutter', ['--write' => '/var/clinic-e2e-patient.json']);

    expect($result)->toBe(1)
        ->and(Artisan::output())->toContain('/tmp/');
});

it('seeds a fresh patient without a profile and a switch patient with an own profile', function () {
    $path = '/tmp/clinic-e2e-patient-flutter-pest.json';
    @unlink($path);

    $result = Artisan::call('e2e:seed-patient-flutter', ['--write' => $path]);
    expect($result)->toBe(0)->and(is_file($path))->toBeTrue();
    expect(Artisan::output())->not->toContain('national_id');

    $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($fixture['fresh']['phone'] ?? null)->toBeString()
        ->and($fixture['fresh']['password'] ?? null)->toBeString()
        ->and($fixture['fresh']['national_id'] ?? null)->toBeString()
        ->and($fixture['switch']['full_name'] ?? null)->toBe('Patient B')
        ->and($fixture['challenger']['phone'] ?? null)->toBeString()
        ->and($fixture['owner_national_id'] ?? null)->toBeString();

    $protector = app(NationalIdProtector::class);
    $freshUserId = DB::table('users')->where(
        'phone_lookup_hmac',
        BinaryColumn::bind($protector->phoneHmac($protector->phone($fixture['fresh']['phone']))),
    )->value('id');
    $switchUserId = DB::table('users')->where(
        'phone_lookup_hmac',
        BinaryColumn::bind($protector->phoneHmac($protector->phone($fixture['switch']['phone']))),
    )->value('id');
    expect($freshUserId)->toBeString()->not->toBeEmpty()
        ->and($switchUserId)->toBeString()->not->toBeEmpty()
        ->and(DB::table('patient_profiles')->count())->toBe(2)
        ->and(DB::table('patient_profiles')->where('user_id', $freshUserId)->exists())->toBeFalse()
        ->and(DB::table('patient_profiles')->where('user_id', $switchUserId)->exists())->toBeTrue();

    $freshToken = patientFlutterDeviceLogin(
        $this,
        $fixture['fresh']['phone'],
        $fixture['fresh']['password'],
        'seed-fresh',
    );
    $this->withToken($freshToken)
        ->getJson('/api/v1/patients/me/profile')
        ->assertNotFound();

    $switchToken = patientFlutterDeviceLogin(
        $this,
        $fixture['switch']['phone'],
        $fixture['switch']['password'],
        'seed-switch',
    );
    $this->withToken($switchToken)
        ->getJson('/api/v1/patients/me/profile')
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Patient B');

    $challengerToken = patientFlutterDeviceLogin(
        $this,
        $fixture['challenger']['phone'],
        $fixture['challenger']['password'],
        'seed-challenger',
    );
    $this->withToken($challengerToken)->postJson(
        '/api/v1/patients/onboarding',
        [
            'national_id' => $fixture['owner_national_id'],
            'full_name' => 'Challenger',
            'gender' => 'male',
        ],
        [
            'Idempotency-Key' => 'clinic-test-idem-pflutter-challenger',
        ],
    )->assertOk()->assertJsonPath('data.status', 'manual_review_required')
        ->assertJsonMissingPath('data.patient_id');

    @unlink($path);
});

function patientFlutterDeviceLogin(TestCase $test, string $phone, string $password, string $device): string
{
    Auth::forgetGuards();
    $test->flushHeaders();
    $test->clearBrowserSession();

    $login = $test->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => $device,
    ]);
    $login->assertOk()->assertJsonPath('data.session_kind', 'device');
    $token = $login->json('data.access_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return $token;
}
