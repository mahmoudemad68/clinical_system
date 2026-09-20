<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresPharmacyOrganizationStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Serialize concurrent writers for one blind index or owner key.
     * Unique indexes remain the invariant; this lock reduces retry storms.
     */
    public function lockLookupIndex(string $hmacOrKey): void
    {
        $this->connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [bin2hex($hmacOrKey)]);
    }

    /**
     * @param  list<string>  $hmacs
     */
    public function findByRegistrationHmacs(array $hmacs, bool $lock): ?PharmacyOrganizationRecord
    {
        if ($hmacs === []) {
            return null;
        }

        $query = $this->connection->table('pharmacy_organizations')
            ->where(function ($inner) use ($hmacs): void {
                foreach ($hmacs as $hmac) {
                    $inner->orWhere('registration_lookup_hmac', BinaryColumn::bind($hmac));
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapOrganization($row) : null;
    }

    public function findOrganizationById(Identifier $id, bool $lock): ?PharmacyOrganizationRecord
    {
        $query = $this->connection->table('pharmacy_organizations')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapOrganization($row) : null;
    }

    /**
     * @param  list<string>  $ids
     * @return list<PharmacyOrganizationRecord>
     */
    public function findOrganizationsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->table('pharmacy_organizations')->whereIn('id', $ids)->get();
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapOrganization($row);
            }
        }

        return $out;
    }

    public function findOwnerMembershipByUserId(Identifier $userId, bool $lock): ?PharmacyMembershipRecord
    {
        $query = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->where('role', PharmacyMembershipRole::Owner->value)
            ->whereIn('status', [
                PharmacyMembershipStatus::Pending->value,
                PharmacyMembershipStatus::Active->value,
            ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    public function findOwnerMembershipByOrganizationId(Identifier $organizationId, bool $lock): ?PharmacyMembershipRecord
    {
        $query = $this->connection->table('pharmacy_memberships')
            ->where('organization_id', $organizationId->value)
            ->where('role', PharmacyMembershipRole::Owner->value)
            ->whereIn('status', [
                PharmacyMembershipStatus::Pending->value,
                PharmacyMembershipStatus::Active->value,
            ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    public function findInitialBranch(Identifier $organizationId, bool $lock): ?PharmacyBranchRecord
    {
        $query = $this->connection->table('pharmacy_branches')
            ->select('*')
            ->selectRaw('ST_Y(geography_point::geometry) AS latitude')
            ->selectRaw('ST_X(geography_point::geometry) AS longitude')
            ->where('organization_id', $organizationId->value)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapBranch($row) : null;
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, PharmacyBranchRecord>
     */
    public function findInitialBranchesByOrganizationIds(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $rows = $this->connection->table('pharmacy_branches')
            ->select('*')
            ->selectRaw('ST_Y(geography_point::geometry) AS latitude')
            ->selectRaw('ST_X(geography_point::geometry) AS longitude')
            ->whereIn('organization_id', $organizationIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $organizationId = (string) $row->organization_id;
            if (isset($out[$organizationId])) {
                continue;
            }
            $out[$organizationId] = $this->mapBranch($row);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertOrganization(array $attributes): void
    {
        try {
            $this->connection->table('pharmacy_organizations')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertBranch(array $attributes, float $longitude, float $latitude): void
    {
        $columns = array_keys($attributes);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO pharmacy_branches ('.implode(', ', $columns).', geography_point) VALUES ('
            .$placeholders.', ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)';

        try {
            $this->connection->insert($sql, [...array_values($attributes), $longitude, $latitude]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertMembership(array $attributes): void
    {
        try {
            $this->connection->table('pharmacy_memberships')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrganization(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('pharmacy_organizations')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBranch(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('pharmacy_branches')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMembership(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('pharmacy_memberships')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    /**
     * Irreversible tombstone of protected organization/branch fields owned by
     * the subject. Membership user_id stays attached.
     *
     * @return array<string, int>
     */
    public function eraseLinked(Identifier $userId, string $cipherTombstone, string $hmacTombstone, DateTimeImmutable $now): array
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $organizationIds = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->where('role', PharmacyMembershipRole::Owner->value)
            ->pluck('organization_id')
            ->all();

        $organizations = 0;
        $branches = 0;
        if ($organizationIds !== []) {
            $organizations = $this->connection->table('pharmacy_organizations')
                ->whereIn('id', $organizationIds)
                ->update([
                    'legal_name_ciphertext' => BinaryColumn::bind($cipherTombstone),
                    'registration_ciphertext' => BinaryColumn::bind($cipherTombstone),
                    'registration_lookup_hmac' => BinaryColumn::bind($hmacTombstone),
                    'public_name' => 'erased',
                    'updated_at' => $stamp,
                ]);

            $branches = $this->connection->table('pharmacy_branches')
                ->whereIn('organization_id', $organizationIds)
                ->update([
                    'address_ciphertext' => BinaryColumn::bind($cipherTombstone),
                    'phone_ciphertext' => BinaryColumn::bind($cipherTombstone),
                    'public_name' => 'erased',
                    'updated_at' => $stamp,
                ]);
        }

        $memberships = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->update([
                'updated_at' => $stamp,
            ]);

        return [
            'pharmacy_organizations' => $organizations,
            'pharmacy_branches' => $branches,
            'pharmacy_memberships' => $memberships,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function countLinkedToUser(Identifier $userId): array
    {
        $organizationIds = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->pluck('organization_id')
            ->all();

        $organizations = $organizationIds === []
            ? 0
            : $this->connection->table('pharmacy_organizations')->whereIn('id', $organizationIds)->count();
        $branches = $organizationIds === []
            ? 0
            : $this->connection->table('pharmacy_branches')->whereIn('organization_id', $organizationIds)->count();
        $memberships = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->count();

        return [
            'pharmacy_organizations' => $organizations,
            'pharmacy_branches' => $branches,
            'pharmacy_memberships' => $memberships,
        ];
    }

    private function mapOrganization(stdClass $row): PharmacyOrganizationRecord
    {
        return new PharmacyOrganizationRecord(
            Identifier::fromTrusted((string) $row->id),
            BinaryColumn::asString($row->legal_name_ciphertext),
            (int) $row->legal_name_key_version,
            (string) $row->public_name,
            BinaryColumn::asString($row->registration_ciphertext),
            BinaryColumn::asString($row->registration_lookup_hmac),
            (int) $row->registration_key_version,
            PharmacyVerificationStatus::from((string) $row->verification_status),
            PharmacyOrganizationStatus::from((string) $row->status),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapBranch(stdClass $row): PharmacyBranchRecord
    {
        return new PharmacyBranchRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->organization_id),
            (string) $row->public_name,
            BinaryColumn::asString($row->address_ciphertext),
            (int) $row->address_key_version,
            (string) $row->country_code,
            (float) $row->latitude,
            (float) $row->longitude,
            BinaryColumn::asString($row->phone_ciphertext),
            (int) $row->phone_key_version,
            PharmacyBranchStatus::from((string) $row->status),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapMembership(stdClass $row): PharmacyMembershipRecord
    {
        $branchId = isset($row->branch_id) && is_string($row->branch_id) && $row->branch_id !== ''
            ? Identifier::fromTrusted($row->branch_id)
            : null;

        return new PharmacyMembershipRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->organization_id),
            Identifier::fromTrusted((string) $row->user_id),
            $branchId,
            PharmacyMembershipRole::from((string) $row->role),
            PharmacyMembershipStatus::from((string) $row->status),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
