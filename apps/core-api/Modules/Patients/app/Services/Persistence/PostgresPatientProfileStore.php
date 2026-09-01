<?php

declare(strict_types=1);

namespace Modules\Patients\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Patients\Enums\PatientStatus;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresPatientProfileStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Serialize concurrent writers for one National ID blind index.
     * The unique index remains the invariant; this lock reduces retry storms.
     */
    public function lockLookupIndex(string $hmac): void
    {
        $this->connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [bin2hex($hmac)]);
    }

    /**
     * @param  list<string>  $hmacs
     */
    public function findAuthoritativeByHmacs(array $hmacs, bool $lock): ?PatientProfileRecord
    {
        if ($hmacs === []) {
            return null;
        }

        $query = $this->connection->table('patient_profiles')
            ->where('status', '<>', PatientStatus::Merged->value)
            ->where(function ($inner) use ($hmacs): void {
                foreach ($hmacs as $hmac) {
                    $inner->orWhere('national_id_lookup_hmac', BinaryColumn::bind($hmac));
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function findByUserId(Identifier $userId, bool $lock): ?PatientProfileRecord
    {
        $query = $this->connection->table('patient_profiles')->where('user_id', $userId->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function findById(Identifier $id, bool $lock): ?PatientProfileRecord
    {
        $query = $this->connection->table('patient_profiles')->where('id', $id->value);
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
            $this->connection->table('patient_profiles')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateDemographics(Identifier $id, int $expectedVersion, array $changes): int
    {
        return $this->connection->table('patient_profiles')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($changes);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertRevision(array $row): void
    {
        $this->connection->table('patient_demographic_revisions')->insert($row);
    }

    /**
     * Irreversible tombstone of linked profile protected fields. Revisions stay
     * append-only (they never stored National ID). Name ciphertext in
     * historical revision rows is a documented residual.
     */
    public function eraseLinkedProfiles(Identifier $userId, string $cipherTombstone, string $hmacTombstone, DateTimeImmutable $now): int
    {
        return $this->connection->table('patient_profiles')
            ->where('user_id', $userId->value)
            ->update([
                'user_id' => null,
                'national_id_ciphertext' => BinaryColumn::bind($cipherTombstone),
                'national_id_lookup_hmac' => BinaryColumn::bind($hmacTombstone),
                'full_name_ciphertext' => BinaryColumn::bind($cipherTombstone),
                'status' => PatientStatus::Archived->value,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function countAuthoritative(): int
    {
        return $this->connection->table('patient_profiles')
            ->where('status', '<>', PatientStatus::Merged->value)
            ->count();
    }

    private function map(stdClass $row): PatientProfileRecord
    {
        $userId = isset($row->user_id) && is_string($row->user_id) && $row->user_id !== ''
            ? Identifier::fromTrusted($row->user_id)
            : null;

        $dob = $row->date_of_birth ?? null;
        $dobString = is_string($dob) && $dob !== '' ? substr($dob, 0, 10) : null;

        return new PatientProfileRecord(
            Identifier::fromTrusted((string) $row->id),
            $userId,
            BinaryColumn::asString($row->national_id_ciphertext),
            BinaryColumn::asString($row->national_id_lookup_hmac),
            (int) $row->national_id_key_version,
            BinaryColumn::asString($row->full_name_ciphertext),
            (string) $row->gender,
            $dobString,
            self::decimal($row->height_cm ?? null),
            self::decimal($row->weight_kg ?? null),
            isset($row->marital_status) && is_string($row->marital_status) ? $row->marital_status : null,
            isset($row->blood_type) && is_string($row->blood_type) ? $row->blood_type : null,
            PatientStatus::from((string) $row->status),
            (string) $row->created_by_type,
            Identifier::fromTrusted((string) $row->created_by_id),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (string) $value : null;
    }
}
