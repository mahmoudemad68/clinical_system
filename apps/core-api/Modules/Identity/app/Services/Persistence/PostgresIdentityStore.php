<?php

declare(strict_types=1);

namespace Modules\Identity\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresIdentityStore implements UserDirectory
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function findById(Identifier $id): ?UserAccount
    {
        $row = $this->connection->table('users')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function encryptedPhone(Identifier $id): ?string
    {
        $value = $this->connection->table('users')->where('id', $id->value)->value('phone_e164_encrypted');

        if ($value === null) {
            return null;
        }

        $cipher = BinaryColumn::asString($value);

        return $cipher === '' ? null : $cipher;
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
                'password_must_change' => $user->passwordMustChange,
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
            'password_must_change' => false,
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

    public function phoneLookupHmac(Identifier $userId): ?string
    {
        $value = $this->connection->table('users')->where('id', $userId->value)->value('phone_lookup_hmac');
        if ($value === null) {
            return null;
        }

        $hmac = BinaryColumn::asString($value);

        return $hmac === '' ? null : $hmac;
    }

    public function tombstoneIdentity(
        Identifier $userId,
        string $phoneCipher,
        string $phoneHmac,
        string $passwordHash,
        int $credentialVersion,
        DateTimeImmutable $now,
    ): void {
        $this->connection->table('users')->where('id', $userId->value)->update([
            'name' => 'erased',
            'phone_e164_encrypted' => BinaryColumn::bind($phoneCipher),
            'phone_lookup_hmac' => BinaryColumn::bind($phoneHmac),
            'password_hash' => $passwordHash,
            'status' => AccountStatus::Closed->value,
            'credential_version' => $credentialVersion,
            'phone_verified_at' => null,
            'last_authenticated_at' => null,
            'password_must_change' => false,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function deleteNationalIds(Identifier $userId): int
    {
        return $this->connection->table('identity_national_ids')->where('user_id', $userId->value)->delete();
    }

    public function deleteProfileLinks(Identifier $userId): int
    {
        return $this->connection->table('identity_profile_links')->where('user_id', $userId->value)->delete();
    }

    public function countNationalIds(Identifier $userId): int
    {
        return $this->connection->table('identity_national_ids')->where('user_id', $userId->value)->count();
    }

    public function countProfileLinks(Identifier $userId): int
    {
        return $this->connection->table('identity_profile_links')->where('user_id', $userId->value)->count();
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
            (bool) $row->password_must_change,
        );
    }
}
