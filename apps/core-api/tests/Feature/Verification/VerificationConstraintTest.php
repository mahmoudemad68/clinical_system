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
