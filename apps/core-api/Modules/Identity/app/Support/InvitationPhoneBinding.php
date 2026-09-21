<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

/**
 * Phone binding for a clinic staff invitation. HMAC + key version only.
 * Never reports whether an account exists.
 */
final readonly class InvitationPhoneBinding
{
    public function __construct(
        public string $phoneLookupHmac,
        public int $hmacVersion,
    ) {}
}
