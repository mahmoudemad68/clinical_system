<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Support\InvitationPhoneBinding;
use Modules\Platform\Contracts\FieldEncryptor;
use Modules\Platform\Support\Identifier;

/**
 * Purpose-specific Identity public service for clinic staff invitations.
 * Clinics must not query users. This service never reports whether a phone
 * belongs to an existing account.
 */
final class InvitationRecipientService
{
    public function __construct(
        private readonly NationalIdProtector $protector,
        private readonly UserDirectory $identities,
        private readonly FieldEncryptor $encryptor,
    ) {}

    public function bindPhone(string $rawPhone): InvitationPhoneBinding
    {
        $phone = $this->protector->phone($rawPhone);

        return new InvitationPhoneBinding(
            $this->protector->phoneHmac($phone),
            $this->protector->hmacVersion(),
        );
    }

    public function actorMatchesInvitationHmac(Identifier $userId, string $storedHmac): bool
    {
        foreach ($this->subjectPhoneLookupHmacs($userId) as $hmac) {
            if (hash_equals($hmac, $storedHmac)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current stored HMAC plus every configured lookup key of the decrypted
     * phone, so invitation matching survives HMAC key rotation.
     *
     * @return list<string>
     */
    public function subjectPhoneLookupHmacs(Identifier $userId): array
    {
        $hmacs = [];
        $current = $this->identities->phoneLookupHmac($userId);
        if (is_string($current) && $current !== '') {
            $hmacs[] = $current;
        }

        $cipher = $this->identities->encryptedPhone($userId);
        if (! is_string($cipher) || $cipher === '') {
            return array_values(array_unique($hmacs));
        }

        try {
            $plain = $this->encryptor->decrypt('phone', $cipher);
            $phone = $this->protector->phone($plain);
            foreach ($this->protector->phoneLookupHmacs($phone) as $hmac) {
                $hmacs[] = $hmac;
            }
        } catch (\Throwable) {
            return array_values(array_unique($hmacs));
        }

        return array_values(array_unique($hmacs));
    }
}
