<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    $freshLogin = $this->postJson('/api/v1/auth/login', [
        'phone' => $fixture['fresh']['phone'],
        'password' => $fixture['fresh']['password'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'seed-fresh',
    ]);
    $freshLogin->assertOk();
    $freshToken = $freshLogin->json('data.access_token');
    expect($freshToken)->toBeString()->not->toBeEmpty();
    $this->getJson('/api/v1/patients/me/profile', ['Authorization' => 'Bearer '.$freshToken])
        ->assertNotFound();

    $switchLogin = $this->postJson('/api/v1/auth/login', [
        'phone' => $fixture['switch']['phone'],
        'password' => $fixture['switch']['password'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'seed-switch',
    ]);
    $switchLogin->assertOk();
    $this->getJson('/api/v1/patients/me/profile', [
        'Authorization' => 'Bearer '.$switchLogin->json('data.access_token'),
    ])->assertOk()->assertJsonPath('data.full_name', 'Patient B');

    $challengerLogin = $this->postJson('/api/v1/auth/login', [
        'phone' => $fixture['challenger']['phone'],
        'password' => $fixture['challenger']['password'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'seed-challenger',
    ]);
    $challengerLogin->assertOk();
    $this->postJson(
        '/api/v1/patients/onboarding',
        [
            'national_id' => $fixture['owner_national_id'],
            'full_name' => 'Challenger',
            'gender' => 'male',
        ],
        [
            'Authorization' => 'Bearer '.$challengerLogin->json('data.access_token'),
            'Idempotency-Key' => 'clinic-test-idem-pflutter-challenger',
        ],
    )->assertOk()->assertJsonPath('data.status', 'manual_review_required')
        ->assertJsonMissingPath('data.patient_id');

    @unlink($path);
});
