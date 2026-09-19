<?php

declare(strict_types=1);

namespace Modules\Verification\Console;

use Illuminate\Console\Command;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Verification\Enums\VerificationDocumentStatus;
use Modules\Verification\Enums\VerificationUploadState;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Support\VerificationPolicy;
use Modules\Verification\Support\VerificationUploadIntentRecord;

/**
 * Bounded, idempotent cleanup of expired or rejected quarantine objects.
 * Never deletes AVAILABLE or submitted evidence.
 */
final class ReconcileVerificationUploadsCommand extends Command
{
    protected $signature = 'verification:reconcile-uploads {--limit=50}';

    protected $description = 'Mark expired upload intents and delete eligible rejected quarantine objects.';

    public function handle(
        TransactionRunner $transactions,
        PostgresVerificationStore $store,
        StoreObject $objects,
        Clock $clock,
        VerificationPolicy $policy,
        AppendAuditEvent $audit,
    ): int {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $now = $clock->now();
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $eligible = $store->uploadsEligibleForCleanup($stamp, $limit);
        $toDelete = [];

        foreach ($eligible as $upload) {
            $transactions->run(function (TransactionContext $tx) use ($store, $audit, $policy, $upload, $now, $stamp, &$toDelete): void {
                $store->lockUpload($upload->id);
                $fresh = $store->findUploadById($upload->id, true);
                if (! $fresh instanceof VerificationUploadIntentRecord) {
                    return;
                }
                if ($fresh->state === VerificationUploadState::Available) {
                    return;
                }

                $document = $store->findDocumentByObjectId($fresh->objectId);
                if ($document !== null && $document->status === VerificationDocumentStatus::Available) {
                    return;
                }

                if (in_array($fresh->state, [VerificationUploadState::Requested, VerificationUploadState::Uploading], true)
                    && $fresh->expiresAt <= $now) {
                    $cleanup = $now->modify('+'.$policy->cleanupRejectedAfterSeconds().' seconds');
                    $store->updateUpload($fresh->id, $fresh->version, [
                        'state' => VerificationUploadState::Rejected->value,
                        'rejection_reason' => 'expired',
                        'cleanup_eligible_at' => $cleanup->format('Y-m-d H:i:s.uP'),
                        'version' => $fresh->version + 1,
                        'updated_at' => $stamp,
                    ]);
                    $toDelete[] = $fresh->storedRef();
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

                    return;
                }

                if ($fresh->state === VerificationUploadState::Rejected) {
                    $toDelete[] = $fresh->storedRef();
                    $audit->append(
                        $tx,
                        'verification.upload_cleanup',
                        'verification_upload_intent',
                        $fresh->id,
                        [
                            'reason_code' => 'rejected_object_removed',
                            'requirement_code' => $fresh->requirementCode,
                            'state' => VerificationUploadState::Rejected->value,
                        ],
                        null,
                        'system',
                    );
                }
            });
        }

        foreach ($toDelete as $ref) {
            $objects->deleteIfPresent($ref);
        }

        $this->info('Reconciled '.count($eligible).' upload intents.');

        return self::SUCCESS;
    }
}
