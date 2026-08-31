<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

use DateTimeImmutable;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Support\Identifier;

interface UserDirectory
{
    public function findById(Identifier $id): ?UserAccount;

    public function encryptedPhone(Identifier $id): ?string;

    public function findByPhoneHmac(string $hmac): ?UserAccount;

    public function lockById(Identifier $userId): ?UserAccount;

    public function insertUser(UserAccount $user, string $phoneCipher, string $phoneHmac, int $keyVersion, DateTimeImmutable $now): void;

    public function insertNationalId(Identifier $id, Identifier $userId, string $cipher, string $hmac, int $keyVersion, DateTimeImmutable $now): void;

    public function markPhoneVerified(Identifier $userId, DateTimeImmutable $now): void;

    public function replacePassword(Identifier $userId, string $hash, int $credentialVersion, DateTimeImmutable $now): void;

    public function touchAuthenticated(Identifier $userId, DateTimeImmutable $now): void;

    public function updateStatus(Identifier $userId, AccountStatus $status, int $credentialVersion, DateTimeImmutable $now): void;

    public function countByAccountType(AccountType $type): int;

    public function phoneLookupHmac(Identifier $userId): ?string;

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
}
