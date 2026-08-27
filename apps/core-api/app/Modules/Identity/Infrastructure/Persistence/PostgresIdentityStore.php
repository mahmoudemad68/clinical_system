<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\AccountType;
use App\Modules\Identity\Domain\ValueObjects\LanguagePreference;
use App\Modules\Platform\Domain\Exceptions\DuplicateIdentity;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Infrastructure\Persistence\BinaryColumn;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use stdClass;

final class PostgresIdentityStore implements UserDirectory
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function findById(Identifier $id): ?UserAccount
    {
        $row = $this->connection->table('users')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function findByPhoneHmac(string $hmac): ?UserAccount
    {
        $row = $this->connection->table('users')
            ->where('phone_lookup_hmac', BinaryColumn::bind($hmac))
            ->whereIn('status', ['pending_phone', 'active', 'suspended', 'locked'])
            ->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function nationalIdHmacTaken(string $hmac): bool
    {
        return $this->connection->table('identity_national_ids')
            ->where('national_id_lookup_hmac', BinaryColumn::bind($hmac))
            ->exists();
    }

    public function insertUser(UserAccount $user, string $phoneCipher, string $phoneHmac, int $keyVersion, DateTimeImmutable $now): void
    {
        try {
            $this->connection->table('users')->insert([
                'id' => $user->id->value,
                'name' => $user->name,
                'phone_e164_encrypted' => BinaryColumn::bind($phoneCipher),
                'phone_lookup_hmac' => BinaryColumn::bind($phoneHmac),
                'phone_key_version' => $keyVersion,
                'password_hash' => $user->passwordHash,
                'account_type' => $user->accountType->value,
                'status' => $user->status->value,
                'language' => $user->language->value,
                'credential_version' => $user->credentialVersion,
                'phone_verified_at' => $user->phoneVerified ? $now->format('Y-m-d H:i:s.uP') : null,
                'last_authenticated_at' => null,
                'bootstrap_exempt' => $user->bootstrapExempt,
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    public function insertNationalId(Identifier $id, Identifier $userId, string $cipher, string $hmac, int $keyVersion, DateTimeImmutable $now): void
    {
        try {
            $this->connection->table('identity_national_ids')->insert([
                'id' => $id->value,
                'user_id' => $userId->value,
                'national_id_encrypted' => BinaryColumn::bind($cipher),
                'national_id_lookup_hmac' => BinaryColumn::bind($hmac),
                'key_version' => $keyVersion,
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->connection->table('users')->where('id', $userId->value)->delete();

            throw new DuplicateIdentity;
        }
    }

    public function markPhoneVerified(Identifier $userId, DateTimeImmutable $now): void
    {
        $this->connection->table('users')->where('id', $userId->value)->update([
            'phone_verified_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function replacePassword(Identifier $userId, string $hash, int $credentialVersion, DateTimeImmutable $now): void
    {
        $this->connection->table('users')->where('id', $userId->value)->update([
            'password_hash' => $hash,
            'credential_version' => $credentialVersion,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function touchAuthenticated(Identifier $userId, DateTimeImmutable $now): void
    {
        $this->connection->table('users')->where('id', $userId->value)->update([
            'last_authenticated_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function updateStatus(Identifier $userId, AccountStatus $status, int $credentialVersion, DateTimeImmutable $now): void
    {
        $this->connection->table('users')->where('id', $userId->value)->update([
            'status' => $status->value,
            'credential_version' => $credentialVersion,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function countByAccountType(AccountType $type): int
    {
        return $this->connection->table('users')->where('account_type', $type->value)->count();
    }

    public function lockById(Identifier $userId): ?UserAccount
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM users WHERE id = ? FOR UPDATE',
            [$userId->value],
        );

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    private function map(stdClass $row): UserAccount
    {
        return new UserAccount(
            Identifier::fromTrusted((string) $row->id),
            (string) $row->name,
            AccountType::from((string) $row->account_type),
            AccountStatus::from((string) $row->status),
            LanguagePreference::from((string) $row->language),
            (string) $row->password_hash,
            (int) $row->credential_version,
            $row->phone_verified_at !== null,
            (bool) $row->bootstrap_exempt,
        );
    }
}
