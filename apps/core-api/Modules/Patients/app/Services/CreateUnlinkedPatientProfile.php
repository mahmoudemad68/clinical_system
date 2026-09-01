<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Patients\Enums\PatientSourceType;
use Modules\Patients\Events\PatientProfileCreated;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientHandle;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Patients\Support\PatientProfileRowFactory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Internal unlinked profile create for future Phase 03 booking. No HTTP.
 */
final class CreateUnlinkedPatientProfile
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPatientProfileStore $store,
        private readonly NationalIdProtector $protector,
        private readonly PatientProfileRowFactory $rows,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, string $createdByType, Identifier $createdById): PatientHandle
    {
        $nationalId = $this->protector->nationalId((string) $input['national_id']);
        $hmacs = $this->protector->nationalIdLookupHmacs($nationalId);

        return $this->transactions->run(function (TransactionContext $tx) use ($input, $nationalId, $hmacs, $createdByType, $createdById): PatientHandle {
            $this->store->lockLookupIndex($this->protector->nationalIdHmac($nationalId));

            $existing = $this->store->findAuthoritativeByHmacs($hmacs, true);
            if ($existing instanceof PatientProfileRecord) {
                return new PatientHandle($existing->id, $existing->status->value);
            }

            $id = $this->ids->next();
            $now = $this->clock->now();

            try {
                $this->store->insert($this->rows->attributes(
                    $id,
                    null,
                    $nationalId,
                    $input,
                    $createdByType,
                    $createdById,
                    $now,
                ));
            } catch (DuplicateIdentity) {
                $retry = $this->store->findAuthoritativeByHmacs($hmacs, true);
                assert($retry instanceof PatientProfileRecord);

                return new PatientHandle($retry->id, $retry->status->value);
            }

            $this->audit->append(
                $tx,
                'patient.profile_created',
                'patient_profile',
                $id,
                ['reason_code' => 'unlinked_walk_in', 'source_type' => PatientSourceType::WalkIn->value],
                $createdById,
                $createdByType,
            );
            $tx->recordEvent(new PatientProfileCreated(
                $id,
                null,
                PatientSourceType::WalkIn->value,
                $now,
            ));

            return new PatientHandle($id, 'active');
        });
    }
}
