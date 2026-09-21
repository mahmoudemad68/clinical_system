<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

/**
 * Phone binding for a clinic staff invitation. Current HMAC + key version for
 * storage, plus every configured lookup HMAC for dedup. Never reports whether
 * an account exists. Callers must not log, audit, or return these bytes.
 */
final readonly class InvitationPhoneBinding
{
    /**
     * @param  list<string>  $lookupHmacs
     */
    public function __construct(
        public string $phoneLookupHmac,
        public int $hmacVersion,
        private array $lookupHmacs = [],
    ) {}

    /**
     * Unique lookup HMAC candidates in a stable byte order so concurrent
     * invite transactions acquire the same advisory locks during key rotation.
     *
     * @return list<string>
     */
    public function orderedLookupHmacs(): array
    {
        $unique = [];
        foreach ([$this->phoneLookupHmac, ...$this->lookupHmacs] as $hmac) {
            if ($hmac === '') {
                continue;
            }
            $unique[bin2hex($hmac)] = $hmac;
        }

        $values = array_values($unique);
        usort($values, static fn (string $left, string $right): int => strcmp(bin2hex($left), bin2hex($right)));

        return $values;
    }
}
