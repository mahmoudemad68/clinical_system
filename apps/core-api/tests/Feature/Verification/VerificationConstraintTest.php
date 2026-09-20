<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Contracts\IdentityGenerator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{id: string, applicant_id: string}
 */
function verificationInsertCase(string $status = 'draft', ?string $submittedAt = null, ?string $decidedAt = null): array
{
    $ids = app(IdentityGenerator::class);
    $now = now('UTC')->format('Y-m-d H:i:s.uP');
    $id = $ids->next()->value;
    $applicant = $ids->next()->value;

    DB::table('verification_cases')->insert([
        'id' => $id,
        'applicant_type' => 'doctor',
        'applicant_id' => $applicant,
        'case_type' => 'doctor_verification',
        'status' => $status,
        'submitted_at' => $submittedAt,
        'assigned_reviewer_id' => null,
        'decided_at' => $decidedAt,
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'applicant_id' => $applicant];
}

it('rejects a second open case for the same applicant', function () {
    $first = verificationInsertCase();
    $ids = app(IdentityGenerator::class);
    $now = now('UTC')->format('Y-m-d H:i:s.uP');

    expect(fn () => DB::table('verification_cases')->insert([
        'id' => $ids->next()->value,
        'applicant_type' => 'doctor',
        'applicant_id' => $first['applicant_id'],
        'case_type' => 'doctor_verification',
        'status' => 'draft',
        'submitted_at' => null,
        'assigned_reviewer_id' => null,
        'decided_at' => null,
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('rejects available documents that are not clean', function () {
    $case = verificationInsertCase();
    $ids = app(IdentityGenerator::class);
    $now = now('UTC')->format('Y-m-d H:i:s.uP');

    expect(fn () => DB::table('verification_documents')->insert([
        'id' => $ids->next()->value,
        'case_id' => $case['id'],
        'requirement_code' => 'professional_id',
        'object_id' => $ids->next()->value,
        'sha256' => str_repeat('ab', 32),
        'detected_mime' => 'application/pdf',
        'size_bytes' => 12,
        'scan_status' => 'pending',
        'status' => 'available',
        'uploaded_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('rejects zero-byte documents and malformed digests', function () {
    $case = verificationInsertCase();
    $ids = app(IdentityGenerator::class);
    $now = now('UTC')->format('Y-m-d H:i:s.uP');

    expect(fn () => DB::table('verification_documents')->insert([
        'id' => $ids->next()->value,
        'case_id' => $case['id'],
        'requirement_code' => 'professional_id',
        'object_id' => $ids->next()->value,
        'sha256' => str_repeat('ab', 32),
        'detected_mime' => 'application/pdf',
        'size_bytes' => 0,
        'scan_status' => 'clean',
        'status' => 'quarantined',
        'uploaded_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('verification_documents')->insert([
        'id' => $ids->next()->value,
        'case_id' => $case['id'],
        'requirement_code' => 'professional_id',
        'object_id' => $ids->next()->value,
        'sha256' => 'not-a-sha256',
        'detected_mime' => 'application/pdf',
        'size_bytes' => 12,
        'scan_status' => 'clean',
        'status' => 'quarantined',
        'uploaded_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('keeps decisions append-only and unique per case', function () {
    $now = now('UTC')->format('Y-m-d H:i:s.uP');
    $case = verificationInsertCase('pending_review', $now);
    $ids = app(IdentityGenerator::class);
    $admin = verificationSeedAdmin('constraint');

    DB::table('verification_decisions')->insert([
        'id' => $ids->next()->value,
        'case_id' => $case['id'],
        'decision' => 'approved',
        'reason_code' => 'approved',
        'reviewer_id' => $admin['user_id'],
        'reviewer_assurance_level' => 'aal2_totp',
        'notes_ciphertext' => null,
        'created_at' => $now,
    ]);

    expect(fn () => DB::table('verification_decisions')->insert([
        'id' => $ids->next()->value,
        'case_id' => $case['id'],
        'decision' => 'rejected',
        'reason_code' => 'identity_mismatch',
        'reviewer_id' => $admin['user_id'],
        'reviewer_assurance_level' => 'aal2_totp',
        'notes_ciphertext' => null,
        'created_at' => $now,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('verification_decisions')->update(['reason_code' => 'evidence_incomplete']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('verification_decisions')->delete())
        ->toThrow(QueryException::class);
});

it('freezes submitted documents and keeps content identity immutable', function () {
    $draft = verificationInsertCase();
    $ids = app(IdentityGenerator::class);
    $now = now('UTC')->format('Y-m-d H:i:s.uP');
    $documentId = $ids->next()->value;
    $objectId = $ids->next()->value;

    DB::table('verification_documents')->insert([
        'id' => $documentId,
        'case_id' => $draft['id'],
        'requirement_code' => 'professional_id',
        'object_id' => $objectId,
        'sha256' => str_repeat('ab', 32),
        'detected_mime' => 'application/pdf',
        'size_bytes' => 12,
        'scan_status' => 'pending',
        'status' => 'quarantined',
        'uploaded_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('verification_documents')->where('id', $documentId)->update([
        'scan_status' => 'clean',
        'status' => 'available',
    ]);
    expect((string) DB::table('verification_documents')->where('id', $documentId)->value('status'))->toBe('available');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'sha256' => str_repeat('cd', 32),
    ])))->toThrow(QueryException::class, 'verification_documents content identity is immutable');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'object_id' => $ids->next()->value,
    ])))->toThrow(QueryException::class, 'verification_documents content identity is immutable');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'requirement_code' => 'medical_licence',
    ])))->toThrow(QueryException::class, 'verification_documents content identity is immutable');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'detected_mime' => 'image/jpeg',
    ])))->toThrow(QueryException::class, 'verification_documents content identity is immutable');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'size_bytes' => 4096,
    ])))->toThrow(QueryException::class, 'verification_documents content identity is immutable');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'uploaded_at' => now('UTC')->addMinute()->format('Y-m-d H:i:s.uP'),
    ])))->toThrow(QueryException::class, 'verification_documents content identity is immutable');

    $submittedAt = now('UTC')->format('Y-m-d H:i:s.uP');
    DB::table('verification_cases')->where('id', $draft['id'])->update([
        'status' => 'pending_review',
        'submitted_at' => $submittedAt,
        'updated_at' => $submittedAt,
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->update([
        'scan_status' => 'failed',
        'status' => 'rejected',
    ])))->toThrow(QueryException::class, 'verification_documents is frozen after submission');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->where('id', $documentId)->delete()))
        ->toThrow(QueryException::class, 'verification_documents is frozen after submission');

    expect(fn () => DB::transaction(fn () => DB::table('verification_documents')->insert([
        'id' => $ids->next()->value,
        'case_id' => $draft['id'],
        'requirement_code' => 'professional_id',
        'object_id' => $ids->next()->value,
        'sha256' => str_repeat('ef', 32),
        'detected_mime' => 'application/pdf',
        'size_bytes' => 12,
        'scan_status' => 'clean',
        'status' => 'available',
        'uploaded_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])))->toThrow(QueryException::class, 'verification_documents is frozen after submission');
});

/**
 * @return array{id: string, case_id: string}
 */
function verificationInsertUpload(string $caseId, string $state = 'uploading'): array
{
    $ids = app(IdentityGenerator::class);
    $now = now('UTC');
    $id = $ids->next()->value;
    $created = $now->format('Y-m-d H:i:s.uP');
    $expires = $now->modify('+15 minutes')->format('Y-m-d H:i:s.uP');
    $completed = in_array($state, ['quarantined', 'validating', 'scanning', 'available', 'rejected'], true)
        ? $created
        : null;
    $available = $state === 'available' ? $created : null;
    $reason = $state === 'rejected' ? 'expired' : null;

    DB::table('verification_upload_intents')->insert([
        'id' => $id,
        'case_id' => $caseId,
        'created_by_user_id' => $ids->next()->value,
        'requirement_code' => 'professional_id',
        'object_id' => $ids->next()->value,
        'storage_locator' => 'verification/q/'.bin2hex(random_bytes(16)),
        'canonical_storage_locator' => $state === 'available' ? 'verification/c/'.bin2hex(random_bytes(16)) : null,
        'state' => $state,
        'expected_size_bytes' => 128,
        'declared_media_type' => 'application/pdf',
        'expected_sha256' => null,
        'object_version' => $state === 'available' ? str_repeat('ab', 32) : null,
        'observed_size_bytes' => $state === 'available' ? 128 : null,
        'observed_sha256' => $state === 'available' ? str_repeat('ab', 32) : null,
        'detected_mime' => $state === 'available' ? 'application/pdf' : null,
        'scanner_identity' => null,
        'scanner_version' => null,
        'rejection_reason' => $reason,
        'expires_at' => $expires,
        'completed_at' => $completed,
        'available_at' => $available,
        'cleanup_eligible_at' => $reason === null ? null : $created,
        'processing_attempts' => 0,
        'version' => 1,
        'created_at' => $created,
        'updated_at' => $created,
    ]);

    return ['id' => $id, 'case_id' => $caseId];
}

it('rejects illegal upload-intent states, hashes, and available-from-rejected transitions', function () {
    $case = verificationInsertCase();

    expect(fn () => DB::transaction(fn () => DB::table('verification_upload_intents')->insert([
        'id' => app(IdentityGenerator::class)->next()->value,
        'case_id' => $case['id'],
        'created_by_user_id' => app(IdentityGenerator::class)->next()->value,
        'requirement_code' => 'professional_id',
        'object_id' => app(IdentityGenerator::class)->next()->value,
        'storage_locator' => 'verification/q/'.bin2hex(random_bytes(16)),
        'state' => 'clean',
        'expected_size_bytes' => 128,
        'declared_media_type' => 'application/pdf',
        'expires_at' => now('UTC')->addMinutes(15)->format('Y-m-d H:i:s.uP'),
        'version' => 1,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
        'updated_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
    ])))->toThrow(QueryException::class);

    $uploading = verificationInsertUpload($case['id'], 'uploading');
    expect(fn () => DB::transaction(fn () => DB::table('verification_upload_intents')->where('id', $uploading['id'])->update([
        'expected_sha256' => 'not-a-hash',
    ])))->toThrow(QueryException::class);

    $sealedUploading = verificationInsertUpload($case['id'], 'uploading');
    $firstLocator = 'verification/c/'.bin2hex(random_bytes(16));
    expect(DB::table('verification_upload_intents')->where('id', $sealedUploading['id'])->update([
        'canonical_storage_locator' => $firstLocator,
    ]))->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('verification_upload_intents')->where('id', $sealedUploading['id'])->update([
        'canonical_storage_locator' => 'verification/c/'.bin2hex(random_bytes(16)),
    ])))->toThrow(QueryException::class, 'verification_upload_intents canonical locator is immutable');

    $rejected = verificationInsertUpload($case['id'], 'rejected');
    expect(fn () => DB::transaction(fn () => DB::table('verification_upload_intents')->where('id', $rejected['id'])->update([
        'state' => 'available',
        'observed_sha256' => str_repeat('ab', 32),
        'observed_size_bytes' => 128,
        'detected_mime' => 'application/pdf',
        'available_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
        'completed_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
        'rejection_reason' => null,
    ])))->toThrow(QueryException::class, 'verification_upload_intents rejected state cannot become available');
});
