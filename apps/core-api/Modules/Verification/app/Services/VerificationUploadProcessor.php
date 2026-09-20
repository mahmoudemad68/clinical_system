<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use DateTimeImmutable;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Enums\ScanOutcome;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\BoundedDocumentInspector;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\ScanVerdict;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Enums\VerificationUploadState;
use Modules\Verification\Services\Adapters\ProcessingTrustedDocumentEvidenceIssuer;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\VerificationCaseRecord;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationUploadIntentRecord;
use Throwable;

/**
 * Observe, validate, scan, and promote one quarantined upload. I/O happens
 * outside the promotion transaction. A scanner miss is retryable, never clean.
 */
final class VerificationUploadProcessor
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresVerificationStore $store,
        private readonly StoreObject $objects,
        private readonly ScanObject $scanner,
        private readonly BoundedDocumentInspector $inspector,
        private readonly ProcessingTrustedDocumentEvidenceIssuer $issuer,
        private readonly VerificationDocumentService $documents,
        private readonly VerificationPolicy $policy,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly PlatformMetrics $metrics,
    ) {}

    public function process(Identifier $uploadId): void
    {
        $claimed = $this->claimForValidation($uploadId);
        if (! $claimed instanceof VerificationUploadIntentRecord) {
            return;
        }

        $inspection = $this->inspect($claimed);
        if ($inspection['reason'] !== null) {
            $this->reject($claimed->id, $inspection['reason'], $inspection['observed']);

            return;
        }

        $this->markScanning($claimed->id, $inspection['observed']);
        $claimed = $this->reload($uploadId);
        if (! $claimed instanceof VerificationUploadIntentRecord || $claimed->state !== VerificationUploadState::Scanning) {
            return;
        }

        $original = $inspection['observed'];
        assert($original instanceof ObservedObject);
        $verdict = $this->scan($claimed, $original->sizeBytes);
        if ($verdict->isRetryable()) {
            $this->metric('scanner_unavailable', $claimed);
            throw new TransientProviderFailure('Malware scanner is unavailable.');
        }
        if ($verdict->outcome === ScanOutcome::Infected) {
            $this->reject($claimed->id, 'malware_detected', $inspection['observed'], $verdict);

            return;
        }
        if ($verdict->outcome !== ScanOutcome::Clean) {
            $this->reject($claimed->id, 'processing_failed', $inspection['observed'], $verdict);

            return;
        }

        $reobserved = $this->reobserve($claimed);
        $original = $inspection['observed'];
        assert($original instanceof ObservedObject);
        if (
            ! $reobserved->exists
            || $reobserved->sha256 !== $original->sha256
            || $reobserved->sizeBytes !== $original->sizeBytes
            || $reobserved->detectedMime !== $original->detectedMime
            || $this->providerVersionMismatch($original, $reobserved)
        ) {
            $this->reject($claimed->id, 'toctou_mismatch', $reobserved, $verdict);

            return;
        }

        $this->promote($claimed->id, $original, $verdict);
    }

    private function claimForValidation(Identifier $uploadId): ?VerificationUploadIntentRecord
    {
        return $this->transactions->run(function (TransactionContext $tx) use ($uploadId): ?VerificationUploadIntentRecord {
            $this->store->lockUpload($uploadId);
            $upload = $this->store->findUploadById($uploadId, true);
            if (! $upload instanceof VerificationUploadIntentRecord) {
                return null;
            }
            if ($upload->state->isTerminal()) {
                return null;
            }
            if (! $upload->state->isProcessable()) {
                return null;
            }

            $this->store->lockCase($upload->caseId);
            $case = $this->store->findCaseById($upload->caseId, false);
            $now = $this->clock->now();
            if ($upload->processingAttempts >= $this->policy->maxProcessingAttempts()) {
                $this->rejectLocked($tx, $upload, 'processing_failed', $now);

                return null;
            }
            if (! $case instanceof VerificationCaseRecord || $case->status !== VerificationCaseStatus::Draft) {
                $this->rejectLocked($tx, $upload, 'case_not_draft', $now);

                return null;
            }
            if ($upload->expiresAt <= $now && $upload->state === VerificationUploadState::Quarantined) {
                $this->rejectLocked($tx, $upload, 'expired', $now);

                return null;
            }

            $stamp = $now->format('Y-m-d H:i:s.uP');
            $this->store->updateUpload($upload->id, $upload->version, [
                'state' => VerificationUploadState::Validating->value,
                'processing_attempts' => $upload->processingAttempts + 1,
                'version' => $upload->version + 1,
                'updated_at' => $stamp,
            ]);
            $this->audit->append(
                $tx,
                'verification.upload_validation_started',
                'verification_upload_intent',
                $upload->id,
                [
                    'reason_code' => 'validation_started',
                    'requirement_code' => $upload->requirementCode,
                    'state' => VerificationUploadState::Validating->value,
                ],
                null,
                'system',
            );

            return $this->store->findUploadById($upload->id, false);
        });
    }

    /**
     * @return array{reason: string|null, observed: ObservedObject|null}
     */
    private function inspect(VerificationUploadIntentRecord $upload): array
    {
        $ref = $upload->canonicalRef();
        if (! $ref instanceof StoredObjectRef || ! $this->objects->exists($ref)) {
            return ['reason' => 'object_missing', 'observed' => null];
        }

        $stream = $this->objects->openStream($ref);
        try {
            $result = $this->inspector->inspect(
                $stream,
                min($upload->expectedSizeBytes, $this->policy->maxDocumentBytes()),
                $upload->declaredMediaType,
                $this->policy->allowedMimeTypes(),
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $observed = new ObservedObject(
            $result->sizeBytes > 0,
            $result->sizeBytes,
            $result->sha256,
            $result->detectedMime,
            $this->objects->providerVersionId($ref) ?? '',
        );

        if ($upload->expectedSha256 !== null && $upload->expectedSha256 !== $result->sha256) {
            return ['reason' => 'toctou_mismatch', 'observed' => $observed];
        }

        if (! $result->ok) {
            return ['reason' => $result->rejectionReason ?? 'processing_failed', 'observed' => $observed];
        }

        return ['reason' => null, 'observed' => $observed];
    }

    private function scan(VerificationUploadIntentRecord $upload, int $sizeBytes): ScanVerdict
    {
        $stream = $this->objects->openStream($upload->trustedRef());
        try {
            return $this->scanner->scanStream($stream, $sizeBytes);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function reobserve(VerificationUploadIntentRecord $upload): ObservedObject
    {
        try {
            return $this->objects->observe($upload->trustedRef(), $this->policy->maxDocumentBytes());
        } catch (Throwable) {
            return new ObservedObject(false, 0, '', null, '');
        }
    }

    private function markScanning(Identifier $uploadId, ?ObservedObject $observed): void
    {
        $this->transactions->run(function (TransactionContext $tx) use ($uploadId, $observed): void {
            $this->store->lockUpload($uploadId);
            $upload = $this->store->findUploadById($uploadId, true);
            if (! $upload instanceof VerificationUploadIntentRecord || $upload->state !== VerificationUploadState::Validating) {
                return;
            }
            $stamp = $this->clock->now()->format('Y-m-d H:i:s.uP');
            $attributes = [
                'state' => VerificationUploadState::Scanning->value,
                'version' => $upload->version + 1,
                'updated_at' => $stamp,
            ];
            if ($observed instanceof ObservedObject) {
                $attributes['object_version'] = $observed->objectVersion !== '' ? $observed->objectVersion : null;
                $attributes['observed_size_bytes'] = $observed->sizeBytes > 0 ? $observed->sizeBytes : null;
                $attributes['observed_sha256'] = $observed->sha256;
                $attributes['detected_mime'] = $observed->detectedMime;
            }
            $this->store->updateUpload($upload->id, $upload->version, $attributes);
            $this->audit->append(
                $tx,
                'verification.upload_scan_started',
                'verification_upload_intent',
                $upload->id,
                [
                    'reason_code' => 'scan_started',
                    'requirement_code' => $upload->requirementCode,
                    'state' => VerificationUploadState::Scanning->value,
                ],
                null,
                'system',
            );
        });
    }

    private function promote(Identifier $uploadId, ObservedObject $observed, ScanVerdict $verdict): void
    {
        $this->transactions->run(function (TransactionContext $tx) use ($uploadId, $observed, $verdict): void {
            $this->store->lockUpload($uploadId);
            $upload = $this->store->findUploadById($uploadId, true);
            if (! $upload instanceof VerificationUploadIntentRecord) {
                throw new StateConflict;
            }
            if ($upload->state->isTerminal()) {
                return;
            }

            $this->store->lockCase($upload->caseId);
            $case = $this->store->findCaseById($upload->caseId, false);
            if (! $case instanceof VerificationCaseRecord || $case->status !== VerificationCaseStatus::Draft) {
                $this->rejectLocked($tx, $upload, 'case_not_draft', $this->clock->now());

                return;
            }

            try {
                $fresh = $this->objects->observe($upload->trustedRef(), $this->policy->maxDocumentBytes());
            } catch (Throwable) {
                $this->rejectLocked($tx, $upload, 'toctou_mismatch', $this->clock->now(), $observed, $verdict);

                return;
            }
            if (
                ! $fresh->exists
                || $fresh->sha256 !== $observed->sha256
                || $fresh->sizeBytes !== $observed->sizeBytes
                || $this->providerVersionMismatch($observed, $fresh)
                || $fresh->detectedMime !== $observed->detectedMime
            ) {
                $this->rejectLocked($tx, $upload, 'toctou_mismatch', $this->clock->now(), $observed, $verdict);

                return;
            }

            $evidence = $this->issuer->issue([
                'case_id' => $upload->caseId->value,
                'requirement_code' => $upload->requirementCode,
                'object_id' => $upload->objectId->value,
                'sha256' => $observed->sha256,
                'detected_mime' => (string) $observed->detectedMime,
                'size_bytes' => $observed->sizeBytes,
                'scan_status' => VerificationDocumentScanStatus::Clean->value,
                'status' => VerificationDocumentStatus::Available->value,
                'upload_intent_id' => $upload->id->value,
            ]);

            $this->documents->registerValidatedMetadataWithin($tx, $evidence);

            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            $affected = $this->store->updateUpload($upload->id, $upload->version, [
                'state' => VerificationUploadState::Available->value,
                'object_version' => $observed->objectVersion !== '' ? $observed->objectVersion : null,
                'observed_size_bytes' => $observed->sizeBytes,
                'observed_sha256' => $observed->sha256,
                'detected_mime' => $observed->detectedMime,
                'scanner_identity' => $verdict->scannerIdentity,
                'scanner_version' => $verdict->scannerVersion,
                'available_at' => $stamp,
                'version' => $upload->version + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1) {
                throw new StateConflict;
            }

            $this->audit->append(
                $tx,
                'verification.upload_available',
                'verification_upload_intent',
                $upload->id,
                [
                    'reason_code' => 'available',
                    'requirement_code' => $upload->requirementCode,
                    'state' => VerificationUploadState::Available->value,
                    'scanner_identity' => $verdict->scannerIdentity,
                ],
                null,
                'system',
            );
            $this->metric('available', $upload, $observed->detectedMime);
        });
    }

    private function reject(
        Identifier $uploadId,
        string $reason,
        ?ObservedObject $observed = null,
        ?ScanVerdict $verdict = null,
    ): void {
        $this->transactions->run(function (TransactionContext $tx) use ($uploadId, $reason, $observed, $verdict): void {
            $this->store->lockUpload($uploadId);
            $upload = $this->store->findUploadById($uploadId, true);
            if (! $upload instanceof VerificationUploadIntentRecord || $upload->state->isTerminal()) {
                return;
            }
            $this->rejectLocked($tx, $upload, $reason, $this->clock->now(), $observed, $verdict);
        });
    }

    private function rejectLocked(
        TransactionContext $tx,
        VerificationUploadIntentRecord $upload,
        string $reason,
        DateTimeImmutable $now,
        ?ObservedObject $observed = null,
        ?ScanVerdict $verdict = null,
    ): void {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $cleanup = $now->modify('+'.$this->policy->cleanupRejectedAfterSeconds().' seconds');
        $attributes = [
            'state' => VerificationUploadState::Rejected->value,
            'rejection_reason' => $reason,
            'cleanup_eligible_at' => $cleanup->format('Y-m-d H:i:s.uP'),
            'version' => $upload->version + 1,
            'updated_at' => $stamp,
        ];
        if ($observed instanceof ObservedObject) {
            $attributes['observed_size_bytes'] = $observed->sizeBytes > 0 ? $observed->sizeBytes : null;
            $attributes['observed_sha256'] = $observed->sha256 !== '' ? $observed->sha256 : null;
            $attributes['detected_mime'] = $observed->detectedMime;
            $attributes['object_version'] = $observed->objectVersion !== '' ? $observed->objectVersion : null;
        }
        if ($verdict instanceof ScanVerdict) {
            $attributes['scanner_identity'] = $verdict->scannerIdentity;
            $attributes['scanner_version'] = $verdict->scannerVersion;
        }

        $this->store->updateUpload($upload->id, $upload->version, $attributes);
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
        $this->metric($reason, $upload, $observed?->detectedMime);
    }

    private function providerVersionMismatch(ObservedObject $left, ObservedObject $right): bool
    {
        if ($left->objectVersion === '' && $right->objectVersion === '') {
            return false;
        }

        return $left->objectVersion !== $right->objectVersion;
    }

    private function reload(Identifier $uploadId): ?VerificationUploadIntentRecord
    {
        return $this->store->findUploadById($uploadId, false);
    }

    private function metric(string $result, VerificationUploadIntentRecord $upload, ?string $detectedType = null): void
    {
        try {
            $this->metrics->increment('clinic_secure_file_results_total', [
                'result' => $result,
                'detected_type' => $detectedType ?? 'unknown',
                'requirement_code' => $upload->requirementCode,
            ]);
        } catch (Throwable) {
        }
    }
}
