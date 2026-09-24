<?php

declare(strict_types=1);

use Modules\Verification\Support\ApprovedVerificationPolicyV1;

/**
 * Phase 02 Verification Policy v1.0.1-phase02 operational representation.
 *
 * Document requirements, decision/reason pairs, MIME types, and max active
 * uploads are APPROVED_AS_POLICY. Maximum bytes, upload-grant TTL, and
 * reviewer document URL TTL remain ENGINEERING_CONTROL values.
 *
 * Unknown codes deny. This is not government, syndicate, licensing-authority,
 * or registry-provider approval.
 */
return [
    'name' => 'Verification',
    'policy_version' => ApprovedVerificationPolicyV1::VERSION,
    'case_types' => ['doctor_verification', 'pharmacy_verification'],
    'max_document_bytes' => 20_971_520,
    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ],
    'document_requirements' => ApprovedVerificationPolicyV1::documentRequirementsConfig(),
    'reason_codes' => ApprovedVerificationPolicyV1::reasonCodesConfig(),
    'notes_max_length' => 2000,
    'upload_expiry_seconds' => 900,
    'max_active_uploads_per_requirement' => 3,
    'cleanup_rejected_after_seconds' => 86_400,
    'max_processing_attempts' => 8,
    'reviewer_document_access_ttl_seconds' => 120,
    'queue_default_limit' => 25,
    'queue_max_limit' => 100,
];
