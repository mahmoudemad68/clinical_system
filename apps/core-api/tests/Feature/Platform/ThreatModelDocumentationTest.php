<?php

declare(strict_types=1);

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
