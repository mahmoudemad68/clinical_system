<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use Illuminate\Validation\ValidationException;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Doctors\Services\DoctorApplicantService;
use Modules\Doctors\Support\DoctorApplicantProjection;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\DocumentMetadataProjection;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationDocumentRecord;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Trusted in-process registrar for already-validated document metadata.
 * There is no HTTP surface that can mark a document AVAILABLE.
 */
final class VerificationDocumentService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresVerificationStore $store,
        private readonly DoctorApplicantService $doctors,
        private readonly VerificationPolicy $policy,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array{
     *     requirement_code: string,
     *     object_id: string,
     *     sha256: string,
     *     detected_mime: string,
     *     size_bytes: int,
     *     scan_status: string,
     *     status: string
     * }  $input
     */
    public function registerValidatedMetadata(ActorContext $actor, Identifier $caseId, array $input): DocumentMetadataProjection
    {
        $this->assertTrustedMetadata($input);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $caseId, $input): DocumentMetadataProjection {
            $this->store->lockCase($caseId);
            $case = $this->store->findCaseById($caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->status !== VerificationCaseStatus::Draft) {
                throw new StateConflict;
            }

            $doctor = $this->doctors->findById($case->applicantId, true);
            if (! $doctor instanceof DoctorApplicantProjection) {
                throw new AuthorizationDenied;
            }
            if (! $doctor->userId->equals($actor->userId) && $actor->accountType !== AccountType::Admin) {
                throw new AuthorizationDenied;
            }

            if (! $this->policy->isKnownRequirement($case->caseType->value, $input['requirement_code'])) {
                throw new InvalidValueObject('Requirement code is not allowed.');
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $id = $this->ids->next();
            $objectId = Identifier::fromString($input['object_id']);

            try {
                $this->store->insertDocument([
                    'id' => $id->value,
                    'case_id' => $case->id->value,
                    'requirement_code' => $input['requirement_code'],
                    'object_id' => $objectId->value,
                    'sha256' => $input['sha256'],
                    'detected_mime' => $input['detected_mime'],
                    'size_bytes' => $input['size_bytes'],
                    'scan_status' => $input['scan_status'],
                    'status' => $input['status'],
                    'uploaded_at' => $stamp,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            } catch (DuplicateIdentity) {
                throw new StateConflict;
            }

            $this->audit->append(
                $tx,
                'verification.document_registered',
                'verification_document',
                $id,
                [
                    'reason_code' => 'trusted_metadata',
                    'requirement_code' => $input['requirement_code'],
                    'scan_status' => $input['scan_status'],
                    'status' => $input['status'],
                ],
                $actor->userId,
                'user',
            );

            $row = $this->store->findDocumentById($id);
            assert($row instanceof VerificationDocumentRecord);

            return $this->project($row);
        });
    }

    public function reviewSafeMetadata(Identifier $documentId): DocumentMetadataProjection
    {
        $row = $this->store->findDocumentById($documentId);
        if (! $row instanceof VerificationDocumentRecord) {
            throw new AuthorizationDenied;
        }

        return $this->project($row);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertTrustedMetadata(array $input): void
    {
        $sha = (string) ($input['sha256'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new InvalidValueObject('Document digest is not a SHA-256 hex digest.');
        }

        $mime = (string) ($input['detected_mime'] ?? '');
        if (! $this->policy->isAllowedMime($mime)) {
            throw new InvalidValueObject('Detected MIME type is not allowed.');
        }

        $size = (int) ($input['size_bytes'] ?? 0);
        if ($size < 1 || $size > $this->policy->maxDocumentBytes()) {
            throw new InvalidValueObject('Document size is outside the allowed bound.');
        }

        try {
            $scan = VerificationDocumentScanStatus::from((string) ($input['scan_status'] ?? ''));
            $status = VerificationDocumentStatus::from((string) ($input['status'] ?? ''));
        } catch (\ValueError) {
            throw new InvalidValueObject('Document status is not allowed.');
        }

        if ($status === VerificationDocumentStatus::Available && $scan !== VerificationDocumentScanStatus::Clean) {
            throw ValidationException::withMessages([
                'status' => 'A document cannot become available without a clean scan.',
            ]);
        }
    }

    private function project(VerificationDocumentRecord $row): DocumentMetadataProjection
    {
        return new DocumentMetadataProjection(
            $row->id->value,
            $row->caseId->value,
            $row->requirementCode,
            $row->sha256,
            $row->detectedMime,
            $row->sizeBytes,
            $row->scanStatus->value,
            $row->status->value,
            $row->uploadedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        );
    }
}
