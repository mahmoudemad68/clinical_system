<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use DateTimeZone;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Doctors\Services\DoctorApplicantService;
use Modules\Doctors\Support\DoctorApplicantProjection;
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
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\DocumentMetadataProjection;
use Modules\Verification\Support\TrustedDocumentEvidence;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationDocumentRecord;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Document metadata registrar and reviewer-safe reader.
 *
 * AVAILABLE/CLEAN rows are written only from TrustedDocumentEvidence issued by
 * the scanner boundary. ActorContext is never scan authority.
 */
final class VerificationDocumentService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresVerificationStore $store,
        private readonly DoctorApplicantService $doctors,
        private readonly VerificationPolicy $policy,
        private readonly Authorize $authorize,
        private readonly RecordPrivilegedFailure $privilegedFailures,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function registerValidatedMetadata(
        TrustedDocumentEvidence $evidence,
        ?Identifier $attributedApplicantId,
    ): DocumentMetadataProjection {
        return $this->transactions->run(function (TransactionContext $tx) use ($evidence, $attributedApplicantId): DocumentMetadataProjection {
            $this->store->lockCase($evidence->caseId);
            $case = $this->store->findCaseById($evidence->caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->status !== VerificationCaseStatus::Draft) {
                throw new StateConflict;
            }

            if (! $this->policy->isKnownRequirement($case->caseType->value, $evidence->requirementCode)) {
                throw new InvalidValueObject('Requirement code is not allowed.');
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $id = $this->ids->next();

            try {
                $this->store->insertDocument([
                    'id' => $id->value,
                    'case_id' => $case->id->value,
                    'requirement_code' => $evidence->requirementCode,
                    'object_id' => $evidence->objectId->value,
                    'sha256' => $evidence->sha256,
                    'detected_mime' => $evidence->detectedMime,
                    'size_bytes' => $evidence->sizeBytes,
                    'scan_status' => $evidence->scanStatus->value,
                    'status' => $evidence->status->value,
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
                    'reason_code' => 'trusted_scanner_pipeline',
                    'requirement_code' => $evidence->requirementCode,
                    'scan_status' => $evidence->scanStatus->value,
                    'status' => $evidence->status->value,
                    'attributed_applicant_user_id' => $attributedApplicantId?->value,
                ],
                null,
                'system',
            );

            $row = $this->store->findDocumentById($id);
            assert($row instanceof VerificationDocumentRecord);

            return $this->project($row);
        });
    }

    public function applyTrustedScanOutcome(TrustedDocumentEvidence $evidence): DocumentMetadataProjection
    {
        return $this->transactions->run(function (TransactionContext $tx) use ($evidence): DocumentMetadataProjection {
            $this->store->lockCase($evidence->caseId);
            $case = $this->store->findCaseById($evidence->caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->status !== VerificationCaseStatus::Draft) {
                throw new StateConflict;
            }

            $row = $this->store->findDocumentByObjectId($evidence->objectId);
            if (! $row instanceof VerificationDocumentRecord) {
                throw new AuthorizationDenied;
            }
            if (! $row->caseId->equals($case->id) || $row->sha256 !== $evidence->sha256) {
                throw new StateConflict;
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $affected = $this->store->updateDocumentLifecycle(
                $row->id,
                $case->id,
                $evidence->objectId->value,
                $evidence->sha256,
                $evidence->scanStatus->value,
                $evidence->status->value,
                $stamp,
            );
            if ($affected !== 1) {
                throw new StateConflict;
            }

            $this->audit->append(
                $tx,
                'verification.document_scan_applied',
                'verification_document',
                $row->id,
                [
                    'reason_code' => 'trusted_scanner_pipeline',
                    'scan_status' => $evidence->scanStatus->value,
                    'status' => $evidence->status->value,
                ],
                null,
                'system',
            );

            $fresh = $this->store->findDocumentById($row->id);
            assert($fresh instanceof VerificationDocumentRecord);

            return $this->project($fresh);
        });
    }

    public function reviewSafeMetadata(ActorContext $reviewer, Identifier $documentId): DocumentMetadataProjection
    {
        $this->assertPrivilegedReviewer($reviewer, $documentId);

        return $this->transactions->run(function () use ($reviewer, $documentId): DocumentMetadataProjection {
            $row = $this->store->findDocumentById($documentId);
            if (! $row instanceof VerificationDocumentRecord) {
                throw new AuthorizationDenied;
            }

            $this->store->lockCase($row->caseId);
            $case = $this->store->findCaseById($row->caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }

            $this->assertNotSelfReview($reviewer, $case);
            $this->assertAssignedReviewer($reviewer, $case);

            if (! $row->isReviewable()) {
                throw new AuthorizationDenied;
            }

            return $this->project($row);
        });
    }

    private function assertPrivilegedReviewer(ActorContext $reviewer, Identifier $objectId): void
    {
        $decision = $this->authorize->decide($reviewer, Capabilities::VERIFICATION_REVIEW);
        if ($decision->allowed) {
            return;
        }

        $this->privilegedFailures->authorizationDenied(
            $reviewer->userId,
            $reviewer->accountType->value,
            $reviewer->assuranceLevel->value,
            Capabilities::VERIFICATION_REVIEW,
            $decision->reasonCode,
            $objectId,
            'verification_document',
        );
        throw new AuthorizationDenied;
    }

    private function assertNotSelfReview(ActorContext $reviewer, VerificationCaseRecord $case): void
    {
        $doctor = $this->doctors->findById($case->applicantId, false);
        if ($doctor instanceof DoctorApplicantProjection && $doctor->userId->equals($reviewer->userId)) {
            throw new AuthorizationDenied;
        }
    }

    private function assertAssignedReviewer(ActorContext $reviewer, VerificationCaseRecord $case): void
    {
        if (! $case->assignedReviewerId instanceof Identifier || ! $case->assignedReviewerId->equals($reviewer->userId)) {
            throw new AuthorizationDenied;
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
            $row->uploadedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        );
    }
}
