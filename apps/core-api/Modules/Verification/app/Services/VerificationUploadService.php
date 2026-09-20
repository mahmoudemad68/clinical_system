<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Doctors\Services\DoctorApplicantService;
use Modules\Doctors\Support\DoctorApplicantProjection;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\PharmacyApplicantService;
use Modules\Pharmacies\Support\PharmacyApplicantProjection;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\ObjectUploadGrant;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Enums\ApplicantType;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationUploadState;
use Modules\Verification\Events\VerificationUploadCompleted;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationUploadIntentRecord;
use Modules\Verification\Support\VerificationUploadProjection;
use RuntimeException;
use Throwable;

/**
 * Own-case verification upload intents for doctor or pharmacy applicants.
 * Platform storage stays generic. The public method names retain Doctor for
 * compatibility; ownership is derived from the authenticated actor and case.
 *
 * @phpstan-type CreateResult array{
 *     projection: VerificationUploadProjection,
 *     grant: ObjectUploadGrant
 * }
 */
final class VerificationUploadService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresVerificationStore $store,
        private readonly DoctorApplicantService $doctors,
        private readonly PharmacyApplicantService $pharmacies,
        private readonly VerificationPolicy $policy,
        private readonly Authorize $authorize,
        private readonly StoreObject $objects,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array{
     *     case_id: string,
     *     requirement_code: string,
     *     expected_size_bytes: int,
     *     declared_media_type: string,
     *     sha256?: string|null
     * }  $input
     * @return CreateResult
     */
    public function createDoctorUpload(ActorContext $actor, array $input): array
    {
        $this->assertApplicantActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $caseId = Identifier::fromString((string) $input['case_id']);
        $requirement = (string) $input['requirement_code'];
        $expectedSize = (int) $input['expected_size_bytes'];
        $declaredMime = (string) $input['declared_media_type'];
        $expectedSha = isset($input['sha256']) && is_string($input['sha256']) && $input['sha256'] !== ''
            ? $input['sha256']
            : null;

        if (! $this->policy->isAllowedMime($declaredMime)) {
            throw ValidationException::withMessages(['declared_media_type' => 'Declared media type is not allowed.']);
        }
        if ($expectedSize < 1 || $expectedSize > $this->policy->maxDocumentBytes()) {
            throw ValidationException::withMessages(['expected_size_bytes' => 'Expected size is outside the allowed bound.']);
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $caseId, $requirement, $expectedSize, $declaredMime, $expectedSha): array {
            $this->store->lockCase($caseId);
            $case = $this->requireOwnDraftCase($actor, $caseId);
            if (! $this->policy->isKnownRequirement($case->caseType->value, $requirement)) {
                throw ValidationException::withMessages(['requirement_code' => 'Requirement code is not allowed.']);
            }

            if ($this->store->countActiveUploads($case->id, $requirement) >= $this->policy->maxActiveUploadsPerRequirement()) {
                throw new StateConflict;
            }

            $now = $this->clock->now();
            $expires = $now->modify('+'.$this->policy->uploadExpirySeconds().' seconds');
            $uploadId = $this->ids->next();
            $objectId = $this->ids->next();
            $grant = $this->objects->createUploadGrant(
                $this->policy->objectNamespace(),
                $objectId->value,
                $expectedSize,
                $declaredMime,
                $expires,
            );

            $stamp = $now->format('Y-m-d H:i:s.uP');
            $this->store->insertUpload([
                'id' => $uploadId->value,
                'case_id' => $case->id->value,
                'created_by_user_id' => $actor->userId->value,
                'requirement_code' => $requirement,
                'object_id' => $objectId->value,
                'storage_locator' => $grant->storageLocator,
                'canonical_storage_locator' => null,
                'state' => VerificationUploadState::Uploading->value,
                'expected_size_bytes' => $expectedSize,
                'declared_media_type' => $declaredMime,
                'expected_sha256' => $expectedSha,
                'object_version' => null,
                'observed_size_bytes' => null,
                'observed_sha256' => null,
                'detected_mime' => null,
                'scanner_identity' => null,
                'scanner_version' => null,
                'rejection_reason' => null,
                'expires_at' => $expires->format('Y-m-d H:i:s.uP'),
                'completed_at' => null,
                'available_at' => null,
                'cleanup_eligible_at' => null,
                'cleanup_completed_at' => null,
                'processing_attempts' => 0,
                'version' => 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            $this->audit->append(
                $tx,
                'verification.upload_intent_created',
                'verification_upload_intent',
                $uploadId,
                [
                    'reason_code' => 'upload_requested',
                    'requirement_code' => $requirement,
                    'state' => VerificationUploadState::Uploading->value,
                ],
                $actor->userId,
                'user',
            );

            $row = $this->store->findUploadById($uploadId, false);
            assert($row instanceof VerificationUploadIntentRecord);

            return [
                'projection' => $this->project($row),
                'grant' => $grant,
            ];
        });
    }

    /**
     * Reconstruct a usable create outcome for the same upload intent.
     * Never stores or logs a signed URL. Grant expiry cannot exceed the
     * intent's expires_at.
     *
     * @return array<string, mixed>
     */
    public function replayDoctorUploadCreate(ActorContext $actor, Identifier $uploadId): array
    {
        $this->assertApplicantActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $upload = $this->store->findUploadById($uploadId, false);
        if (! $upload instanceof VerificationUploadIntentRecord) {
            throw new AuthorizationDenied;
        }

        $this->assertOwnsCase($actor, $upload->caseId);

        $projection = $this->project($upload);
        $data = $projection->toArray();
        if ($upload->state !== VerificationUploadState::Uploading) {
            return $data;
        }

        $now = $this->clock->now();
        if ($upload->expiresAt <= $now) {
            return $data;
        }

        $grant = $this->objects->issueUploadGrant(
            $upload->storedRef(),
            $upload->expectedSizeBytes,
            $upload->declaredMediaType,
            $upload->expiresAt,
        );

        $data['upload_target'] = [
            'method' => $grant->method,
            'url' => $grant->url,
            'headers' => $grant->headers,
            'expires_at' => $projection->expiresAt,
        ];

        return $data;
    }

    public function completeDoctorUpload(ActorContext $actor, Identifier $uploadId): VerificationUploadProjection
    {
        $this->assertApplicantActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_SUBMIT_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $failure = null;
        /** @var array{outcome: 'replay'|'seal'|'rejected', projection: VerificationUploadProjection, ingress: StoredObjectRef|null, canonical: StoredObjectRef|null} $prepared */
        $prepared = $this->transactions->run(function (TransactionContext $tx) use ($actor, $uploadId, &$failure): array {
            $this->store->lockUpload($uploadId);
            $upload = $this->store->findUploadById($uploadId, true);
            if (! $upload instanceof VerificationUploadIntentRecord) {
                throw new AuthorizationDenied;
            }

            $this->store->lockCase($upload->caseId);
            $this->requireOwnDraftCase($actor, $upload->caseId);

            $now = $this->clock->now();
            if ($upload->state->isProcessable() || $upload->state === VerificationUploadState::Available) {
                return [
                    'outcome' => 'replay',
                    'projection' => $this->project($upload),
                    'ingress' => $upload->storedRef(),
                    'canonical' => $upload->canonicalRef(),
                ];
            }
            if ($upload->state === VerificationUploadState::Rejected) {
                throw new StateConflict;
            }
            if ($upload->state !== VerificationUploadState::Uploading) {
                throw new StateConflict;
            }
            if ($upload->expiresAt <= $now) {
                $this->rejectLocked($tx, $upload, 'expired', $now);
                $failure = new StateConflict;
                $fresh = $this->store->findUploadById($upload->id, false);
                assert($fresh instanceof VerificationUploadIntentRecord);

                return [
                    'outcome' => 'rejected',
                    'projection' => $this->project($fresh),
                    'ingress' => null,
                    'canonical' => null,
                ];
            }

            $canonical = $upload->canonicalRef();
            if (! $canonical instanceof StoredObjectRef) {
                $ref = $upload->storedRef();
                if (! $this->objects->exists($ref)) {
                    $this->rejectLocked($tx, $upload, 'object_missing', $now);
                    $failure = new InvalidValueObject('Uploaded object was not found.');
                    $fresh = $this->store->findUploadById($upload->id, false);
                    assert($fresh instanceof VerificationUploadIntentRecord);

                    return [
                        'outcome' => 'rejected',
                        'projection' => $this->project($fresh),
                        'ingress' => null,
                        'canonical' => null,
                    ];
                }

                $canonical = $this->objects->allocateCanonicalRef(
                    $this->policy->objectNamespace(),
                    $upload->objectId->value,
                );
                $stamp = $now->format('Y-m-d H:i:s.uP');
                $affected = $this->store->updateUpload($upload->id, $upload->version, [
                    'canonical_storage_locator' => $canonical->storageLocator,
                    'version' => $upload->version + 1,
                    'updated_at' => $stamp,
                ]);
                if ($affected !== 1) {
                    throw new StateConflict;
                }
            }

            return [
                'outcome' => 'seal',
                'projection' => $this->project($upload),
                'ingress' => $upload->storedRef(),
                'canonical' => $canonical,
            ];
        });

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        if ($prepared['outcome'] === 'replay') {
            if ($prepared['ingress'] instanceof StoredObjectRef) {
                $this->deleteIngressQuietly($prepared['ingress']);
            }

            return $prepared['projection'];
        }

        $ingress = $prepared['ingress'];
        $canonical = $prepared['canonical'];
        assert($ingress instanceof StoredObjectRef);
        assert($canonical instanceof StoredObjectRef);

        if ($this->transactions->inTransaction()) {
            throw new RuntimeException('Canonical seal copy must not run inside a database transaction.');
        }

        try {
            $this->objects->copyExact($ingress, $canonical);
        } catch (Throwable) {
            throw new TransientProviderFailure('Object seal failed.');
        }

        $projection = $this->transactions->run(function (TransactionContext $tx) use ($actor, $uploadId, $canonical): VerificationUploadProjection {
            $this->store->lockUpload($uploadId);
            $upload = $this->store->findUploadById($uploadId, true);
            if (! $upload instanceof VerificationUploadIntentRecord) {
                throw new AuthorizationDenied;
            }

            $this->store->lockCase($upload->caseId);
            $this->requireOwnDraftCase($actor, $upload->caseId);

            if ($upload->state->isProcessable() || $upload->state === VerificationUploadState::Available) {
                return $this->project($upload);
            }
            if ($upload->state !== VerificationUploadState::Uploading) {
                throw new StateConflict;
            }

            $persisted = $upload->canonicalRef();
            if (! $persisted instanceof StoredObjectRef || $persisted->key() !== $canonical->key()) {
                throw new StateConflict;
            }
            if (! $this->objects->exists($persisted)) {
                throw new TransientProviderFailure('Object seal failed.');
            }

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $affected = $this->store->updateUpload($upload->id, $upload->version, [
                'state' => VerificationUploadState::Quarantined->value,
                'completed_at' => $stamp,
                'version' => $upload->version + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1) {
                throw new StateConflict;
            }

            $this->audit->append(
                $tx,
                'verification.upload_completion_accepted',
                'verification_upload_intent',
                $upload->id,
                [
                    'reason_code' => 'completion_accepted',
                    'requirement_code' => $upload->requirementCode,
                    'state' => VerificationUploadState::Quarantined->value,
                ],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new VerificationUploadCompleted($upload->id, $now));

            $fresh = $this->store->findUploadById($upload->id, false);
            assert($fresh instanceof VerificationUploadIntentRecord);

            return $this->project($fresh);
        });

        $this->deleteIngressQuietly($ingress);

        return $projection;
    }

    public function doctorUploadStatus(ActorContext $actor, Identifier $uploadId): VerificationUploadProjection
    {
        $this->assertApplicantActor($actor);
        $decision = $this->authorize->decide($actor, Capabilities::VERIFICATION_STATUS_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $upload = $this->store->findUploadById($uploadId, false);
        if (! $upload instanceof VerificationUploadIntentRecord) {
            throw new AuthorizationDenied;
        }

        $this->assertOwnsCase($actor, $upload->caseId);

        return $this->project($upload);
    }

    private function deleteIngressQuietly(StoredObjectRef $ingress): void
    {
        try {
            $this->objects->deleteIfPresent($ingress);
        } catch (Throwable) {
        }
    }

    private function rejectLocked(
        TransactionContext $tx,
        VerificationUploadIntentRecord $upload,
        string $reason,
        \DateTimeImmutable $now,
    ): void {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $cleanup = $now->modify('+'.$this->policy->cleanupRejectedAfterSeconds().' seconds');
        $this->store->updateUpload($upload->id, $upload->version, [
            'state' => VerificationUploadState::Rejected->value,
            'rejection_reason' => $reason,
            'cleanup_eligible_at' => $cleanup->format('Y-m-d H:i:s.uP'),
            'version' => $upload->version + 1,
            'updated_at' => $stamp,
        ]);
        $this->audit->append(
            $tx,
            'verification.upload_rejected',
            'verification_upload_intent',
            $upload->id,
            [
                'reason_code' => $reason,
                'requirement_code' => $upload->requirementCode,
                'state' => VerificationUploadState::Rejected->value,
            ],
            null,
            'system',
        );
    }

    private function requireOwnDraftCase(ActorContext $actor, Identifier $caseId): VerificationCaseRecord
    {
        $case = $this->assertOwnsCase($actor, $caseId, true);
        if ($case->status !== VerificationCaseStatus::Draft) {
            throw new StateConflict;
        }

        return $case;
    }

    private function assertOwnsCase(ActorContext $actor, Identifier $caseId, bool $lock = false): VerificationCaseRecord
    {
        $case = $this->store->findCaseById($caseId, $lock);
        if (! $case instanceof VerificationCaseRecord) {
            throw new AuthorizationDenied;
        }

        $expectedType = $this->applicantTypeFor($actor);
        if ($case->applicantType !== $expectedType) {
            throw new AuthorizationDenied;
        }

        $applicantId = $this->applicantIdFor($actor, $expectedType);
        if (! $case->applicantId->equals($applicantId)) {
            throw new AuthorizationDenied;
        }

        return $case;
    }

    private function applicantTypeFor(ActorContext $actor): ApplicantType
    {
        return match ($actor->accountType) {
            AccountType::Doctor => ApplicantType::Doctor,
            AccountType::Pharmacy => ApplicantType::Pharmacy,
            default => throw new FeatureUnavailable,
        };
    }

    private function applicantIdFor(ActorContext $actor, ApplicantType $type): Identifier
    {
        return match ($type) {
            ApplicantType::Doctor => $this->requireDoctor($actor->userId)->doctorId,
            ApplicantType::Pharmacy => $this->requirePharmacy($actor->userId)->organizationId,
        };
    }

    private function requireDoctor(Identifier $userId): DoctorApplicantProjection
    {
        $doctor = $this->doctors->findByUserId($userId, true);
        if (! $doctor instanceof DoctorApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $doctor;
    }

    private function requirePharmacy(Identifier $userId): PharmacyApplicantProjection
    {
        $pharmacy = $this->pharmacies->findByUserId($userId, true);
        if (! $pharmacy instanceof PharmacyApplicantProjection) {
            throw new AuthorizationDenied;
        }

        return $pharmacy;
    }

    private function assertApplicantActor(ActorContext $actor): void
    {
        if (
            ! in_array($actor->accountType, [AccountType::Doctor, AccountType::Pharmacy], true)
            || ! $actor->status->canAccessBusinessEndpoints()
        ) {
            throw new FeatureUnavailable;
        }
    }

    private function project(VerificationUploadIntentRecord $row): VerificationUploadProjection
    {
        $utc = new DateTimeZone('UTC');

        return new VerificationUploadProjection(
            $row->id->value,
            $row->requirementCode,
            $row->state->value,
            $row->rejectionReason,
            $row->expiresAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $row->completedAt?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
        );
    }
}
