<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Identity\Support\ClaimCredential;
use Modules\Patients\Services\Persistence\PostgresPatientClaimStore;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Issues one clinic-bound Option B credential at new walk-in create.
 * Never retroactive. Plaintext is returned once and not stored.
 */
final class IssueWalkInClaimCredential
{
    public function __construct(
        private readonly PostgresPatientClaimStore $store,
        private readonly RandomBytes $random,
        private readonly HmacHasher $hmac,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    public function issue(Identifier $patientId): string
    {
        $now = $this->clock->now();
        $ttlDays = (int) config('identity.profile_claim.credential_ttl_days', 30);
        $expires = $now->modify(sprintf('+%d days', $ttlDays));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plaintext = ClaimCredential::generate($this->random);
            $hash = $this->hmac->digest(ClaimCredential::HMAC_PURPOSE, $plaintext);

            try {
                $this->store->insertCredential($this->ids->next(), $patientId, $hash, $expires, $now);

                return $plaintext;
            } catch (DuplicateIdentity) {
                continue;
            }
        }

        throw new DuplicateIdentity;
    }
}
