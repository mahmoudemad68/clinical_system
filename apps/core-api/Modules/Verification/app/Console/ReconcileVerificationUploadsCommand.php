<?php

declare(strict_types=1);

namespace Modules\Verification\Console;

use Illuminate\Console\Command;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Enums\VerificationUploadState;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationUploadIntentRecord;
use Throwable;

/**
 * Bounded, idempotent cleanup of expired or rejected quarantine objects
 * and of client-writable ingress after an AVAILABLE grant expires.
 * Never deletes AVAILABLE or submitted canonical evidence.
 *
 * External deletes run outside the database transaction. Completion is
 * marked only after the object store confirms absence, so a crash or
 * provider failure leaves the row retry-eligible.
 */
final class ReconcileVerificationUploadsCommand extends Command
{
    protected $signature = 'verification:reconcile-uploads {--limit=50}';

    protected $description = 'Expire upload intents, delete rejected objects, and delete expired AVAILABLE ingress only.';

    public function handle(
        TransactionRunner $transactions,
        PostgresVerificationStore $store,
        StoreObject $objects,
        Clock $clock,
        AppendAuditEvent $audit,
        VerificationPolicy $policy,
    ): int {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $now = $clock->now();
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $eligible = $store->uploadsEligibleForCleanup($stamp, $limit);

        foreach ($eligible as $upload) {
            if ($upload->state === VerificationUploadState::Available) {
                $this->reconcileAvailableIngress($transactions, $store, $objects, $audit, $upload, $now, $stamp);

                continue;
            }

            if (in_array($upload->state, [VerificationUploadState::Requested, VerificationUploadState::Uploading], true)) {
                $this->expireStaleUpload($transactions, $store, $audit, $policy, $upload, $now, $stamp);

                continue;
            }

            if ($upload->state === VerificationUploadState::Rejected) {
                $this->reconcileRejectedStorage($transactions, $store, $objects, $audit, $upload, $now, $stamp);
            }
        }

        $this->info('Reconciled '.count($eligible).' upload intents.');

        return self::SUCCESS;
    }

    private function reconcileAvailableIngress(
        TransactionRunner $transactions,
        PostgresVerificationStore $store,
        StoreObject $objects,
        AppendAuditEvent $audit,
        VerificationUploadIntentRecord $upload,
        \DateTimeImmutable $now,
        string $stamp,
    ): void {
        $ingress = $transactions->run(function () use ($store, $upload, $now): ?StoredObjectRef {
            $store->lockUpload($upload->id);
            $fresh = $store->findUploadById($upload->id, true);
            if (! $fresh instanceof VerificationUploadIntentRecord) {
                return null;
            }
            if ($fresh->state !== VerificationUploadState::Available
                || $fresh->expiresAt > $now
                || $fresh->cleanupCompletedAt !== null) {
                return null;
            }

            return $fresh->storedRef();
        });

        if (! $ingress instanceof StoredObjectRef) {
            return;
        }

        if (! $this->deleteConfirmed($objects, [$ingress])) {
            return;
        }

        $this->markCleanupCompleted(
            $transactions,
            $store,
            $audit,
            $upload,
            VerificationUploadState::Available,
            'available_ingress_removed',
            $stamp,
        );
    }

    private function expireStaleUpload(
        TransactionRunner $transactions,
        PostgresVerificationStore $store,
        AppendAuditEvent $audit,
        VerificationPolicy $policy,
        VerificationUploadIntentRecord $upload,
        \DateTimeImmutable $now,
        string $stamp,
    ): void {
        $cleanup = $now->modify('+'.$policy->cleanupRejectedAfterSeconds().' seconds');
        $transactions->run(function (TransactionContext $tx) use ($store, $audit, $upload, $now, $stamp, $cleanup): void {
            $store->lockUpload($upload->id);
            $fresh = $store->findUploadById($upload->id, true);
            if (! $fresh instanceof VerificationUploadIntentRecord) {
                return;
            }

            $document = $store->findDocumentByObjectId($fresh->objectId);
            if ($document !== null && $document->status === VerificationDocumentStatus::Available) {
                return;
            }

            if (! in_array($fresh->state, [VerificationUploadState::Requested, VerificationUploadState::Uploading], true)
                || $fresh->expiresAt > $now) {
                return;
            }

            $affected = $store->updateUpload($fresh->id, $fresh->version, [
                'state' => VerificationUploadState::Rejected->value,
                'rejection_reason' => 'expired',
                'cleanup_eligible_at' => $cleanup->format('Y-m-d H:i:s.uP'),
                'version' => $fresh->version + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1) {
                return;
            }

            $audit->append(
                $tx,
                'verification.upload_cleanup',
                'verification_upload_intent',
                $fresh->id,
                [
                    'reason_code' => 'expired',
                    'requirement_code' => $fresh->requirementCode,
                    'state' => VerificationUploadState::Rejected->value,
                ],
                null,
                'system',
            );
        });
    }

    private function reconcileRejectedStorage(
        TransactionRunner $transactions,
        PostgresVerificationStore $store,
        StoreObject $objects,
        AppendAuditEvent $audit,
        VerificationUploadIntentRecord $upload,
        \DateTimeImmutable $now,
        string $stamp,
    ): void {
        $refs = $transactions->run(function () use ($store, $upload, $now): ?array {
            $store->lockUpload($upload->id);
            $fresh = $store->findUploadById($upload->id, true);
            if (! $fresh instanceof VerificationUploadIntentRecord) {
                return null;
            }

            $document = $store->findDocumentByObjectId($fresh->objectId);
            if ($document !== null && $document->status === VerificationDocumentStatus::Available) {
                return null;
            }

            if ($fresh->state !== VerificationUploadState::Rejected
                || $fresh->cleanupCompletedAt !== null
                || $fresh->cleanupEligibleAt === null
                || $fresh->cleanupEligibleAt > $now) {
                return null;
            }

            return $fresh->storageRefs();
        });

        if (! is_array($refs) || $refs === []) {
            return;
        }

        if (! $this->deleteConfirmed($objects, $refs)) {
            return;
        }

        $this->markCleanupCompleted(
            $transactions,
            $store,
            $audit,
            $upload,
            VerificationUploadState::Rejected,
            'rejected_object_removed',
            $stamp,
        );
    }

    /**
     * @param  list<StoredObjectRef>  $refs
     */
    private function deleteConfirmed(StoreObject $objects, array $refs): bool
    {
        try {
            foreach ($refs as $ref) {
                $objects->deleteIfPresent($ref);
            }
            foreach ($refs as $ref) {
                if ($objects->exists($ref)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function markCleanupCompleted(
        TransactionRunner $transactions,
        PostgresVerificationStore $store,
        AppendAuditEvent $audit,
        VerificationUploadIntentRecord $upload,
        VerificationUploadState $expectedState,
        ?string $reasonCode,
        string $stamp,
    ): void {
        $transactions->run(function (TransactionContext $tx) use ($store, $audit, $upload, $expectedState, $reasonCode, $stamp): void {
            $store->lockUpload($upload->id);
            $fresh = $store->findUploadById($upload->id, true);
            if (! $fresh instanceof VerificationUploadIntentRecord) {
                return;
            }
            if ($fresh->state !== $expectedState || $fresh->cleanupCompletedAt !== null) {
                return;
            }

            $affected = $store->updateUpload($fresh->id, $fresh->version, [
                'cleanup_completed_at' => $stamp,
                'version' => $fresh->version + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected !== 1 || $reasonCode === null) {
                return;
            }

            $audit->append(
                $tx,
                'verification.upload_cleanup',
                'verification_upload_intent',
                $fresh->id,
                [
                    'reason_code' => $reasonCode,
                    'requirement_code' => $fresh->requirementCode,
                    'state' => $fresh->state->value,
                ],
                null,
                'system',
            );
        });
    }
}
