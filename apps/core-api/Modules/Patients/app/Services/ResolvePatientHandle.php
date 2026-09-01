<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Identity\Services\NationalIdProtector;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientHandle;
use Modules\Patients\Support\PatientProfileRecord;

/**
 * Internal exact-match handle resolution. No public National ID HTTP lookup.
 */
final class ResolvePatientHandle
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly NationalIdProtector $protector,
    ) {}

    public function handle(string $nationalId): ?PatientHandle
    {
        $parsed = $this->protector->nationalId($nationalId);
        $row = $this->store->findAuthoritativeByHmacs(
            $this->protector->nationalIdLookupHmacs($parsed),
            false,
        );

        if (! $row instanceof PatientProfileRecord) {
            return null;
        }

        return new PatientHandle($row->id, $row->status->value);
    }
}
