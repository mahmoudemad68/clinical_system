<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientHandle;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Support\Identifier;

/**
 * Internal exact-match handle resolution. No public National ID HTTP lookup.
 */
final class ResolvePatientHandle
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPatientProfileStore $store,
        private readonly NationalIdProtector $protector,
        private readonly Authorize $authorize,
        private readonly AppendAuditEvent $audit,
    ) {}

    public function handle(ActorContext $actor, string $nationalId, Identifier $requestId): ?PatientHandle
    {
        $decision = $this->authorize->decide($actor, Capabilities::PATIENTS_UNLINKED_RESOLVE);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $parsed = $this->protector->nationalId($nationalId);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $parsed, $requestId): ?PatientHandle {
            $row = $this->store->findAuthoritativeByHmacs(
                $this->protector->nationalIdLookupHmacs($parsed),
                false,
            );

            $this->audit->append(
                $tx,
                'patient.handle_lookup',
                'user',
                $actor->userId,
                [
                    'reason_code' => 'exact_match',
                    'request_id' => $requestId->value,
                ],
                $actor->userId,
                'user',
            );

            if (! $row instanceof PatientProfileRecord) {
                return null;
            }

            return new PatientHandle($row->id, $row->status->value);
        });
    }
}
