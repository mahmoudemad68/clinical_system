<?php

declare(strict_types=1);

use Modules\Verification\Support\ApprovedVerificationPolicyV1;
use Modules\Verification\Support\VerificationPolicy;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the approved verification policy artifact aligned with the PHP source', function () {
    $artifactPath = ApprovedVerificationPolicyV1::artifactPath();
    $shaPath = ApprovedVerificationPolicyV1::artifactSha256Path();

    expect($artifactPath)->toBeFile()
        ->and($shaPath)->toBeFile();

    $artifactJson = (string) file_get_contents($artifactPath);
    $artifact = json_decode($artifactJson, true, 512, JSON_THROW_ON_ERROR);
    $recordedHash = trim((string) file_get_contents($shaPath));
    $computedHash = hash('sha256', $artifactJson);

    expect($computedHash)->toBe(ApprovedVerificationPolicyV1::ARTIFACT_SHA256)
        ->and($recordedHash)->toBe(ApprovedVerificationPolicyV1::ARTIFACT_SHA256)
        ->and($artifact['version'])->toBe('v1.0.1-phase02')
        ->and($artifact['supersedes'])->toBe('v1.0.0-phase02')
        ->and($artifact['release_date'])->toBe('2026-09-24')
        ->and($artifact['status'])->toBe('APPROVED_PRODUCTION_POLICY')
        ->and($artifact['approved_by']['product'])->toBe('prod-gov-lead@system.internal')
        ->and($artifact['approved_by']['privacy'])->toBe('privacy-dpo@system.internal')
        ->and($artifact['approved_by']['security'])->toBe('ciso-gov@system.internal')
        ->and($artifact['supersession_reason'])->toBe(ApprovedVerificationPolicyV1::SUPERSESSION_REASON)
        ->and($artifactJson)->not->toContain('ENGINEERING_DEFAULT')
        ->and(ApprovedVerificationPolicyV1::VERSION)->toBe($artifact['version'])
        ->and(ApprovedVerificationPolicyV1::SUPERSEDES)->toBe($artifact['supersedes'])
        ->and(ApprovedVerificationPolicyV1::RELEASE_DATE)->toBe($artifact['release_date'])
        ->and(ApprovedVerificationPolicyV1::STATUS)->toBe($artifact['status'])
        ->and($artifact['doctor_requirements'])->toBe(ApprovedVerificationPolicyV1::doctorRequirements())
        ->and($artifact['pharmacy_requirements'])->toBe(ApprovedVerificationPolicyV1::pharmacyRequirements())
        ->and($artifact['decision_reasons'])->toBe(ApprovedVerificationPolicyV1::decisionReasons())
        ->and($artifact['appeal'])->toBe([
            'allowed' => false,
            'implementation_required_in_phase02' => false,
            'status' => 'DEFERRED',
        ])
        ->and($artifact['retention']['quarantine_abandoned_expired_failed_rejected']['cleanup_delay_seconds'])->toBe(86_400)
        ->and($artifact['retention']['canonical_submitted_evidence']['automated_canonical_deletion_in_phase02'])->toBeFalse()
        ->and($artifact['technical_upload_limits']['allowed_mime_types']['classification'])->toBe('APPROVED_AS_POLICY')
        ->and($artifact['technical_upload_limits']['max_active_uploads_per_requirement']['classification'])->toBe('APPROVED_AS_POLICY')
        ->and($artifact['technical_upload_limits']['max_document_bytes']['classification'])->toBe('ENGINEERING_CONTROL')
        ->and($artifact['technical_upload_limits']['upload_expiry_seconds']['classification'])->toBe('ENGINEERING_CONTROL')
        ->and($artifact['technical_upload_limits']['reviewer_document_access_ttl_seconds']['classification'])->toBe('ENGINEERING_CONTROL');
});

it('exposes v1.0.1 doctor and pharmacy requirements through VerificationPolicy', function () {
    $policy = app(VerificationPolicy::class);

    expect($policy->policyVersion())->toBe('v1.0.1-phase02')
        ->and($policy->requiredRequirementCodes('doctor_verification'))->toBe([
            'medical_license',
            'national_id_or_passport',
        ])
        ->and($policy->knownRequirementCodes('doctor_verification'))->toBe([
            'medical_license',
            'national_id_or_passport',
            'syndicate_card',
        ])
        ->and($policy->requiredRequirementCodes('pharmacy_verification'))->toBe([
            'pharmacy_facility_license',
            'commercial_register',
            'responsible_pharmacist_license',
        ])
        ->and($policy->isKnownRequirement('doctor_verification', 'medical_license'))->toBeTrue()
        ->and($policy->isKnownRequirement('doctor_verification', 'national_id_or_passport'))->toBeTrue()
        ->and($policy->isKnownRequirement('doctor_verification', 'syndicate_card'))->toBeTrue()
        ->and($policy->isKnownRequirement('doctor_verification', 'professional_id'))->toBeFalse()
        ->and($policy->isKnownRequirement('pharmacy_verification', 'organization_registration_evidence'))->toBeFalse()
        ->and($policy->isKnownRequirement('doctor_verification', 'pharmacy_facility_license'))->toBeFalse()
        ->and($policy->isKnownRequirement('pharmacy_verification', 'medical_license'))->toBeFalse()
        ->and($policy->isKnownRequirement('doctor_verification', 'fabricated_licence'))->toBeFalse()
        ->and($policy->isRecognizedRequirement('doctor_verification', 'professional_id'))->toBeTrue()
        ->and($policy->isRecognizedRequirement('pharmacy_verification', 'organization_registration_evidence'))->toBeTrue()
        ->and($policy->isRecognizedRequirement('doctor_verification', 'organization_registration_evidence'))->toBeFalse()
        ->and($policy->isOptionalRequirement('doctor_verification', 'syndicate_card'))->toBeTrue()
        ->and($policy->isOptionalRequirement('doctor_verification', 'medical_license'))->toBeFalse();
});

it('accepts only the approved reason catalogue for new decisions', function () {
    $policy = app(VerificationPolicy::class);

    expect($policy->reasonAllowsDecision('approved', 'approved'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('docs_blurry_or_illegible', 'changes_requested'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('missing_required_docs', 'changes_requested'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('identity_mismatch', 'changes_requested'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('identity_mismatch', 'rejected'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('license_expired', 'changes_requested'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('fraudulent_or_altered_doc', 'rejected'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('unauthorized_entity', 'rejected'))->toBeTrue()
        ->and($policy->reasonAllowsDecision('evidence_incomplete', 'rejected'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('documents_illegible', 'changes_requested'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('fraud_internal', 'rejected'))->toBeFalse()
        ->and($policy->reasonAllowsDecision('approved', 'rejected'))->toBeFalse()
        ->and($policy->applicantSafeExplanation('docs_blurry_or_illegible'))->toBe('صورة المستند المقدم غير واضحة أو يتعذر قراءة البيانات منها. يرجى إعادة رفع نسخة جيدة.')
        ->and($policy->applicantSafeExplanation('evidence_incomplete'))->toBeNull()
        ->and($policy->cleanupRejectedAfterSeconds())->toBe(86_400)
        ->and($policy->maxActiveUploadsPerRequirement())->toBe(3)
        ->and($policy->allowedMimeTypes())->toBe(['application/pdf', 'image/jpeg', 'image/png']);
});
