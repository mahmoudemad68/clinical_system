<?php

declare(strict_types=1);

use Modules\Doctors\Support\ApprovedSpecialtyCatalogueV1;
use Modules\Doctors\Support\DoctorOnboardingRules;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the approved specialty artifact metadata and SHA-256 aligned with the installer', function () {
    $artifactPath = ApprovedSpecialtyCatalogueV1::artifactPath();
    $shaPath = ApprovedSpecialtyCatalogueV1::artifactSha256Path();
    $mapPath = ApprovedSpecialtyCatalogueV1::uuidMapPath();

    expect($artifactPath)->toBeFile()
        ->and($shaPath)->toBeFile()
        ->and($mapPath)->toBeFile();

    $artifactJson = (string) file_get_contents($artifactPath);
    $artifact = json_decode($artifactJson, true, 512, JSON_THROW_ON_ERROR);
    $recordedHash = trim((string) file_get_contents($shaPath));
    $computedHash = hash('sha256', $artifactJson);

    expect($computedHash)->toBe(ApprovedSpecialtyCatalogueV1::ARTIFACT_SHA256)
        ->and($recordedHash)->toBe(ApprovedSpecialtyCatalogueV1::ARTIFACT_SHA256)
        ->and($artifact['version'])->toBe('v1.0.0-phase02')
        ->and($artifact['approved_by'])->toBe('Medical Operations & Clinical Informatics Governance Team')
        ->and($artifact['release_date'])->toBe('2026-09-24')
        ->and($artifact['specialties'])->toHaveCount(30)
        ->and($artifactJson)->not->toContain('"id"')
        ->and(ApprovedSpecialtyCatalogueV1::VERSION)->toBe($artifact['version'])
        ->and(ApprovedSpecialtyCatalogueV1::APPROVED_BY)->toBe($artifact['approved_by'])
        ->and(ApprovedSpecialtyCatalogueV1::RELEASE_DATE)->toBe($artifact['release_date'])
        ->and(ApprovedSpecialtyCatalogueV1::ROW_COUNT)->toBe(30);

    $map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
    expect($map['version'])->toBe('v1.0.0-phase02')
        ->and($map['ids'])->toHaveCount(30);

    $phpRows = ApprovedSpecialtyCatalogueV1::rows();
    $codes = [];
    foreach ($artifact['specialties'] as $index => $row) {
        expect($row)->not->toHaveKey('id')
            ->and($row['code'])->toMatch(ApprovedSpecialtyCatalogueV1::CODE_FORMAT)
            ->and($phpRows[$index]['code'])->toBe($row['code'])
            ->and($phpRows[$index]['label_ar'])->toBe($row['label_ar'])
            ->and($phpRows[$index]['label_en'])->toBe($row['label_en'])
            ->and($phpRows[$index]['active'])->toBe($row['active'])
            ->and($phpRows[$index]['sort_order'])->toBe($row['sort_order'])
            ->and($phpRows[$index]['id'])->toBe($map['ids'][$row['code']])
            ->and($phpRows[$index]['id'])->toMatch(DoctorOnboardingRules::UUID_V7);

        expect(UuidV7::isValid($phpRows[$index]['id']))->toBeTrue();
        $codes[] = $row['code'];
    }

    expect($codes)->toHaveCount(30)
        ->and($codes)->toHaveCount(count(array_unique($codes)));
});
