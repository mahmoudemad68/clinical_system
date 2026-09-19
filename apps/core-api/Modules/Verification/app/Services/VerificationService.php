<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Services\DoctorApplicantService;
use Modules\Doctors\Support\DoctorApplicantProjection;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\ApplicantType;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;
use Modules\Verification\Enums\VerificationDecision;
use Modules\Verification\Events\DoctorVerificationDecided;
use Modules\Verification\Events\DoctorVerificationSubmitted;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\ApplicantCaseProjection;
use Modules\Verification\Support\ReviewerCaseProjection;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationDecisionRecord;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationSubmissionOutcome;

/**
 * Doctor verification case lifecycle. Coordinates Verification writes with
 * Doctors applicant transitions inside one transaction.
 */
final class VerificationService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresVerificationStore $store,
        private readonly DoctorApplicantService $doctors,
        private readonly VerificationPolicy $policy,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly RecordPrivilegedFailure $privilegedFailures,
        private readonly FieldEncryptor $encryptor,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    public function openDoctorCase(ActorContext $actor): ApplicantCaseProjection
    {
        $this->assertDoctorActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor): ApplicantCaseProjection {
            $this->assertKnownDoctorCaseType();
            $doctor = $this->requireDoctor($actor->userId, true);
            $this->store->lockApplicant(ApplicantType::Doctor, $doctor->doctorId);

            if (! $doctor->verificationStatus->allowsNewVerificationCase()) {
                $open = $this->store->findOpenCase(ApplicantType::Doctor, $doctor->doctorId, VerificationCaseType::DoctorVerification, true);
                if ($open instanceof VerificationCaseRecord) {
                    return $this->applicantProjection($doctor, $open);
                }
                throw new StateConflict;
            }

            $existing = $this->store->findOpenCase(ApplicantType::Doctor, $doctor->doctorId, VerificationCaseType::DoctorVerification, true);
            if ($existing instanceof VerificationCaseRecord) {
                return $this->applicantProjection($doctor, $existing);
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $caseId = $this->ids->next();

            try {
                $this->store->insertCase([
                    'id' => $caseId->value,
                    'applicant_type' => ApplicantType::Doctor->value,
                    'applicant_id' => $doctor->doctorId->value,
                    'case_type' => VerificationCaseType::DoctorVerification->value,
                    'status' => VerificationCaseStatus::Draft->value,
                    'submitted_at' => null,
                    'assigned_reviewer_id' => null,
                    'decided_at' => null,
                    'version' => 1,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            } catch (DuplicateIdentity) {
                $retry = $this->store->findOpenCase(ApplicantType::Doctor, $doctor->doctorId, VerificationCaseType::DoctorVerification, true);
                if ($retry instanceof VerificationCaseRecord) {
                    return $this->applicantProjection($doctor, $retry);
                }
                throw new StateConflict;
            }

            $this->audit->append(
                $tx,
                'verification.case_created',
                'verification_case',
                $caseId,
                [
                    'reason_code' => 'doctor_verification',
                    'case_type' => VerificationCaseType::DoctorVerification->value,
                ],
                $actor->userId,
                'user',
            );

            $case = $this->store->findCaseById($caseId, false);
            assert($case instanceof VerificationCaseRecord);

            return $this->applicantProjection($doctor, $case);
        });
    }

    /**
     * @param  array{case_version: int, profile_version: int}  $input
     */
    public function submitDoctorCase(ActorContext $actor, array $input): VerificationSubmissionOutcome
    {
        $this->assertDoctorActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $expectedCaseVersion = (int) $input['case_version'];
        $expectedProfileVersion = (int) $input['profile_version'];

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $expectedCaseVersion, $expectedProfileVersion): VerificationSubmissionOutcome {
            $this->assertKnownDoctorCaseType();
            $doctor = $this->requireDoctor($actor->userId, true);
            $this->store->lockApplicant(ApplicantType::Doctor, $doctor->doctorId);
            $case = $this->store->findOpenCase(ApplicantType::Doctor, $doctor->doctorId, VerificationCaseType::DoctorVerification, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->version !== $expectedCaseVersion) {
                throw new VersionConflict;
            }
            if ($doctor->version !== $expectedProfileVersion) {
                throw new VersionConflict;
            }
            if (! $case->status->canTransitionTo(VerificationCaseStatus::PendingReview)) {
                throw new StateConflict;
            }

            $this->assertRequiredDocumentsAvailable($case);

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $affected = $this->store->updateCase($case->id, $expectedCaseVersion, [
                'status' => VerificationCaseStatus::PendingReview->value,
                'submitted_at' => $stamp,
                'version' => $expectedCaseVersion + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $doctor = $this->doctors->transition(
                $doctor->doctorId,
                DoctorVerificationStatus::PendingReview,
                $expectedProfileVersion,
                $now,
            );

            $this->audit->append(
                $tx,
                'verification.case_submitted',
                'verification_case',
                $case->id,
                [
                    'reason_code' => 'submitted',
                    'case_type' => $case->caseType->value,
                ],
                $actor->userId,
                'user',
            );
            $this->audit->append(
                $tx,
                'doctor.verification_status_changed',
                'doctor_profile',
                $doctor->doctorId,
                [
                    'reason_code' => 'submitted',
                    'status' => DoctorVerificationStatus::PendingReview->value,
                ],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new DoctorVerificationSubmitted($doctor->doctorId, $case->id, $now));

            $fresh = $this->store->findCaseById($case->id, false);
            assert($fresh instanceof VerificationCaseRecord);

            return new VerificationSubmissionOutcome(
                $doctor->doctorId->value,
                $fresh->id->value,
                $fresh->status->value,
                $fresh->version,
                $doctor->version,
                $doctor->verificationStatus->value,
            );
        });
    }

    public function applicantStatus(ActorContext $actor): ApplicantCaseProjection
    {
        $this->assertDoctorActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_STATUS_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $doctor = $this->requireDoctor($actor->userId, false);
        $case = $this->store->findLatestCase(ApplicantType::Doctor, $doctor->doctorId, VerificationCaseType::DoctorVerification);

        return $this->applicantProjection($doctor, $case);
    }

    public function claimCase(ActorContext $reviewer, Identifier $caseId, int $expectedVersion): ReviewerCaseProjection
    {
        $this->assertPrivilegedReviewer($reviewer, $caseId);

        return $this->transactions->run(function (TransactionContext $tx) use ($reviewer, $caseId, $expectedVersion): ReviewerCaseProjection {
            $this->store->lockCase($caseId);
            $case = $this->requirePendingCase($caseId);
            $this->assertNotSelfReview($reviewer, $case);
            if ($case->version !== $expectedVersion) {
                throw new VersionConflict;
            }
            if ($case->assignedReviewerId instanceof Identifier && ! $case->assignedReviewerId->equals($reviewer->userId)) {
                throw new StateConflict;
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $affected = $this->store->updateCase($case->id, $expectedVersion, [
                'assigned_reviewer_id' => $reviewer->userId->value,
                'version' => $expectedVersion + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $this->audit->append(
                $tx,
                'verification.reviewer_claimed',
                'verification_case',
                $case->id,
                ['reason_code' => 'claimed'],
                $reviewer->userId,
                'user',
            );

            $fresh = $this->store->findCaseById($case->id, false);
            assert($fresh instanceof VerificationCaseRecord);

            return $this->reviewerProjection($fresh);
        });
    }

    public function recordDecision(
        ActorContext $reviewer,
        Identifier $caseId,
        string $decisionValue,
        string $reasonCode,
        int $expectedVersion,
        ?string $notes = null,
    ): ReviewerCaseProjection {
        $this->assertPrivilegedReviewer($reviewer, $caseId);

        $this->assertKnownDoctorCaseType();

        try {
            $decision = VerificationDecision::from($decisionValue);
        } catch (\ValueError) {
            throw new InvalidValueObject('Decision type is not allowed.');
        }

        if (! $this->policy->reasonAllowsDecision($reasonCode, $decision->value)) {
            throw new InvalidValueObject('Reason code is not allowed for that decision.');
        }

        if ($notes !== null && $notes !== '' && mb_strlen($notes) > $this->policy->notesMaxLength()) {
            throw new InvalidValueObject('Reviewer notes exceed the allowed length.');
        }

        return $this->transactions->run(function (TransactionContext $tx) use (
            $reviewer,
            $caseId,
            $decision,
            $reasonCode,
            $expectedVersion,
            $notes,
        ): ReviewerCaseProjection {
            $this->store->lockCase($caseId);
            $case = $this->store->findCaseById($caseId, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }

            $existing = $this->store->findDecisionByCaseId($case->id);
            if ($existing instanceof VerificationDecisionRecord) {
                if ($existing->matches($decision, $reasonCode, $reviewer->userId)) {
                    return $this->reviewerProjection($case, $existing);
                }
                throw new StateConflict;
            }

            if (! $case->status->canTransitionTo($decision->resultingCaseStatus())) {
                throw new StateConflict;
            }
            if ($case->version !== $expectedVersion) {
                throw new VersionConflict;
            }

            $this->assertNotSelfReview($reviewer, $case);
            if ($case->assignedReviewerId instanceof Identifier && ! $case->assignedReviewerId->equals($reviewer->userId)) {
                throw new StateConflict;
            }

            $doctor = $this->doctors->findById($case->applicantId, true);
            if (! $doctor instanceof DoctorApplicantProjection) {
                throw new AuthorizationDenied;
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $decisionId = $this->ids->next();
            $notesCipher = ($notes !== null && $notes !== '')
                ? BinaryColumn::bind($this->encryptor->encrypt('verification_reviewer_note', $notes))
                : null;

            try {
                $this->store->insertDecision([
                    'id' => $decisionId->value,
                    'case_id' => $case->id->value,
                    'decision' => $decision->value,
                    'reason_code' => $reasonCode,
                    'reviewer_id' => $reviewer->userId->value,
                    'reviewer_assurance_level' => $reviewer->assuranceLevel->value,
                    'notes_ciphertext' => $notesCipher,
                    'created_at' => $stamp,
                ]);
            } catch (DuplicateIdentity) {
                $replay = $this->store->findDecisionByCaseId($case->id);
                if ($replay instanceof VerificationDecisionRecord && $replay->matches($decision, $reasonCode, $reviewer->userId)) {
                    return $this->reviewerProjection($case, $replay);
                }
                throw new StateConflict;
            }

            $affected = $this->store->updateCase($case->id, $expectedVersion, [
                'status' => $decision->resultingCaseStatus()->value,
                'assigned_reviewer_id' => $reviewer->userId->value,
                'decided_at' => $stamp,
                'version' => $expectedVersion + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $this->doctors->transition(
                $doctor->doctorId,
                $this->doctorStatusFor($decision),
                $doctor->version,
                $now,
            );

            $this->audit->append(
                $tx,
                'verification.decision_recorded',
                'verification_case',
                $case->id,
                [
                    'reason_code' => $reasonCode,
                    'decision' => $decision->value,
                    'assurance_level' => $reviewer->assuranceLevel->value,
                ],
                $reviewer->userId,
                'user',
            );
            $this->audit->append(
                $tx,
                'doctor.verification_status_changed',
                'doctor_profile',
                $doctor->doctorId,
                [
                    'reason_code' => $reasonCode,
                    'status' => $this->doctorStatusFor($decision)->value,
                ],
                $reviewer->userId,
                'user',
            );
            $tx->recordEvent(new DoctorVerificationDecided(
                $doctor->doctorId,
                $case->id,
                $decision->value,
                $reasonCode,
                $now,
            ));

            $fresh = $this->store->findCaseById($case->id, false);
            assert($fresh instanceof VerificationCaseRecord);
            $recorded = $this->store->findDecisionByCaseId($case->id);
            assert($recorded instanceof VerificationDecisionRecord);

            return $this->reviewerProjection($fresh, $recorded);
        });
    }

    public function reviewerCase(ActorContext $reviewer, Identifier $caseId): ReviewerCaseProjection
    {
        $this->assertPrivilegedReviewer($reviewer, $caseId);
        $case = $this->store->findCaseById($caseId, false);
        if (! $case instanceof VerificationCaseRecord) {
            throw new AuthorizationDenied;
        }
        $this->assertNotSelfReview($reviewer, $case);

        return $this->reviewerProjection($case);
    }

    private function assertKnownDoctorCaseType(): void
    {
        if (! $this->policy->isKnownCaseType(VerificationCaseType::DoctorVerification->value)) {
            throw new InvalidValueObject('Case type is not allowed.');
        }
    }

    private function assertDoctorActor(ActorContext $actor): void
    {
        if ($actor->accountType !== AccountType::Doctor || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
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
            'verification_case',
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

    private function requireDoctor(Identifier $userId, bool $lock): DoctorApplicantProjection
    {
        $doctor = $this->doctors->findByUserId($userId, $lock);
        if (! $doctor instanceof DoctorApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $doctor;
    }

    private function requirePendingCase(Identifier $caseId): VerificationCaseRecord
    {
        $case = $this->store->findCaseById($caseId, true);
        if (! $case instanceof VerificationCaseRecord) {
            throw new AuthorizationDenied;
        }
        if ($case->status !== VerificationCaseStatus::PendingReview) {
            throw new StateConflict;
        }

        return $case;
    }

    private function assertRequiredDocumentsAvailable(VerificationCaseRecord $case): void
    {
        $required = $this->policy->requiredRequirementCodes($case->caseType->value);
        if ($required === []) {
            throw new InvalidValueObject('Document requirements are not configured.');
        }

        $documents = $this->store->documentsForCase($case->id);
        foreach ($required as $code) {
            $found = false;
            foreach ($documents as $document) {
                if ($document->requirementCode === $code && $document->isReviewable()) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                throw ValidationException::withMessages([
                    'documents' => 'Required documents are not available for review.',
                ]);
            }
        }
    }

    private function doctorStatusFor(VerificationDecision $decision): DoctorVerificationStatus
    {
        return match ($decision) {
            VerificationDecision::Approved => DoctorVerificationStatus::Approved,
            VerificationDecision::Rejected => DoctorVerificationStatus::Rejected,
            VerificationDecision::ChangesRequested => DoctorVerificationStatus::ChangesRequested,
        };
    }

    private function applicantProjection(DoctorApplicantProjection $doctor, ?VerificationCaseRecord $case): ApplicantCaseProjection
    {
        $decision = $case instanceof VerificationCaseRecord
            ? $this->store->findDecisionByCaseId($case->id)
            : null;
        $documents = [];
        if ($case instanceof VerificationCaseRecord) {
            foreach ($this->store->documentsForCase($case->id) as $document) {
                $documents[] = [
                    'document_id' => $document->id->value,
                    'requirement_code' => $document->requirementCode,
                    'scan_status' => $document->scanStatus->value,
                    'status' => $document->status->value,
                    'uploaded_at' => $this->iso($document->uploadedAt),
                ];
            }
        }

        return new ApplicantCaseProjection(
            $doctor->doctorId->value,
            $doctor->verificationStatus->value,
            $doctor->publicStatus->value,
            $doctor->version,
            $case?->id->value,
            $case?->status->value,
            $case?->version,
            $case?->caseType->value,
            $case instanceof VerificationCaseRecord ? $this->isoOrNull($case->submittedAt) : null,
            $case instanceof VerificationCaseRecord ? $this->isoOrNull($case->decidedAt) : null,
            $decision?->decision->value,
            $decision?->reasonCode,
            $documents,
        );
    }

    private function reviewerProjection(VerificationCaseRecord $case, ?VerificationDecisionRecord $decision = null): ReviewerCaseProjection
    {
        $decision ??= $this->store->findDecisionByCaseId($case->id);
        $documents = [];
        foreach ($this->store->documentsForCase($case->id) as $document) {
            $documents[] = [
                'document_id' => $document->id->value,
                'requirement_code' => $document->requirementCode,
                'sha256' => $document->sha256,
                'detected_mime' => $document->detectedMime,
                'size_bytes' => $document->sizeBytes,
                'scan_status' => $document->scanStatus->value,
                'status' => $document->status->value,
                'uploaded_at' => $this->iso($document->uploadedAt),
            ];
        }

        return new ReviewerCaseProjection(
            $case->id->value,
            $case->caseType->value,
            $case->status->value,
            $case->version,
            $case->applicantId->value,
            $this->isoOrNull($case->submittedAt),
            $case->assignedReviewerId?->value,
            $this->isoOrNull($case->decidedAt),
            $decision?->decision->value,
            $decision?->reasonCode,
            $documents,
        );
    }

    private function iso(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function isoOrNull(?DateTimeImmutable $value): ?string
    {
        return $value instanceof DateTimeImmutable ? $this->iso($value) : null;
    }
}
