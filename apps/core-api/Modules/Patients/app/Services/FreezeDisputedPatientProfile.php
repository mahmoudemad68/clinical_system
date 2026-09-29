<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Enums\PatientStatus;
use Modules\Patients\Events\PatientProfileDisputed;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Support\Identifier;

/**
 * PC-015: freeze a wrong bind or ownership dispute to status disputed.
 * No automatic reassignment. Operator capability is default-deny.
 */
final class FreezeDisputedPatientProfile
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPatientProfileStore $store,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
        private readonly RecordInboxNotification $inbox,
        private readonly Clock $clock,
    ) {}

    public function handle(ActorContext $actor, Identifier $patientId): PatientStatus
    {
        $decision = $this->authorize->decide($actor, Capabilities::PATIENTS_PROFILE_DISPUTE_FREEZE, 'patient_profile', $patientId);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $patientId): PatientStatus {
            $row = $this->store->findById($patientId, true);
            if (! $row instanceof PatientProfileRecord) {
                throw new AuthorizationDenied;
            }

            if ($row->status === PatientStatus::Merged) {
                throw new StateConflict('The patient profile cannot be frozen.');
            }

            if ($row->status === PatientStatus::Disputed) {
                return PatientStatus::Disputed;
            }

            $now = $this->clock->now();
            $affected = $this->store->freezeDisputed($row->id, $row->version, $now);
            if ($affected !== 1) {
                throw new StateConflict('The patient profile cannot be frozen.');
            }

            $this->audit->append(
                $tx,
                'patient.profile_disputed',
                'patient_profile',
                $row->id,
                ['reason_code' => 'dispute_freeze'],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new PatientProfileDisputed($row->id, $now));

            if ($row->userId instanceof Identifier) {
                $this->inbox->record('user', $row->userId->value, 'patient.profile_disputed', [
                    'reason_code' => 'dispute_freeze',
                ]);
            }

            return PatientStatus::Disputed;
        });
    }
}
