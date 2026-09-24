<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Doctors\Exceptions\ConflictingSpecialtyCatalogue;
use Modules\Doctors\Services\InstallApprovedSpecialtyCatalogue;
use Modules\Doctors\Services\ListSpecialties;
use Modules\Doctors\Support\ApprovedSpecialtyCatalogueV1;
use Modules\Doctors\Support\DoctorOnboardingRules;
use Modules\Platform\Contracts\IdentityGenerator;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{version: string, approved_by: string, release_date: string, specialties: list<array{code: string, label_ar: string, label_en: string, active: bool, sort_order: int}>}
 */
function approvedSpecialtyArtifact(): array
{
    $path = ApprovedSpecialtyCatalogueV1::artifactPath();
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['specialties'])->toBeArray();

    return $decoded;
}

function assertSpecialtiesMatchApprovedArtifact(): void
{
    $artifact = approvedSpecialtyArtifact();
    $rows = DB::table('specialties')->orderBy('sort_order')->orderBy('code')->get();
    $approved = ApprovedSpecialtyCatalogueV1::rows();

    expect($rows)->toHaveCount(30)
        ->and($approved)->toHaveCount(30)
        ->and($artifact['specialties'])->toHaveCount(30);

    $codes = [];
    foreach ($rows as $index => $row) {
        $expected = $artifact['specialties'][$index];
        $hardCoded = $approved[$index];
        expect((string) $row->code)->toBe($expected['code'])
            ->and((string) $row->label_ar)->toBe($expected['label_ar'])
            ->and((string) $row->label_en)->toBe($expected['label_en'])
            ->and((bool) $row->active)->toBe($expected['active'])
            ->and((int) $row->sort_order)->toBe($expected['sort_order'])
            ->and((string) $row->id)->toBe($hardCoded['id'])
            ->and((string) $row->code)->toMatch(ApprovedSpecialtyCatalogueV1::CODE_FORMAT)
            ->and((string) $row->id)->toMatch(DoctorOnboardingRules::UUID_V7)
            ->and(UuidV7::isValid((string) $row->id))->toBeTrue();
        $codes[] = (string) $row->code;
    }

    expect($codes)->toBe($artifact['specialties'] === [] ? [] : array_column($artifact['specialties'], 'code'))
        ->and($codes)->toHaveCount(count(array_unique($codes)))
        ->and($codes)->not->toContain('gp_happy')
        ->and($codes)->not->toContain('general_practice')
        ->and($codes)->not->toContain('alpha_http')
        ->and($codes)->not->toContain('e2e_alpha');
}

it('installs exactly the approved 30-row catalogue from an empty specialties table', function () {
    DB::table('specialties')->delete();
    expect(DB::table('specialties')->count())->toBe(0);

    app(InstallApprovedSpecialtyCatalogue::class)->install();

    assertSpecialtiesMatchApprovedArtifact();
    expect(hash_file('sha256', ApprovedSpecialtyCatalogueV1::artifactPath()))
        ->toBe(ApprovedSpecialtyCatalogueV1::ARTIFACT_SHA256);
});

it('is idempotent when the catalogue already equals the approved version', function () {
    expect(DB::table('specialties')->count())->toBe(30);

    app(InstallApprovedSpecialtyCatalogue::class)->install();
    app(InstallApprovedSpecialtyCatalogue::class)->install();

    assertSpecialtiesMatchApprovedArtifact();
});

it('lists the approved active catalogue sorted by sort_order then code', function () {
    $listed = app(ListSpecialties::class)->handle();
    $artifact = approvedSpecialtyArtifact();

    expect($listed)->toHaveCount(30);

    $pairs = array_map(static fn ($row): array => [$row->sortOrder, $row->code], $listed);
    $sorted = $pairs;
    usort($sorted, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: strcmp($left[1], $right[1]));
    expect($pairs)->toBe($sorted);

    foreach ($listed as $index => $row) {
        expect($row->code)->toBe($artifact['specialties'][$index]['code'])
            ->and($row->labelAr)->toBe($artifact['specialties'][$index]['label_ar'])
            ->and($row->labelEn)->toBe($artifact['specialties'][$index]['label_en'])
            ->and($row->sortOrder)->toBe($artifact['specialties'][$index]['sort_order']);
    }
});

it('exposes the same approved catalogue on doctor and admin specialty endpoints', function () {
    $artifact = approvedSpecialtyArtifact();
    $doctor = doctorsActiveSession('approved-cat-doc');
    $doctorList = $this->getJson('/api/v1/doctors/specialties', doctorsAuth($doctor['token']))->assertOk();
    $doctorCodes = collect($doctorList->json('data.specialties'))->pluck('code')->all();

    expect($doctorList->json('data.specialties'))->toHaveCount(30)
        ->and($doctorCodes)->toBe(array_column($artifact['specialties'], 'code'))
        ->and($doctorList->json('data.specialties.0'))->toHaveKey('specialty_id')
        ->and($doctorList->json('data.specialties.0'))->not->toHaveKey('active')
        ->and($doctorList->json('data.specialties.0'))->not->toHaveKey('id')
        ->and($doctorList->json('data.specialties.0.specialty_id'))->toBe(ApprovedSpecialtyCatalogueV1::idFor('family_medicine'));

    clinicClearBrowserSession();
    $admin = adminVerificationInsertAdmin('approved-cat-admin');
    adminVerificationLogin($admin);
    $adminList = adminVerificationGetJson('/api/v1/admin/doctor-applicants/specialties')->assertOk();
    $adminCodes = collect($adminList->json('data.specialties'))->pluck('code')->all();

    expect($adminList->json('data.specialties'))->toHaveCount(30)
        ->and($adminCodes)->toBe($doctorCodes)
        ->and($adminList->json('data.specialties.0.specialty_id'))->toBe($doctorList->json('data.specialties.0.specialty_id'))
        ->and($adminList->getContent())->not->toContain('certified')
        ->and($adminList->getContent())->not->toContain('gp_happy');
});

it('accepts onboarding with an approved specialty and rejects unknown or inactive rows', function () {
    $session = doctorsActiveSession('approved-onboard');
    $created = $this->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($session['payload']['national_id'], ApprovedSpecialtyCatalogueV1::idFor('family_medicine')),
        doctorsAuth($session['token']) + doctorsIdem('approved-onboard'),
    );
    $created->assertCreated()->assertJsonPath('data.status', 'profile_ready');
    expect((string) DB::table('doctor_profiles')->where('id', $created->json('data.doctor_id'))->value('specialty_id'))
        ->toBe(ApprovedSpecialtyCatalogueV1::idFor('family_medicine'));

    $unknown = doctorsActiveSession('unknown-onboard');
    $missing = app(IdentityGenerator::class)->next()->value;
    $this->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($unknown['payload']['national_id'], $missing),
        doctorsAuth($unknown['token']) + doctorsIdem('unknown-onboard'),
    )->assertUnprocessable();

    $inactive = doctorsSeedSpecialty('unapproved_inactive_card', ['active' => false, 'sort_order' => 999]);
    $inactiveSession = doctorsActiveSession('inactive-onboard');
    $this->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($inactiveSession['payload']['national_id'], $inactive['id']),
        doctorsAuth($inactiveSession['token']) + doctorsIdem('inactive-onboard'),
    )->assertUnprocessable();

    expect(DB::table('doctor_profiles')->count())->toBe(1);
});

it('fails closed when a conflicting or synthetic catalogue is already present', function () {
    $before = DB::table('specialties')->orderBy('code')->get()->map(static fn ($row): array => (array) $row)->all();
    doctorsSeedSpecialty('synthetic_test_gp', ['label_en' => 'Synthetic Test', 'sort_order' => 1]);

    expect(fn () => app(InstallApprovedSpecialtyCatalogue::class)->install())
        ->toThrow(ConflictingSpecialtyCatalogue::class, 'Unexpected specialty codes: synthetic_test_gp');

    expect(DB::table('specialties')->where('code', 'synthetic_test_gp')->exists())->toBeTrue()
        ->and(DB::table('specialties')->count())->toBe(31);

    DB::table('specialties')->where('code', 'synthetic_test_gp')->delete();
    DB::table('specialties')->where('code', 'family_medicine')->update([
        'label_en' => 'Not The Approved Label',
        'active' => false,
        'sort_order' => 1,
    ]);

    expect(fn () => app(InstallApprovedSpecialtyCatalogue::class)->install())
        ->toThrow(ConflictingSpecialtyCatalogue::class, 'family_medicine');

    $family = DB::table('specialties')->where('code', 'family_medicine')->first();
    expect((string) $family->label_en)->toBe('Not The Approved Label')
        ->and((bool) $family->active)->toBeFalse()
        ->and((int) $family->sort_order)->toBe(1);

    DB::table('specialties')->delete();
    foreach ($before as $row) {
        DB::table('specialties')->insert([
            'id' => $row['id'],
            'code' => $row['code'],
            'label_ar' => $row['label_ar'],
            'label_en' => $row['label_en'],
            'active' => $row['active'],
            'sort_order' => $row['sort_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }
});

it('fails closed without rewriting a doctor profile that references a conflicting specialty', function () {
    $synthetic = doctorsSeedSpecialty('synthetic_referenced', ['sort_order' => 2]);
    $session = doctorsActiveSession('ref-conflict');
    $created = $this->postJson(
        '/api/v1/doctors/onboarding',
        doctorsOnboardingBody($session['payload']['national_id'], $synthetic['id']),
        doctorsAuth($session['token']) + doctorsIdem('ref-conflict'),
    );
    $created->assertCreated();
    $doctorId = (string) $created->json('data.doctor_id');

    expect(fn () => app(InstallApprovedSpecialtyCatalogue::class)->install())
        ->toThrow(ConflictingSpecialtyCatalogue::class, 'referenced by doctor_profiles');

    expect((string) DB::table('doctor_profiles')->where('id', $doctorId)->value('specialty_id'))
        ->toBe($synthetic['id'])
        ->and(DB::table('specialties')->where('code', 'synthetic_referenced')->exists())->toBeTrue()
        ->and((string) DB::table('specialties')->where('code', 'family_medicine')->value('label_en'))
        ->toBe('Family Medicine');
});
