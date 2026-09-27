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

it('keeps Phase 02 threat-model completeness claims aligned with source', function () {
    $root = dirname(base_path(), 2);
    $phase02 = (string) file_get_contents($root.'/docs/threat-models/phase-02-onboarding.md');
    $phase02Catalog = (string) file_get_contents($root.'/docs/threat-models/phase-02-entry-points.md');
    $phase02Evidence = (string) file_get_contents($root.'/docs/evidence/phase-02/p02-audit-004-threat-model.md');

    expect($phase02)
        ->toContain('d16fcde5b07844f69547a7ed7be52187a44800d8')
        ->toContain('STRIDE')
        ->toContain('PENDING_INDEPENDENT_REVIEW')
        ->toContain('G-08-04')
        ->toContain('OPEN')
        ->toContain('EXTERNAL_HUMAN')
        ->toContain('SF-001')
        ->toContain('extract-zip@2.0.1')
        ->toContain('EXTERNAL_POLICY_INPUT_REQUIRED')
        ->toContain('FEATURE_IDENTITY_PROFILE_CLAIM')
        ->toContain('P02-AUDIT-003 stays OPEN')
        ->toContain('P02-AUDIT-005')
        ->toContain('STATUS_COUNTS MITIGATED=39 PARTIAL=8 OPEN=4 NOT_APPLICABLE=1 TOTAL=52')
        ->toContain('P02-T01')
        ->toContain('P02-T12')
        ->toContain('P02-T23')
        ->toContain('P02-T30')
        ->toContain('P02-T45')
        ->toContain('P02-T46')
        ->toContain('P02-T47')
        ->toContain('P02-T48')
        ->toContain('P02-T49')
        ->toContain('P02-T52')
        ->not->toContain('READY_TO_MERGE')
        ->not->toContain('closes P02-AUDIT-003');

    expect(substr_count($phase02, 'subgraph F'))->toBeGreaterThanOrEqual(9);

    expect($phase02Catalog)
        ->toContain('HTTP entry points (44)')
        ->toContain('Electron IPC entry points (33 domain + inherited auth)')
        ->toContain('Non-HTTP security entry points (18)')
        ->toContain('/api/v1/patients/onboarding')
        ->toContain('/api/v1/verification-review-files/{caseId}/{documentId}')
        ->toContain('/api/v1/admin/verification-cases/{caseId}/decisions')
        ->toContain('clinic:doctor.evidence.select')
        ->toContain('clinic:pharmacy.evidence.upload')
        ->toContain('verification:reconcile-uploads')
        ->toContain('CreateUnlinkedPatientProfile')
        ->toContain('FEATURE_IDENTITY_PROFILE_CLAIM')
        ->toContain('intentionally internal')
        ->toContain('deferred');

    expect($phase02Evidence)
        ->toContain('P02-AUDIT-004')
        ->toContain('READY_FOR_INDEPENDENT_QA')
        ->toContain('does **not** close P02-AUDIT-003')
        ->toContain('G-08-04 remains OPEN')
        ->toContain('OPEN / UNCHANGED');
});
