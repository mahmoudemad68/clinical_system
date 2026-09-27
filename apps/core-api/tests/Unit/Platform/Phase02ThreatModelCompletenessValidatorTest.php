<?php

declare(strict_types=1);

use Tests\Support\ThreatModel\Phase02CompletenessValidator;
use Tests\Support\ThreatModel\Phase02ImplementedSurface;
use Tests\Support\ThreatModel\Phase02MarkdownMutations;

function phase02RemediationRoot(): string
{
    return dirname(__DIR__, 5);
}

/**
 * @return array{
 *     onboarding: string,
 *     catalog: string,
 *     evidence: string,
 *     http: list<string>,
 *     doctor: list<string>,
 *     pharmacy: list<string>,
 *     validator: Phase02CompletenessValidator
 * }
 */
function phase02RemediationFixtures(): array
{
    $root = phase02RemediationRoot();
    $http = Phase02ImplementedSurface::httpIdentitiesFromApiPhp(
        (string) file_get_contents($root.'/apps/core-api/routes/api.php'),
    );
    $doctor = Phase02ImplementedSurface::channelsFromContract(
        (string) file_get_contents($root.'/packages/typescript/desktop_bridge_contracts/src/doctor.ts'),
        'clinic:doctor.',
    );
    $pharmacy = Phase02ImplementedSurface::channelsFromContract(
        (string) file_get_contents($root.'/packages/typescript/desktop_bridge_contracts/src/pharmacy.ts'),
        'clinic:pharmacy.',
    );

    return [
        'onboarding' => (string) file_get_contents($root.'/docs/threat-models/phase-02-onboarding.md'),
        'catalog' => (string) file_get_contents($root.'/docs/threat-models/phase-02-entry-points.md'),
        'evidence' => (string) file_get_contents($root.'/docs/evidence/phase-02/p02-audit-004-threat-model.md'),
        'http' => $http,
        'doctor' => $doctor,
        'pharmacy' => $pharmacy,
        'validator' => new Phase02CompletenessValidator($root),
    ];
}

it('detects deletion of threat T05', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::deleteThreat($fx['onboarding'], 'P02-T05');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('missing threat ID P02-T05');
});

it('detects deletion of threat T45', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::deleteThreat($fx['onboarding'], 'P02-T45');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('missing threat ID P02-T45');
});

it('detects a duplicate threat ID', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::duplicateThreat($fx['onboarding'], 'P02-T12');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('duplicate threat ID P02-T12');
});

it('detects a threat status change that is not reflected in STATUS_COUNTS', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::changeThreatStatus($fx['onboarding'], 'P02-T01', 'OPEN');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse();
    $matched = false;
    foreach ($result->issues as $issue) {
        if (str_contains($issue, 'STATUS_COUNTS MITIGATED=') && str_contains($issue, 'does not match derived')) {
            $matched = true;
        }
    }
    expect($matched)->toBeTrue();
});

it('detects a missing Evidence field on a threat row', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::removeEvidenceField($fx['onboarding'], 'P02-T08');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('P02-T08 missing required field evidence');
});

it('detects removal of the Actors section', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::removeSection($fx['onboarding'], '/^## Actors\b/m');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('missing required section: Actors section');
});

it('detects removal of the Assets section', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::removeSection($fx['onboarding'], '/^## Assets\b/m');
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('missing required section: Assets section');
});

it('detects removal of the Jobs section', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::removeSection($fx['catalog'], '/^## Jobs \/ storage \/ scanner/m');
    $result = $fx['validator']->validate($fx['onboarding'], $mutated, $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('missing required section: Jobs/workers/storage/scanner');
});

it('detects a nonexistent cited repository test', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::citeMissingTest($fx['onboarding']);
    $result = $fx['validator']->validate($mutated, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('P02-T01 cites missing repository evidence NonexistentPhase02RaceTest');
});

it('detects deletion of a middle HTTP route', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::removeMiddleHttpRoute($fx['catalog']);
    $result = $fx['validator']->validate($fx['onboarding'], $mutated, $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse();
    $matched = false;
    foreach ($result->issues as $issue) {
        if (str_contains($issue, 'HTTP inventory missing implemented route')
            || str_contains($issue, 'HTTP declared count')) {
            $matched = true;
        }
    }
    expect($matched)->toBeTrue();
});

it('detects an unexpected HTTP route', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::addFakeHttpRoute($fx['catalog']);
    $result = $fx['validator']->validate($fx['onboarding'], $mutated, $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('HTTP inventory has unexpected route POST /api/v1/verification-appeals');
});

it('detects deletion of an IPC channel', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::removeIpcChannel($fx['catalog'], 'clinic:doctor.evidence.upload');
    $result = $fx['validator']->validate($fx['onboarding'], $mutated, $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('Doctor IPC missing channel clinic:doctor.evidence.upload');
});

it('detects an unexpected IPC channel', function () {
    $fx = phase02RemediationFixtures();
    $mutated = Phase02MarkdownMutations::addFakeIpcChannel($fx['catalog']);
    $result = $fx['validator']->validate($fx['onboarding'], $mutated, $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($result->passed())->toBeFalse()
        ->and($result->issues)->toContain('Doctor IPC unexpected channel clinic:doctor.backdoor.shell');
});

it('detects a false G-08-04 APPROVED claim and allows OPEN wording', function () {
    $fx = phase02RemediationFixtures();
    $approved = Phase02MarkdownMutations::claimG0804Approved($fx['onboarding']);
    $bad = $fx['validator']->validate($approved, $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);
    $good = $fx['validator']->validate($fx['onboarding'], $fx['catalog'], $fx['evidence'], $fx['http'], $fx['doctor'], $fx['pharmacy']);

    expect($bad->passed())->toBeFalse();
    $matched = false;
    foreach ($bad->issues as $issue) {
        if (str_contains($issue, 'forbidden G-08-04')) {
            $matched = true;
        }
    }
    expect($matched)->toBeTrue()
        ->and($good->passed())->toBeTrue();
});
