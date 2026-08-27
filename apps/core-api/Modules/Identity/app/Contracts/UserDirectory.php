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

    public function findByPhoneHmac(string $hmac): ?UserAccount;

    public function lockById(Identifier $userId): ?UserAccount;

    public function insertUser(UserAccount $user, string $phoneCipher, string $phoneHmac, int $keyVersion, DateTimeImmutable $now): void;

    public function insertNationalId(Identifier $id, Identifier $userId, string $cipher, string $hmac, int $keyVersion, DateTimeImmutable $now): void;

    public function markPhoneVerified(Identifier $userId, DateTimeImmutable $now): void;

    public function replacePassword(Identifier $userId, string $hash, int $credentialVersion, DateTimeImmutable $now): void;

    public function touchAuthenticated(Identifier $userId, DateTimeImmutable $now): void;

    public function updateStatus(Identifier $userId, AccountStatus $status, int $credentialVersion, DateTimeImmutable $now): void;

    public function countByAccountType(AccountType $type): int;
}
