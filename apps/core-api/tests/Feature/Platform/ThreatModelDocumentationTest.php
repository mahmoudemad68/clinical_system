<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\ThreatModel\Phase02CompletenessValidator;
use Tests\Support\ThreatModel\Phase02ImplementedSurface;
use Tests\TestCase;

uses(TestCase::class);

it('keeps Phase 00 and Phase 01 threat-model completeness claims aligned with source', function () {
    $root = dirname(base_path(), 2);
    $phase00 = (string) file_get_contents($root.'/docs/threat-models/phase-00-foundation.md');
    $phase01 = (string) file_get_contents($root.'/docs/threat-models/phase-01-identity.md');
    $catalog = (string) file_get_contents($root.'/docs/threat-models/phase-01-entry-points.md');

    expect($phase00)
        ->toContain('eight')
        ->toContain('B8 Electron renderer / Flutter UI')
        ->toContain('B7 CI / staging / production planes')
        ->toContain('baked exact HTTPS')
        ->toContain('cannot expand')
        ->toContain('33398311982')
        ->toContain('11ffb25c7470c4b42fd535e9780b235de57297e4')
        ->toContain('Ubuntu')
        ->toContain('Windows')
        ->toContain('macOS')
        ->toContain('PENDING_INDEPENDENT_ACCEPTANCE')
        ->toContain('G-08-04 remains')
        ->toContain('OPEN')
        ->toContain('SELECT, UPDATE')
        ->toContain('otp_requests')
        ->toContain('whole table')
        ->toContain('platform_diagnostics')
        ->not->toContain('scheme-checked, not allowlisted')
        ->not->toContain('Production API host is scheme-checked');

    expect($phase01)
        ->toContain('lookupDigests')
        ->toContain('AuditedSensitiveDecryptor')
        ->toContain('auth.sensitive_decrypt')
        ->toContain('internal_processing')
        ->toContain('human_disclosure')
        ->toContain('EraseSubjectService')
        ->toContain('Phase01SubjectHoldings')
        ->toContain('FEATURE_AUTH_REGISTRATION')
        ->toContain('FEATURE_AUTH_RECOVERY')
        ->toContain('FEATURE_IDENTITY_PROFILE_CLAIM')
        ->toContain('APP_ENV=production')
        ->toContain('Firebase')
        ->toContain('You have a new notice')
        ->toContain('P01-T13')
        ->toContain('P01-T14')
        ->toContain('P01-T15')
        ->toContain('PENDING_INDEPENDENT_REVIEW')
        ->toContain('G-01-21')
        ->not->toContain('live KMS provider is bound');

    expect($catalog)
        ->toContain('/api/v1/auth/registrations')
        ->toContain('/api/v1/me/capabilities')
        ->toContain('/broadcasting/auth')
        ->toContain('identity:bootstrap-admin')
        ->toContain('identity:rotate-keys')
        ->toContain('identity:apply-due-recoveries')
        ->toContain('auth:prune-expired')
        ->toContain('platform:prune')
        ->toContain('access:prune-expired')
        ->toContain('audit:verify-chain')
        ->toContain('audit:checkpoint-chain')
        ->toContain('outbox:work')
        ->toContain('EraseSubjectService')
        ->toContain('HTTP entry points (27)')
        ->toContain('Non-HTTP security entry points (16)');

    expect(substr_count($phase00, 'subgraph B'))->toBeGreaterThanOrEqual(8);
});

it('structurally validates the Phase 02 threat register and inventories', function () {
    $root = dirname(base_path(), 2);
    $phase02 = (string) file_get_contents($root.'/docs/threat-models/phase-02-onboarding.md');
    $phase02Catalog = (string) file_get_contents($root.'/docs/threat-models/phase-02-entry-points.md');
    $phase02Evidence = (string) file_get_contents($root.'/docs/evidence/phase-02/p02-audit-004-threat-model.md');

    $apiPhp = (string) file_get_contents(base_path('routes/api.php'));
    $fromFile = Phase02ImplementedSurface::httpIdentitiesFromApiPhp($apiPhp);
    $fromLaravel = Phase02ImplementedSurface::httpIdentitiesFromLaravelRoutes(Route::getRoutes());
    expect($fromLaravel)->toEqual($fromFile);

    $doctorTs = (string) file_get_contents($root.'/packages/typescript/desktop_bridge_contracts/src/doctor.ts');
    $pharmacyTs = (string) file_get_contents($root.'/packages/typescript/desktop_bridge_contracts/src/pharmacy.ts');
    $doctorChannels = Phase02ImplementedSurface::channelsFromContract($doctorTs, 'clinic:doctor.');
    $pharmacyChannels = Phase02ImplementedSurface::channelsFromContract($pharmacyTs, 'clinic:pharmacy.');

    $result = (new Phase02CompletenessValidator($root))->validate(
        $phase02,
        $phase02Catalog,
        $phase02Evidence,
        $fromFile,
        $doctorChannels,
        $pharmacyChannels,
    );

    expect($result->issues)->toEqual([])
        ->and($result->statusCounts)->toBe([
            'MITIGATED' => 40,
            'PARTIAL' => 8,
            'OPEN' => 2,
            'NOT_APPLICABLE' => 2,
            'TOTAL' => 52,
        ])
        ->and($result->httpCount)->toBe(44)
        ->and($result->doctorIpcCount)->toBe(17)
        ->and($result->pharmacyIpcCount)->toBe(16)
        ->and($result->actorCount)->toBe(19)
        ->and($result->assetCount)->toBe(23);

    expect($phase02)
        ->toContain('d16fcde5b07844f69547a7ed7be52187a44800d8')
        ->toContain('8083ad0c85ce60a0346910416a83472861d971ca')
        ->toContain('STRIDE')
        ->toContain('**G-08-04:** `OPEN`')
        ->toContain('1961be59aa3ea0ab2e712ebc854d15a23343ca03485d81155aa4c36627c35e37')
        ->toContain('FREEZE_CURRENT_BEHAVIOR')
        ->toContain('READY_FOR_RE_QA')
        ->toContain('QA-P02A003-014')
        ->not->toContain('P02-AUDIT-003 stays OPEN')
        ->not->toContain('READY_TO_MERGE')
        ->not->toContain('closes P02-AUDIT-003');

    expect(substr_count($phase02, 'subgraph F'))->toBeGreaterThanOrEqual(9);

    expect($phase02Evidence)
        ->toContain('P02-AUDIT-004')
        ->toContain('READY_FOR_RE-QA')
        ->toContain('does **not** close P02-AUDIT-003')
        ->toContain('OPEN / UNCHANGED')
        ->toContain('QA-P02A003-014')
        ->toContain('1961be59aa3ea0ab2e712ebc854d15a23343ca03485d81155aa4c36627c35e37');
});
