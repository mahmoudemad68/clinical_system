<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Domain\ValueObjects\NationalId;
use App\Modules\Identity\Domain\ValueObjects\PhoneE164;
use App\Modules\Platform\Domain\Contracts\FieldEncryptor;
use App\Modules\Platform\Domain\Contracts\HmacHasher;

final class NationalIdProtector
{
    public function __construct(
        private readonly FieldEncryptor $encryptor,
        private readonly HmacHasher $hmac,
        private readonly bool $allowSynthetic,
    ) {}

    public function phone(string $raw): PhoneE164
    {
        return PhoneE164::fromUntrusted($raw, $this->allowSynthetic);
    }

    public function nationalId(string $raw): NationalId
    {
        return NationalId::fromUntrusted($raw, $this->allowSynthetic);
    }

    public function phoneHmac(PhoneE164 $phone): string
    {
        return $this->hmac->digest('phone_lookup', $phone->e164());
    }

    public function nationalIdHmac(NationalId $nationalId): string
    {
        return $this->hmac->digest('national_id_lookup', $nationalId->canonical());
    }

    public function encryptPhone(PhoneE164 $phone): string
    {
        return $this->encryptor->encrypt('phone', $phone->e164());
    }

    public function encryptNationalId(NationalId $nationalId): string
    {
        return $this->encryptor->encrypt('national_id', $nationalId->canonical());
    }

    public function decryptPhone(string $envelope): string
    {
        return $this->encryptor->decrypt('phone', $envelope);
    }

    public function encryptSecret(string $purpose, string $plain): string
    {
        return $this->encryptor->encrypt($purpose, $plain);
    }

    public function decryptSecret(string $purpose, string $envelope): string
    {
        return $this->encryptor->decrypt($purpose, $envelope);
    }
}
