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
use Modules\Pharmacies\Services\PharmacyApplicantService;
use Modules\Pharmacies\Support\PharmacyApplicantProjection;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Enums\ApplicantType;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationUploadState;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\DocumentMetadataProjection;
use Modules\Verification\Support\ReviewerDocumentAccessGrant;
use Modules\Verification\Support\ReviewerDocumentStream;
use Modules\Verification\Support\TrustedDocumentEvidence;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationDocumentRecord;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationUploadIntentRecord;

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
        private readonly PharmacyApplicantService $pharmacies,
        private readonly VerificationPolicy $policy,
        private readonly Authorize $authorize,
        private readonly RecordPrivilegedFailure $privilegedFailures,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly StoreObject $objects,
        private readonly ReviewerDocumentUrlSigner $urls,
        private readonly PlatformMetrics $metrics,
    ) {}

    public function registerValidatedMetadata(TrustedDocumentEvidence $evidence): DocumentMetadataProjection
    {
        return $this->transactions->run(
            fn (TransactionContext $tx): DocumentMetadataProjection => $this->registerValidatedMetadataWithin($tx, $evidence),
        );
    }

    public function registerValidatedMetadataWithin(TransactionContext $tx, TrustedDocumentEvidence $evidence): DocumentMetadataProjection
    {
        $this->store->lockCase($evidence->caseId);
        $case = $this->store->findCaseById($evidence->caseId, false);
        if (! $case instanceof VerificationCaseRecord) {
            throw new AuthorizationDenied;
        }
        if ($case->status !== VerificationCaseStatus::Draft) {
            throw new StateConflict;
        }

        if (! $this->policy->isKnownRequirement($case->caseType->value, $evidence->requirementCode)) {
            throw new InvalidValueObject('Requirement code is not allowed.');
        }

        $applicantUserId = $this->authoritativeApplicantUserId($case, $evidence->uploadIntentId);

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
                'upload_intent_id' => $evidence->uploadIntentId?->value,
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
                'attributed_applicant_user_id' => $applicantUserId->value,
            ],
            null,
            'system',
        );

        $row = $this->store->findDocumentById($id);
        assert($row instanceof VerificationDocumentRecord);

        return $this->project($row);
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

    public function issueReviewerReadGrant(
        ActorContext $reviewer,
        Identifier $caseId,
        Identifier $documentId,
    ): ReviewerDocumentAccessGrant {
        $this->assertPrivilegedReviewer($reviewer, $documentId);

        $caseType = null;
        $grant = $this->transactions->run(function (TransactionContext $tx) use ($reviewer, $caseId, $documentId, &$caseType): ReviewerDocumentAccessGrant {
            $this->store->lockCase($caseId);
            $case = $this->store->findCaseById($caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->status !== VerificationCaseStatus::PendingReview) {
                throw new AuthorizationDenied;
            }

            $this->assertNotSelfReview($reviewer, $case);
            $this->assertAssignedReviewer($reviewer, $case);

            $target = $this->canonicalReviewTarget($case, $documentId);

            $ttl = $this->policy->reviewerDocumentAccessTtlSeconds();
            if ($ttl < 1 || $ttl > 300) {
                throw new InvalidValueObject('Reviewer document access TTL is not allowed.');
            }

            $expiresAt = $this->clock->now()->modify('+'.$ttl.' seconds');
            $url = $this->urls->sign($caseId, $documentId, $expiresAt);

            $this->audit->append(
                $tx,
                'verification.document_access_granted',
                'verification_case',
                $caseId,
                [
                    'reason_code' => 'verification_review_access',
                    'document_id' => $target['document']->id->value,
                    'requirement_code' => $target['document']->requirementCode,
                    'assurance_level' => $reviewer->assuranceLevel->value,
                ],
                $reviewer->userId,
                'user',
            );

            $caseType = $case->caseType->value;

            return new ReviewerDocumentAccessGrant(
                $target['document']->id->value,
                $url,
                $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                $target['document']->detectedMime,
                $target['document']->sizeBytes,
            );
        });

        try {
            $this->metrics->increment('clinic_verification_review_results_total', [
                'result' => 'granted',
                'case_type' => is_string($caseType) ? $caseType : 'unknown',
                'reason_code' => 'verification_review_access',
            ]);
        } catch (\Throwable) {
        }

        return $grant;
    }

    public function openReviewerDownload(
        string $caseId,
        string $documentId,
        string $expires,
        string $signature,
    ): ReviewerDocumentStream {
        if (! $this->urls->isValid($caseId, $documentId, $expires, $signature, $this->clock->now())) {
            throw new AuthorizationDenied;
        }

        try {
            $caseIdentifier = Identifier::fromString($caseId);
            $documentIdentifier = Identifier::fromString($documentId);
        } catch (InvalidValueObject) {
            throw new AuthorizationDenied;
        }

        $prepared = $this->resolveCanonicalDownloadTarget($caseIdentifier, $documentIdentifier);
        $expectedSize = $prepared['document']->sizeBytes;
        $expectedSha = $prepared['document']->sha256;
        $ref = $prepared['ref'];
        $recordedVersion = $prepared['object_version'];
        $caseType = $prepared['case_type'];

        try {
            $observed = $this->objects->observe($ref, $this->policy->maxDocumentBytes());
        } catch (\Throwable) {
            $this->countDownloadResult('provider_read_failure', $caseType);
            throw new AuthorizationDenied;
        }

        if (! $this->canonicalObservationMatches($observed, $expectedSize, $expectedSha, $recordedVersion)) {
            $this->countDownloadResult('integrity_mismatch', $caseType);
            throw new AuthorizationDenied;
        }

        $confirmed = $this->resolveCanonicalDownloadTarget($caseIdentifier, $documentIdentifier);
        if ($confirmed['ref']->key() !== $ref->key()
            || $confirmed['document']->sizeBytes !== $expectedSize
            || ! hash_equals($confirmed['document']->sha256, $expectedSha)) {
            $this->countDownloadResult('integrity_mismatch', $caseType);
            throw new AuthorizationDenied;
        }

        try {
            $stream = $this->objects->openStream($ref);
        } catch (\Throwable) {
            $this->countDownloadResult('provider_read_failure', $caseType);
            throw new AuthorizationDenied;
        }
        if (! is_resource($stream)) {
            $this->countDownloadResult('provider_read_failure', $caseType);
            throw new AuthorizationDenied;
        }

        return new ReviewerDocumentStream(
            $stream,
            $confirmed['document']->detectedMime,
            $this->policy->reviewerDownloadFilename($confirmed['document']->detectedMime),
            $expectedSize,
            $this->policy->reviewerDownloadChunkBytes(),
        );
    }

    /**
     * @return array{document: VerificationDocumentRecord, ref: StoredObjectRef, object_version: ?string, case_type: string}
     */
    private function resolveCanonicalDownloadTarget(Identifier $caseId, Identifier $documentId): array
    {
        return $this->transactions->run(function () use ($caseId, $documentId): array {
            $this->store->lockCase($caseId);
            $case = $this->store->findCaseById($caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->status !== VerificationCaseStatus::PendingReview) {
                throw new AuthorizationDenied;
            }

            return $this->canonicalReviewTarget($case, $documentId);
        });
    }

    /**
     * @return array{document: VerificationDocumentRecord, ref: StoredObjectRef, object_version: ?string, case_type: string}
     */
    private function canonicalReviewTarget(VerificationCaseRecord $case, Identifier $documentId): array
    {
        $row = $this->store->findDocumentById($documentId);
        if (! $row instanceof VerificationDocumentRecord || ! $row->caseId->equals($case->id)) {
            throw new AuthorizationDenied;
        }
        if (! $row->isReviewable() || ! $row->uploadIntentId instanceof Identifier) {
            throw new AuthorizationDenied;
        }
        if ($row->sizeBytes < 1 || $row->sizeBytes > $this->policy->maxDocumentBytes()) {
            throw new AuthorizationDenied;
        }
        if (! $this->policy->isAllowedMime($row->detectedMime)) {
            throw new AuthorizationDenied;
        }

        $upload = $this->store->findUploadById($row->uploadIntentId, true);
        if (! $upload instanceof VerificationUploadIntentRecord) {
            throw new AuthorizationDenied;
        }
        if (! $upload->caseId->equals($case->id) || $upload->state !== VerificationUploadState::Available) {
            throw new AuthorizationDenied;
        }

        $canonical = $upload->canonicalRef();
        if (! $canonical instanceof StoredObjectRef) {
            throw new AuthorizationDenied;
        }

        $trusted = $upload->trustedRef();
        if ($trusted->key() !== $canonical->key()) {
            throw new AuthorizationDenied;
        }

        return [
            'document' => $row,
            'ref' => $trusted,
            'object_version' => $upload->objectVersion,
            'case_type' => $case->caseType->value,
        ];
    }

    private function canonicalObservationMatches(
        ObservedObject $observed,
        int $expectedSize,
        string $expectedSha,
        ?string $recordedVersion,
    ): bool {
        if (! $observed->exists || $observed->sizeBytes !== $expectedSize) {
            return false;
        }
        if ($expectedSha === '' || ! hash_equals($expectedSha, $observed->sha256)) {
            return false;
        }
        if ($recordedVersion !== null && $recordedVersion !== '' && $observed->objectVersion !== '') {
            if (! hash_equals($recordedVersion, $observed->objectVersion)) {
                return false;
            }
        }

        return true;
    }

    private function countDownloadResult(string $result, string $caseType): void
    {
        try {
            $this->metrics->increment('clinic_verification_review_results_total', [
                'result' => $result,
                'case_type' => $caseType,
                'reason_code' => 'verification_review_download',
            ]);
        } catch (\Throwable) {
        }
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

    private function authoritativeApplicantUserId(VerificationCaseRecord $case, ?Identifier $uploadIntentId): Identifier
    {
        if ($uploadIntentId instanceof Identifier) {
            $upload = $this->store->findUploadById($uploadIntentId, false);
            if ($upload instanceof VerificationUploadIntentRecord) {
                return $upload->createdByUserId;
            }
        }

        return match ($case->applicantType) {
            ApplicantType::Doctor => $this->doctorApplicantUserId($case),
            ApplicantType::Pharmacy => $this->pharmacyApplicantUserId($case),
        };
    }

    private function doctorApplicantUserId(VerificationCaseRecord $case): Identifier
    {
        $doctor = $this->doctors->findById($case->applicantId, true);
        if (! $doctor instanceof DoctorApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $doctor->userId;
    }

    private function pharmacyApplicantUserId(VerificationCaseRecord $case): Identifier
    {
        $pharmacy = $this->pharmacies->findById($case->applicantId, true);
        if (! $pharmacy instanceof PharmacyApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $pharmacy->userId;
    }

    private function assertNotSelfReview(ActorContext $reviewer, VerificationCaseRecord $case): void
    {
        $ownerUserId = match ($case->applicantType) {
            ApplicantType::Doctor => $this->doctors->findById($case->applicantId, false)?->userId,
            ApplicantType::Pharmacy => $this->pharmacies->findById($case->applicantId, false)?->userId,
        };

        if ($ownerUserId instanceof Identifier && $ownerUserId->equals($reviewer->userId)) {
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
