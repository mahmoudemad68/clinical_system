<?php

declare(strict_types=1);

/**
 * ENGINEERING_DEFAULT verification policy. Not a product/security-approved
 * document-requirement or rejection-reason catalogue. Unknown codes deny.
 */
return [
    'name' => 'Verification',
    'case_types' => ['doctor_verification'],
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
    ],
    'reason_codes' => [
        'approved' => ['approved'],
        'evidence_incomplete' => ['rejected', 'changes_requested'],
        'identity_mismatch' => ['rejected'],
        'documents_illegible' => ['changes_requested'],
    ],
    'notes_max_length' => 2000,
];
