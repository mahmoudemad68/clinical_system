<?php

declare(strict_types=1);

/**
 * ENGINEERING_DEFAULT verification policy. Not a product/security-approved
 * document-requirement or rejection-reason catalogue. Unknown codes deny.
 */
return [
    'name' => 'Verification',
    'case_types' => ['doctor_verification', 'pharmacy_verification'],
    'max_document_bytes' => 20_971_520,
    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ],
    'document_requirements' => [
        'professional_id' => [
            'case_type' => 'doctor_verification',
            'required' => true,
        ],
        // ENGINEERING_DEFAULT synthetic requirement. Not government
        // verification, pharmacy licensing sufficiency, commercial-registry
        // validity, or legal approval.
        'organization_registration_evidence' => [
            'case_type' => 'pharmacy_verification',
            'required' => true,
        ],
    ],
    'reason_codes' => [
        'approved' => ['approved'],
        'evidence_incomplete' => ['rejected', 'changes_requested'],
        'identity_mismatch' => ['rejected'],
        'documents_illegible' => ['changes_requested'],
    ],
    'notes_max_length' => 2000,
    'upload_expiry_seconds' => 900,
    'max_active_uploads_per_requirement' => 3,
    'cleanup_rejected_after_seconds' => 86_400,
    'max_processing_attempts' => 8,
    'reviewer_document_access_ttl_seconds' => 120,
    'queue_default_limit' => 25,
    'queue_max_limit' => 100,
];
