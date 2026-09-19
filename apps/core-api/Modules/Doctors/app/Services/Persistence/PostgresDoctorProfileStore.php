<?php

declare(strict_types=1);

namespace Modules\Doctors\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresDoctorProfileStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Serialize concurrent writers for one blind index.
     * Unique indexes remain the invariant; this lock reduces retry storms.
     */
    public function lockLookupIndex(string $hmac): void
    {
        $this->connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [bin2hex($hmac)]);
    }

    /**
     * @param  list<string>  $hmacs
     */
    public function findByNationalIdHmacs(array $hmacs, bool $lock): ?DoctorProfileRecord
    {
        return $this->findByBinaryColumn('national_id_lookup_hmac', $hmacs, $lock);
    }

    /**
     * @param  list<string>  $hmacs
     */
    public function findBySyndicateHmacs(array $hmacs, bool $lock): ?DoctorProfileRecord
    {
        return $this->findByBinaryColumn('syndicate_number_lookup_hmac', $hmacs, $lock);
    }

    public function findByUserId(Identifier $userId, bool $lock): ?DoctorProfileRecord
    {
        $query = $this->connection->table('doctor_profiles')->where('user_id', $userId->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function findById(Identifier $id, bool $lock): ?DoctorProfileRecord
    {
        $query = $this->connection->table('doctor_profiles')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insert(array $attributes): void
    {
        try {
            $this->connection->table('doctor_profiles')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVerificationState(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('doctor_profiles')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    /**
     * Irreversible tombstone of linked profile protected fields. user_id stays
     * attached to the closed identity; specialties are not subject-linked.
     */
    public function eraseLinkedProfiles(Identifier $userId, string $cipherTombstone, string $hmacTombstone, DateTimeImmutable $now): int
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $nid = $this->connection->table('doctor_profiles')
            ->where('user_id', $userId->value)
            ->update([
                'national_id_ciphertext' => BinaryColumn::bind($cipherTombstone),
                'national_id_lookup_hmac' => BinaryColumn::bind($hmacTombstone),
                'professional_display_name' => 'erased',
                'updated_at' => $stamp,
            ]);

        $this->connection->table('doctor_profiles')
            ->where('user_id', $userId->value)
            ->whereNotNull('syndicate_number_lookup_hmac')
            ->update([
                'syndicate_number_ciphertext' => BinaryColumn::bind($cipherTombstone),
                'syndicate_number_lookup_hmac' => BinaryColumn::bind($hmacTombstone),
                'updated_at' => $stamp,
            ]);

        return $nid;
    }

    public function countLinkedToUser(Identifier $userId): int
    {
        return $this->connection->table('doctor_profiles')
            ->where('user_id', $userId->value)
            ->count();
    }

    /**
     * @param  list<string>  $hmacs
     */
    private function findByBinaryColumn(string $column, array $hmacs, bool $lock): ?DoctorProfileRecord
    {
        if ($hmacs === []) {
            return null;
        }

        $query = $this->connection->table('doctor_profiles')
            ->where(function ($inner) use ($column, $hmacs): void {
                foreach ($hmacs as $hmac) {
                    $inner->orWhere($column, BinaryColumn::bind($hmac));
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    private function map(stdClass $row): DoctorProfileRecord
    {
        $syndicateCipher = BinaryColumn::asString($row->syndicate_number_ciphertext ?? '');
        $syndicateHmac = BinaryColumn::asString($row->syndicate_number_lookup_hmac ?? '');

        return new DoctorProfileRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->user_id),
            BinaryColumn::asString($row->national_id_ciphertext),
            BinaryColumn::asString($row->national_id_lookup_hmac),
            (int) $row->national_id_key_version,
            $syndicateCipher === '' ? null : $syndicateCipher,
            $syndicateHmac === '' ? null : $syndicateHmac,
            isset($row->syndicate_number_key_version) ? (int) $row->syndicate_number_key_version : null,
            Identifier::fromTrusted((string) $row->specialty_id),
            (string) $row->professional_display_name,
            DoctorVerificationStatus::from((string) $row->verification_status),
            DoctorPublicStatus::from((string) $row->public_status),
            (int) $row->version,
            self::timestamp($row->approved_at ?? null),
            self::timestamp($row->suspended_at ?? null),
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
