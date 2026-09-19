<?php

declare(strict_types=1);

namespace Modules\Verification\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\ApplicantType;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;
use Modules\Verification\Enums\VerificationDecision;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationDecisionRecord;
use Modules\Verification\Support\VerificationDocumentRecord;
use stdClass;

final class PostgresVerificationStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function lockCase(Identifier $caseId): void
    {
        $this->connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['verification-case:'.$caseId->value]);
    }

    public function lockApplicant(ApplicantType $type, Identifier $applicantId): void
    {
        $this->connection->select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['verification-applicant:'.$type->value.':'.$applicantId->value],
        );
    }

    public function findCaseById(Identifier $id, bool $lock): ?VerificationCaseRecord
    {
        $query = $this->connection->table('verification_cases')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapCase($row) : null;
    }

    public function findOpenCase(ApplicantType $type, Identifier $applicantId, VerificationCaseType $caseType, bool $lock): ?VerificationCaseRecord
    {
        $query = $this->connection->table('verification_cases')
            ->where('applicant_type', $type->value)
            ->where('applicant_id', $applicantId->value)
            ->where('case_type', $caseType->value)
            ->whereIn('status', [
                VerificationCaseStatus::Draft->value,
                VerificationCaseStatus::PendingReview->value,
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapCase($row) : null;
    }

    public function findLatestCase(ApplicantType $type, Identifier $applicantId, VerificationCaseType $caseType): ?VerificationCaseRecord
    {
        $row = $this->connection->table('verification_cases')
            ->where('applicant_type', $type->value)
            ->where('applicant_id', $applicantId->value)
            ->where('case_type', $caseType->value)
            ->orderByDesc('created_at')
            ->first();

        return $row instanceof stdClass ? $this->mapCase($row) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertCase(array $attributes): void
    {
        try {
            $this->connection->table('verification_cases')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCase(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('verification_cases')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertDocument(array $attributes): void
    {
        try {
            $this->connection->table('verification_documents')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @return list<VerificationDocumentRecord>
     */
    public function documentsForCase(Identifier $caseId): array
    {
        $rows = $this->connection->table('verification_documents')
            ->where('case_id', $caseId->value)
            ->orderBy('uploaded_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapDocument($row);
            }
        }

        return $out;
    }

    public function findDocumentById(Identifier $id): ?VerificationDocumentRecord
    {
        $row = $this->connection->table('verification_documents')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->mapDocument($row) : null;
    }

    public function findDocumentByObjectId(Identifier $objectId): ?VerificationDocumentRecord
    {
        $row = $this->connection->table('verification_documents')->where('object_id', $objectId->value)->first();

        return $row instanceof stdClass ? $this->mapDocument($row) : null;
    }

    /**
     * Lifecycle-only mutation. Content identity columns are not in the SET list;
     * PostgreSQL still rejects the write after the parent case leaves draft.
     */
    public function updateDocumentLifecycle(
        Identifier $id,
        Identifier $caseId,
        string $objectId,
        string $sha256,
        string $scanStatus,
        string $status,
        string $updatedAt,
    ): int {
        return $this->connection->table('verification_documents')
            ->where('id', $id->value)
            ->where('case_id', $caseId->value)
            ->where('object_id', $objectId)
            ->where('sha256', $sha256)
            ->update([
                'scan_status' => $scanStatus,
                'status' => $status,
                'updated_at' => $updatedAt,
            ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertDecision(array $attributes): void
    {
        try {
            $this->connection->table('verification_decisions')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    public function findDecisionByCaseId(Identifier $caseId): ?VerificationDecisionRecord
    {
        $row = $this->connection->table('verification_decisions')->where('case_id', $caseId->value)->first();

        return $row instanceof stdClass ? $this->mapDecision($row) : null;
    }

    /**
     * @return list<VerificationDecisionRecord>
     */
    public function decisionsForCase(Identifier $caseId): array
    {
        $rows = $this->connection->table('verification_decisions')
            ->where('case_id', $caseId->value)
            ->orderBy('created_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapDecision($row);
            }
        }

        return $out;
    }

    private function mapCase(stdClass $row): VerificationCaseRecord
    {
        $reviewer = isset($row->assigned_reviewer_id) && is_string($row->assigned_reviewer_id) && $row->assigned_reviewer_id !== ''
            ? Identifier::fromTrusted($row->assigned_reviewer_id)
            : null;

        return new VerificationCaseRecord(
            Identifier::fromTrusted((string) $row->id),
            ApplicantType::from((string) $row->applicant_type),
            Identifier::fromTrusted((string) $row->applicant_id),
            VerificationCaseType::from((string) $row->case_type),
            VerificationCaseStatus::from((string) $row->status),
            self::timestamp($row->submitted_at ?? null),
            $reviewer,
            self::timestamp($row->decided_at ?? null),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapDocument(stdClass $row): VerificationDocumentRecord
    {
        return new VerificationDocumentRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->case_id),
            (string) $row->requirement_code,
            Identifier::fromTrusted((string) $row->object_id),
            (string) $row->sha256,
            (string) $row->detected_mime,
            (int) $row->size_bytes,
            VerificationDocumentScanStatus::from((string) $row->scan_status),
            VerificationDocumentStatus::from((string) $row->status),
            new DateTimeImmutable((string) $row->uploaded_at),
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapDecision(stdClass $row): VerificationDecisionRecord
    {
        $notes = BinaryColumn::asString($row->notes_ciphertext ?? '');

        return new VerificationDecisionRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->case_id),
            VerificationDecision::from((string) $row->decision),
            (string) $row->reason_code,
            Identifier::fromTrusted((string) $row->reviewer_id),
            (string) $row->reviewer_assurance_level,
            $notes === '' ? null : $notes,
            new DateTimeImmutable((string) $row->created_at),
        );
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
