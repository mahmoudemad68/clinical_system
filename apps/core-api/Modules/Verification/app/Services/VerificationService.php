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
use Modules\Doctors\Services\CreateAdminDoctorApplicant;
use Modules\Doctors\Services\DoctorApplicantService;
use Modules\Doctors\Services\DoctorReviewerService;
use Modules\Doctors\Services\ListSpecialties;
use Modules\Doctors\Support\AdminCreatedDoctorResult;
use Modules\Doctors\Support\DoctorApplicantProjection;
use Modules\Doctors\Support\DoctorReviewerProjection;
use Modules\Doctors\Support\SpecialtyProjection;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Services\PharmacyApplicantService;
use Modules\Pharmacies\Services\PharmacyReviewerService;
use Modules\Pharmacies\Support\PharmacyApplicantProjection;
use Modules\Pharmacies\Support\PharmacyReviewerProjection;
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
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\ApplicantType;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;
use Modules\Verification\Enums\VerificationDecision;
use Modules\Verification\Events\DoctorVerificationDecided;
use Modules\Verification\Events\DoctorVerificationSubmitted;
use Modules\Verification\Events\PharmacyVerificationDecided;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\AdminDoctorApplicantOutcome;
use Modules\Verification\Support\ApplicantCaseProjection;
use Modules\Verification\Support\PharmacyApplicantCaseProjection;
use Modules\Verification\Support\PharmacyVerificationCaseOutcome;
use Modules\Verification\Support\PharmacyVerificationSubmissionOutcome;
use Modules\Verification\Support\PrivilegedAdminDoctorCreateGuard;
use Modules\Verification\Support\ReviewerApplicantProjection;
use Modules\Verification\Support\ReviewerCaseProjection;
use Modules\Verification\Support\ReviewerQueueFilters;
use Modules\Verification\Support\ReviewerQueueItemProjection;
use Modules\Verification\Support\ReviewerQueuePage;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationDecisionRecord;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationSubmissionOutcome;

/**
 * Verification case lifecycle. Coordinates Verification writes with Doctors
 * or Pharmacies applicant transitions inside one transaction.
 */
final class VerificationService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresVerificationStore $store,
        private readonly DoctorApplicantService $doctors,
        private readonly DoctorReviewerService $reviewers,
        private readonly PharmacyApplicantService $pharmacies,
        private readonly PharmacyReviewerService $pharmacyReviewers,
        private readonly CreateAdminDoctorApplicant $adminDoctors,
        private readonly ListSpecialties $specialties,
        private readonly PrivilegedAdminDoctorCreateGuard $adminCreate,
        private readonly VerificationPolicy $policy,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly RecordPrivilegedFailure $privilegedFailures,
        private readonly FieldEncryptor $encryptor,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly PlatformMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function createAdminDoctorApplicant(ActorContext $actor, array $input): AdminDoctorApplicantOutcome
    {
        $this->adminCreate->assert($actor, $actor->userId, 'user');

        $created = $this->adminDoctors->create($actor, $input);
        if ($created->status === AdminCreatedDoctorResult::MANUAL_REVIEW_REQUIRED || $created->doctorId === null) {
            return new AdminDoctorApplicantOutcome(AdminCreatedDoctorResult::MANUAL_REVIEW_REQUIRED);
        }

        $opened = $this->openRepresentedDoctorCase($actor, Identifier::fromTrusted($created->doctorId));

        return AdminDoctorApplicantOutcome::fromCreated($created, $opened);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSpecialtiesForAdminCreate(ActorContext $actor): array
    {
        $this->adminCreate->assert($actor, $actor->userId, 'user');

        return array_map(
            static fn (SpecialtyProjection $row): array => $row->toArray(),
            $this->specialties->handle(),
        );
    }

    public function openDoctorCase(ActorContext $actor): ApplicantCaseProjection
    {
        $this->assertDoctorActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor): ApplicantCaseProjection {
            $this->assertKnownCaseType(VerificationCaseType::DoctorVerification);
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

    public function openRepresentedDoctorCase(ActorContext $actor, Identifier $doctorId): ApplicantCaseProjection
    {
        $this->adminCreate->assert($actor, $doctorId, 'doctor_profile');

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $doctorId): ApplicantCaseProjection {
            $this->assertKnownCaseType(VerificationCaseType::DoctorVerification);
            $doctor = $this->requireDoctorById($doctorId, true);
            $this->assertRepresentedCreator($actor, $doctor);
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
                    'source_type' => 'admin_created',
                ],
                $actor->userId,
                'user',
            );

            $case = $this->store->findCaseById($caseId, false);
            assert($case instanceof VerificationCaseRecord);

            return $this->applicantProjection($doctor, $case);
        });
    }

    public function openPharmacyCase(ActorContext $actor): PharmacyVerificationCaseOutcome
    {
        $this->assertPharmacyActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor): PharmacyVerificationCaseOutcome {
            $this->assertKnownCaseType(VerificationCaseType::PharmacyVerification);
            $pharmacy = $this->requirePharmacy($actor->userId, true);
            $this->store->lockApplicant(ApplicantType::Pharmacy, $pharmacy->organizationId);

            if (! $pharmacy->verificationStatus->allowsNewVerificationCase()) {
                $open = $this->store->findOpenCase(ApplicantType::Pharmacy, $pharmacy->organizationId, VerificationCaseType::PharmacyVerification, true);
                if ($open instanceof VerificationCaseRecord) {
                    return $this->pharmacyCaseOutcome($pharmacy, $open);
                }
                throw new StateConflict;
            }

            $existing = $this->store->findOpenCase(ApplicantType::Pharmacy, $pharmacy->organizationId, VerificationCaseType::PharmacyVerification, true);
            if ($existing instanceof VerificationCaseRecord) {
                return $this->pharmacyCaseOutcome($pharmacy, $existing);
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $caseId = $this->ids->next();

            try {
                $this->store->insertCase([
                    'id' => $caseId->value,
                    'applicant_type' => ApplicantType::Pharmacy->value,
                    'applicant_id' => $pharmacy->organizationId->value,
                    'case_type' => VerificationCaseType::PharmacyVerification->value,
                    'status' => VerificationCaseStatus::Draft->value,
                    'submitted_at' => null,
                    'assigned_reviewer_id' => null,
                    'decided_at' => null,
                    'version' => 1,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            } catch (DuplicateIdentity) {
                $retry = $this->store->findOpenCase(ApplicantType::Pharmacy, $pharmacy->organizationId, VerificationCaseType::PharmacyVerification, true);
                if ($retry instanceof VerificationCaseRecord) {
                    return $this->pharmacyCaseOutcome($pharmacy, $retry);
                }
                throw new StateConflict;
            }

            $this->audit->append(
                $tx,
                'verification.case_created',
                'verification_case',
                $caseId,
                [
                    'reason_code' => 'pharmacy_verification',
                    'case_type' => VerificationCaseType::PharmacyVerification->value,
                ],
                $actor->userId,
                'user',
            );

            $case = $this->store->findCaseById($caseId, false);
            assert($case instanceof VerificationCaseRecord);

            return $this->pharmacyCaseOutcome($pharmacy, $case);
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
            $this->assertKnownCaseType(VerificationCaseType::DoctorVerification);
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

    /**
     * @param  array{case_version: int, profile_version: int}  $input
     */
    public function submitRepresentedDoctorCase(ActorContext $actor, Identifier $doctorId, array $input): VerificationSubmissionOutcome
    {
        $this->adminCreate->assert($actor, $doctorId, 'doctor_profile');

        $expectedCaseVersion = (int) $input['case_version'];
        $expectedProfileVersion = (int) $input['profile_version'];

        return $this->transactions->run(function (TransactionContext $tx) use (
            $actor,
            $doctorId,
            $expectedCaseVersion,
            $expectedProfileVersion,
        ): VerificationSubmissionOutcome {
            $this->assertKnownCaseType(VerificationCaseType::DoctorVerification);
            $doctor = $this->requireDoctorById($doctorId, true);
            $this->assertRepresentedCreator($actor, $doctor);
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

    /**
     * @param  array{case_version: int, organization_version: int}  $input
     */
    public function submitPharmacyCase(ActorContext $actor, array $input): PharmacyVerificationSubmissionOutcome
    {
        $this->assertPharmacyActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $expectedCaseVersion = (int) $input['case_version'];
        $expectedOrganizationVersion = (int) $input['organization_version'];

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $expectedCaseVersion, $expectedOrganizationVersion): PharmacyVerificationSubmissionOutcome {
            $this->assertKnownCaseType(VerificationCaseType::PharmacyVerification);
            $pharmacy = $this->requirePharmacy($actor->userId, true);
            $this->store->lockApplicant(ApplicantType::Pharmacy, $pharmacy->organizationId);
            $case = $this->store->findOpenCase(ApplicantType::Pharmacy, $pharmacy->organizationId, VerificationCaseType::PharmacyVerification, true);
            if (! $case instanceof VerificationCaseRecord) {
                throw new AuthorizationDenied;
            }
            if ($case->version !== $expectedCaseVersion) {
                throw new VersionConflict;
            }
            if ($pharmacy->version !== $expectedOrganizationVersion) {
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

            $pharmacy = $this->pharmacies->transition(
                $pharmacy->organizationId,
                PharmacyVerificationStatus::PendingReview,
                $expectedOrganizationVersion,
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
                'pharmacy.verification_status_changed',
                'pharmacy_organization',
                $pharmacy->organizationId,
                [
                    'reason_code' => 'submitted',
                    'status' => PharmacyVerificationStatus::PendingReview->value,
                ],
                $actor->userId,
                'user',
            );

            $fresh = $this->store->findCaseById($case->id, false);
            assert($fresh instanceof VerificationCaseRecord);

            return new PharmacyVerificationSubmissionOutcome(
                $pharmacy->organizationId->value,
                $fresh->id->value,
                $fresh->status->value,
                $fresh->version,
                $pharmacy->version,
                $pharmacy->verificationStatus->value,
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

    public function pharmacyApplicantStatus(ActorContext $actor): PharmacyApplicantCaseProjection
    {
        $this->assertPharmacyActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_STATUS_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $pharmacy = $this->requirePharmacy($actor->userId, false);
        $case = $this->store->findLatestCase(ApplicantType::Pharmacy, $pharmacy->organizationId, VerificationCaseType::PharmacyVerification);

        return $this->pharmacyApplicantProjection($pharmacy, $case);
    }

    public function pharmacyApplicantStatusForOrganization(ActorContext $actor, Identifier $organizationId): PharmacyApplicantCaseProjection
    {
        $status = $this->pharmacyApplicantStatus($actor);
        if ($status->organizationId !== $organizationId->value) {
            throw new AuthorizationDenied;
        }

        return $status;
    }

    public function claimCase(ActorContext $reviewer, Identifier $caseId, int $expectedVersion): ReviewerCaseProjection
    {
        $this->assertPrivilegedReviewer($reviewer, $caseId);

        return $this->transactions->run(function (TransactionContext $tx) use ($reviewer, $caseId, $expectedVersion): ReviewerCaseProjection {
            $this->store->lockCase($caseId);
            $case = $this->requirePendingCase($caseId);
            $this->assertNotSelfReview($reviewer, $case);
            if ($case->assignedReviewerId instanceof Identifier && $case->assignedReviewerId->equals($reviewer->userId)) {
                return $this->reviewerProjection($reviewer, $case);
            }
            if ($case->assignedReviewerId instanceof Identifier) {
                throw new StateConflict;
            }
            if ($case->version !== $expectedVersion) {
                throw new VersionConflict;
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
            $this->countReview('claimed', $fresh->caseType->value, 'claimed');

            return $this->reviewerProjection($reviewer, $fresh);
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
            $this->assertKnownCaseType($case->caseType);

            $existing = $this->store->findDecisionByCaseId($case->id);
            if ($existing instanceof VerificationDecisionRecord) {
                if ($existing->matches($decision, $reasonCode, $reviewer->userId)) {
                    $this->assertAssignedReviewer($reviewer, $case);

                    return $this->reviewerProjection($reviewer, $case, $existing);
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
            $this->assertAssignedReviewer($reviewer, $case);
            $this->assertRequiredDocumentsAvailable($case);

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
                    return $this->reviewerProjection($reviewer, $case, $replay);
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

            match ($case->applicantType) {
                ApplicantType::Doctor => $this->commitDoctorDecision($tx, $reviewer, $case, $decision, $reasonCode, $now),
                ApplicantType::Pharmacy => $this->commitPharmacyDecision($tx, $reviewer, $case, $decision, $reasonCode, $now),
            };

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

            $fresh = $this->store->findCaseById($case->id, false);
            assert($fresh instanceof VerificationCaseRecord);
            $recorded = $this->store->findDecisionByCaseId($case->id);
            assert($recorded instanceof VerificationDecisionRecord);
            $this->countReview('recorded', $fresh->caseType->value, $reasonCode, $decision->value);

            return $this->reviewerProjection($reviewer, $fresh, $recorded);
        });
    }

    /**
     * @param  array{submitted_at: string, case_id: string}|null  $after
     */
    public function listReviewQueue(ActorContext $reviewer, ReviewerQueueFilters $filters, ?array $after): ReviewerQueuePage
    {
        $this->assertPrivilegedReviewer($reviewer, $reviewer->userId, 'verification_review_queue');
        if ($filters->limit > $this->policy->queueMaxLimit()) {
            throw new InvalidValueObject('Queue page size is not allowed.');
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($reviewer, $filters, $after): ReviewerQueuePage {
            $rows = $this->store->listReviewerQueue(
                $filters->caseType,
                $filters->status,
                $filters->assignment,
                $reviewer->userId,
                $after,
                $filters->limit + 1,
            );
            $hasMore = count($rows) > $filters->limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $filters->limit);
            }

            $doctorIds = [];
            $pharmacyIds = [];
            foreach ($rows as $row) {
                match ($row->applicantType) {
                    ApplicantType::Doctor => $doctorIds[] = $row->applicantId,
                    ApplicantType::Pharmacy => $pharmacyIds[] = $row->applicantId,
                };
            }
            $doctors = $this->reviewers->findByIds($doctorIds);
            $pharmacies = $this->pharmacyReviewers->findByIds($pharmacyIds);

            $items = [];
            foreach ($rows as $row) {
                $applicant = $this->queueApplicant($row, $doctors, $pharmacies);
                if (! $applicant instanceof ReviewerApplicantProjection) {
                    continue;
                }
                $assignedToMe = $row->assignedReviewerId instanceof Identifier
                    && $row->assignedReviewerId->equals($reviewer->userId);
                $items[] = new ReviewerQueueItemProjection(
                    $row->id->value,
                    $row->caseType->value,
                    $row->status->value,
                    $row->version,
                    $this->isoOrNull($row->submittedAt),
                    $this->assignmentFor($reviewer, $row),
                    $assignedToMe,
                    $applicant,
                );
            }

            $next = null;
            if ($hasMore && $items !== []) {
                $tail = $items[array_key_last($items)];
                if ($tail->submittedAt !== null) {
                    $next = [
                        'submitted_at' => $tail->submittedAt,
                        'case_id' => $tail->caseId,
                    ];
                }
            }

            $this->audit->append(
                $tx,
                'verification.review_queue_listed',
                'verification_review_queue',
                $reviewer->userId,
                [
                    'reason_code' => 'review_queue',
                    'assignment' => $filters->assignment,
                    'case_type' => $filters->caseType->value,
                    'status' => $filters->status->value,
                ],
                $reviewer->userId,
                'user',
            );

            return new ReviewerQueuePage($items, $hasMore, $next, $filters->limit);
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

        return $this->transactions->run(function (TransactionContext $tx) use ($reviewer, $case): ReviewerCaseProjection {
            $projection = $this->reviewerProjection($reviewer, $case);
            $this->audit->append(
                $tx,
                'verification.case_viewed',
                'verification_case',
                $case->id,
                [
                    'reason_code' => 'reviewer_read',
                    'assigned_to_me' => $projection->assignedToMe,
                ],
                $reviewer->userId,
                'user',
            );

            return $projection;
        });
    }

    private function commitDoctorDecision(
        TransactionContext $tx,
        ActorContext $reviewer,
        VerificationCaseRecord $case,
        VerificationDecision $decision,
        string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $doctor = $this->doctors->findById($case->applicantId, true);
        if (! $doctor instanceof DoctorApplicantProjection) {
            throw new AuthorizationDenied;
        }

        $this->doctors->transition(
            $doctor->doctorId,
            $this->doctorStatusFor($decision),
            $doctor->version,
            $now,
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
    }

    private function commitPharmacyDecision(
        TransactionContext $tx,
        ActorContext $reviewer,
        VerificationCaseRecord $case,
        VerificationDecision $decision,
        string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $pharmacy = $this->pharmacies->findById($case->applicantId, true);
        if (! $pharmacy instanceof PharmacyApplicantProjection) {
            throw new AuthorizationDenied;
        }

        $pharmacy = $this->pharmacies->transition(
            $pharmacy->organizationId,
            $this->pharmacyStatusFor($decision),
            $pharmacy->version,
            $now,
        );

        $this->audit->append(
            $tx,
            'pharmacy.verification_status_changed',
            'pharmacy_organization',
            $pharmacy->organizationId,
            [
                'reason_code' => $reasonCode,
                'status' => $this->pharmacyStatusFor($decision)->value,
            ],
            $reviewer->userId,
            'user',
        );
        $tx->recordEvent(new PharmacyVerificationDecided(
            $pharmacy->organizationId,
            [$pharmacy->branchId->value],
            $case->id,
            $decision->value,
            $reasonCode,
            $now,
        ));
    }

    private function assertKnownCaseType(VerificationCaseType $caseType): void
    {
        if (! $this->policy->isKnownCaseType($caseType->value)) {
            throw new InvalidValueObject('Case type is not allowed.');
        }
    }

    private function assertDoctorActor(ActorContext $actor): void
    {
        if ($actor->accountType !== AccountType::Doctor || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }
    }

    private function assertPharmacyActor(ActorContext $actor): void
    {
        if ($actor->accountType !== AccountType::Pharmacy || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }
    }

    private function assertPrivilegedReviewer(ActorContext $reviewer, Identifier $objectId, string $objectType = 'verification_case'): void
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
            $objectType,
        );
        throw new AuthorizationDenied;
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

        if ($case->applicantType === ApplicantType::Doctor) {
            $createdBy = $this->doctors->findById($case->applicantId, false)?->createdByUserId;
            if ($createdBy instanceof Identifier && $createdBy->equals($reviewer->userId)) {
                throw new AuthorizationDenied;
            }
        }
    }

    private function assertAssignedReviewer(ActorContext $reviewer, VerificationCaseRecord $case): void
    {
        if (! $case->assignedReviewerId instanceof Identifier || ! $case->assignedReviewerId->equals($reviewer->userId)) {
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

    private function requireDoctorById(Identifier $doctorId, bool $lock): DoctorApplicantProjection
    {
        $doctor = $this->doctors->findById($doctorId, $lock);
        if (! $doctor instanceof DoctorApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $doctor;
    }

    private function assertRepresentedCreator(ActorContext $actor, DoctorApplicantProjection $doctor): void
    {
        if (! $doctor->createdByUserId->equals($actor->userId)) {
            throw new AuthorizationDenied;
        }
    }

    private function requirePharmacy(Identifier $userId, bool $lock): PharmacyApplicantProjection
    {
        $pharmacy = $this->pharmacies->findByUserId($userId, $lock);
        if (! $pharmacy instanceof PharmacyApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $pharmacy;
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

    private function pharmacyStatusFor(VerificationDecision $decision): PharmacyVerificationStatus
    {
        return match ($decision) {
            VerificationDecision::Approved => PharmacyVerificationStatus::Approved,
            VerificationDecision::Rejected => PharmacyVerificationStatus::Rejected,
            VerificationDecision::ChangesRequested => PharmacyVerificationStatus::ChangesRequested,
        };
    }

    private function pharmacyCaseOutcome(
        PharmacyApplicantProjection $pharmacy,
        VerificationCaseRecord $case,
    ): PharmacyVerificationCaseOutcome {
        return new PharmacyVerificationCaseOutcome(
            $pharmacy->organizationId->value,
            $case->id->value,
            $case->status->value,
            $case->version,
            $pharmacy->version,
        );
    }

    /**
     * @return list<array{document_id: string, requirement_code: string, scan_status: string, status: string, uploaded_at: string}>
     */
    private function applicantDocuments(VerificationCaseRecord $case): array
    {
        $documents = [];
        foreach ($this->store->documentsForCase($case->id) as $document) {
            $documents[] = [
                'document_id' => $document->id->value,
                'requirement_code' => $document->requirementCode,
                'scan_status' => $document->scanStatus->value,
                'status' => $document->status->value,
                'uploaded_at' => $this->iso($document->uploadedAt),
            ];
        }

        return $documents;
    }

    private function applicantProjection(DoctorApplicantProjection $doctor, ?VerificationCaseRecord $case): ApplicantCaseProjection
    {
        $decision = $case instanceof VerificationCaseRecord
            ? $this->store->findDecisionByCaseId($case->id)
            : null;
        $documents = $case instanceof VerificationCaseRecord ? $this->applicantDocuments($case) : [];

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

    private function pharmacyApplicantProjection(
        PharmacyApplicantProjection $pharmacy,
        ?VerificationCaseRecord $case,
    ): PharmacyApplicantCaseProjection {
        $decision = $case instanceof VerificationCaseRecord
            ? $this->store->findDecisionByCaseId($case->id)
            : null;
        $documents = $case instanceof VerificationCaseRecord ? $this->applicantDocuments($case) : [];

        return new PharmacyApplicantCaseProjection(
            $pharmacy->organizationId->value,
            $pharmacy->verificationStatus->value,
            $pharmacy->status->value,
            $pharmacy->version,
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

    private function reviewerProjection(ActorContext $reviewer, VerificationCaseRecord $case, ?VerificationDecisionRecord $decision = null): ReviewerCaseProjection
    {
        $decision ??= $this->store->findDecisionByCaseId($case->id);
        $assigned = $case->assignedReviewerId instanceof Identifier
            && $case->assignedReviewerId->equals($reviewer->userId);
        $documents = [];
        if ($assigned) {
            foreach ($this->store->documentsForCase($case->id) as $document) {
                if (! $document->isReviewable()) {
                    continue;
                }
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
            $assigned,
            $this->assignmentFor($reviewer, $case),
            $this->reviewerApplicant($case),
        );
    }

    private function reviewerApplicant(VerificationCaseRecord $case): ReviewerApplicantProjection
    {
        return match ($case->applicantType) {
            ApplicantType::Doctor => $this->doctorReviewerApplicant($case),
            ApplicantType::Pharmacy => $this->pharmacyReviewerApplicant($case),
        };
    }

    private function doctorReviewerApplicant(VerificationCaseRecord $case): ReviewerApplicantProjection
    {
        $doctor = $this->reviewers->findById($case->applicantId);
        if (! $doctor instanceof DoctorReviewerProjection) {
            throw new AuthorizationDenied;
        }

        return ReviewerApplicantProjection::doctor($doctor);
    }

    private function pharmacyReviewerApplicant(VerificationCaseRecord $case): ReviewerApplicantProjection
    {
        $pharmacy = $this->pharmacyReviewers->findById($case->applicantId);
        if (! $pharmacy instanceof PharmacyReviewerProjection) {
            throw new AuthorizationDenied;
        }

        return ReviewerApplicantProjection::pharmacy($pharmacy);
    }

    /**
     * @param  array<string, DoctorReviewerProjection>  $doctors
     * @param  array<string, PharmacyReviewerProjection>  $pharmacies
     */
    private function queueApplicant(
        VerificationCaseRecord $row,
        array $doctors,
        array $pharmacies,
    ): ?ReviewerApplicantProjection {
        return match ($row->applicantType) {
            ApplicantType::Doctor => isset($doctors[$row->applicantId->value])
                ? ReviewerApplicantProjection::doctor($doctors[$row->applicantId->value])
                : null,
            ApplicantType::Pharmacy => isset($pharmacies[$row->applicantId->value])
                ? ReviewerApplicantProjection::pharmacy($pharmacies[$row->applicantId->value])
                : null,
        };
    }

    private function assignmentFor(ActorContext $reviewer, VerificationCaseRecord $case): string
    {
        if (! $case->assignedReviewerId instanceof Identifier) {
            return ReviewerQueueFilters::ASSIGNMENT_UNASSIGNED;
        }

        return $case->assignedReviewerId->equals($reviewer->userId)
            ? ReviewerQueueFilters::ASSIGNMENT_MINE
            : 'other';
    }

    private function countReview(string $result, string $caseType, string $reasonCode, ?string $decision = null): void
    {
        try {
            $labels = [
                'result' => $result,
                'case_type' => $caseType,
                'reason_code' => $reasonCode,
            ];
            if ($decision !== null) {
                $labels['decision'] = $decision;
            }
            $this->metrics->increment('clinic_verification_review_results_total', $labels);
        } catch (\Throwable) {
        }
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
