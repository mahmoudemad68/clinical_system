<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Enums\PatientSourceType;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientHandle;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
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
        private readonly CommitNewPatientProfile $commit,
        private readonly Authorize $authorize,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, array $input, Identifier $requestId): PatientHandle
    {
        $decision = $this->authorize->decide($actor, Capabilities::PATIENTS_UNLINKED_CREATE);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $nationalId = $this->protector->nationalId((string) $input['national_id']);
        $hmacs = $this->protector->nationalIdLookupHmacs($nationalId);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $input, $nationalId, $hmacs, $requestId): PatientHandle {
            $this->store->lockLookupIndex($this->protector->nationalIdHmac($nationalId));

            $existing = $this->store->findAuthoritativeByHmacs($hmacs, true);
            if ($existing instanceof PatientProfileRecord) {
                return new PatientHandle($existing->id, $existing->status->value);
            }

            try {
                $created = $this->commit->insert(
                    $tx,
                    null,
                    $nationalId,
                    $input,
                    'user',
                    $actor->userId,
                    PatientSourceType::WalkIn,
                    $requestId,
                    'unlinked_walk_in',
                );
            } catch (DuplicateIdentity) {
                $retry = $this->store->findAuthoritativeByHmacs($hmacs, true);
                assert($retry instanceof PatientProfileRecord);

                return new PatientHandle($retry->id, $retry->status->value);
            }

            return new PatientHandle($created->id, $created->status->value);
        });
    }
}
