<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Support\NationalId;
use Modules\Patients\Enums\PatientSourceType;
use Modules\Patients\Events\PatientProfileCreated;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientDemographicRevisionRecorder;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Patients\Support\PatientProfileRowFactory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Support\Identifier;

/**
 * Shared insert + creation provenance + profile-created side effects.
 * Callers own locking, uniqueness races, and account-link decisions.
 */
final class CommitNewPatientProfile
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly PatientProfileRowFactory $rows,
        private readonly PatientDemographicRevisionRecorder $revisions,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function insert(
        TransactionContext $tx,
        ?Identifier $userId,
        NationalId $nationalId,
        array $input,
        string $createdByType,
        Identifier $createdById,
        PatientSourceType $source,
        Identifier $requestId,
        string $auditReason,
    ): PatientProfileRecord {
        $id = $this->ids->next();
        $now = $this->clock->now();

        $this->store->insert($this->rows->attributes(
            $id,
            $userId,
            $nationalId,
            $input,
            $createdByType,
            $createdById,
            $now,
        ));

        $this->revisions->recordAcceptedFields(
            $id,
            $input,
            $createdByType,
            $createdById,
            $auditReason,
            $source->value,
            1,
            $requestId,
            $now,
        );

        $this->audit->append(
            $tx,
            'patient.profile_created',
            'patient_profile',
            $id,
            ['reason_code' => $auditReason, 'source_type' => $source->value],
            $createdById,
            $createdByType,
        );
        $tx->recordEvent(new PatientProfileCreated(
            $id,
            $userId,
            $source->value,
            $now,
        ));

        $created = $this->store->findById($id, false);
        assert($created instanceof PatientProfileRecord);

        return $created;
    }
}
