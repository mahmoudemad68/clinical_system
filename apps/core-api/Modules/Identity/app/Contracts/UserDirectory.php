<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

use DateTimeImmutable;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Support\Identifier;
use stdClass;

interface UserDirectory
{
    public function findById(Identifier $id): ?UserAccount;

    public function encryptedPhone(Identifier $id): ?string;

    public function findByPhoneHmac(string $hmac): ?UserAccount;

    /**
     * @param  list<string>  $hmacs
     */
    public function findByPhoneHmacs(array $hmacs): ?UserAccount;

    /**
     * @param  list<string>  $hmacs
     */
    public function nationalIdHmacsTaken(array $hmacs): bool;

    public function lockById(Identifier $userId): ?UserAccount;

    public function insertUser(
        UserAccount $user,
        string $phoneCipher,
        string $phoneHmac,
        int $encryptionVersion,
        int $hmacVersion,
        DateTimeImmutable $now,
    ): void;

    public function insertNationalId(
        Identifier $id,
        Identifier $userId,
        string $cipher,
        string $hmac,
        int $encryptionVersion,
        int $hmacVersion,
        DateTimeImmutable $now,
    ): void;

    public function markPhoneVerified(Identifier $userId, DateTimeImmutable $now): void;

    public function replacePassword(Identifier $userId, string $hash, int $credentialVersion, DateTimeImmutable $now): void;

    public function touchAuthenticated(Identifier $userId, DateTimeImmutable $now): void;

    public function updateStatus(Identifier $userId, AccountStatus $status, int $credentialVersion, DateTimeImmutable $now): void;

    public function countByAccountType(AccountType $type): int;

    public function phoneLookupHmac(Identifier $userId): ?string;

    public function nationalIdLookupHmac(Identifier $userId): ?string;

    public function tombstoneIdentity(
        Identifier $userId,
        string $phoneCipher,
        string $phoneHmac,
        string $passwordHash,
        int $credentialVersion,
        DateTimeImmutable $now,
    ): void;

    public function deleteNationalIds(Identifier $userId): int;

    public function deleteProfileLinks(Identifier $userId): int;

    public function countNationalIds(Identifier $userId): int;

    public function countProfileLinks(Identifier $userId): int;

    public function countPhonesNeedingRekey(int $encryptionVersion, int $hmacVersion): int;

    public function countNationalIdsNeedingRekey(int $encryptionVersion, int $hmacVersion): int;

    /**
     * @return list<stdClass>
     */
    public function phonesNeedingRekey(int $encryptionVersion, int $hmacVersion, int $limit): array;

    /**
     * @return list<stdClass>
     */
    public function nationalIdsNeedingRekey(int $encryptionVersion, int $hmacVersion, int $limit): array;

    public function rewritePhoneCrypto(
        Identifier $id,
        string $phoneCipher,
        string $phoneHmac,
        int $encryptionVersion,
        int $hmacVersion,
        DateTimeImmutable $now,
    ): void;

    public function rewriteNationalIdCrypto(
        Identifier $id,
        string $cipher,
        string $hmac,
        int $encryptionVersion,
        int $hmacVersion,
        DateTimeImmutable $now,
    ): void;
}
