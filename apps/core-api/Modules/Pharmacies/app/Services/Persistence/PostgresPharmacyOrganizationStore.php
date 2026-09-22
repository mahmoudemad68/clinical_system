<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyInvitationStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Pharmacies\Support\PharmacyStaffInvitationRecord;
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
        $query = $this->branchQuery()
            ->where('pharmacy_branches.organization_id', $organizationId->value)
            ->orderBy('pharmacy_branches.created_at')
            ->orderBy('pharmacy_branches.id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapBranch($row) : null;
    }

    public function findBranchById(Identifier $id, bool $lock): ?PharmacyBranchRecord
    {
        $query = $this->branchQuery()->where('pharmacy_branches.id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapBranch($row) : null;
    }

    /**
     * @param  array{created_at: string, id: string}|null  $after
     * @return list<PharmacyBranchRecord>
     */
    public function listBranchesForOrganization(Identifier $organizationId, int $limit, ?array $after): array
    {
        $query = $this->branchQuery()
            ->where('pharmacy_branches.organization_id', $organizationId->value)
            ->orderBy('pharmacy_branches.created_at')
            ->orderBy('pharmacy_branches.id')
            ->limit($limit);

        if (is_array($after)) {
            $query->where(function ($inner) use ($after): void {
                $inner->where('pharmacy_branches.created_at', '>', $after['created_at'])
                    ->orWhere(function ($same) use ($after): void {
                        $same->where('pharmacy_branches.created_at', $after['created_at'])
                            ->where('pharmacy_branches.id', '>', $after['id']);
                    });
            });
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapBranch($row);
            }
        }

        return $out;
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

        $rows = $this->branchQuery()
            ->whereIn('pharmacy_branches.organization_id', $organizationIds)
            ->orderBy('pharmacy_branches.created_at')
            ->orderBy('pharmacy_branches.id')
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
    public function updateBranchGeography(
        Identifier $id,
        int $expectedVersion,
        array $attributes,
        float $longitude,
        float $latitude,
    ): int {
        $assignments = [];
        $bindings = [];
        foreach ($attributes as $column => $value) {
            $assignments[] = $column.' = ?';
            $bindings[] = $value;
        }
        $assignments[] = 'geography_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';
        $bindings[] = $longitude;
        $bindings[] = $latitude;
        $bindings[] = $id->value;
        $bindings[] = $expectedVersion;

        return $this->connection->update(
            'UPDATE pharmacy_branches SET '.implode(', ', $assignments)
            .' WHERE id = ? AND version = ?',
            $bindings,
        );
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

    public function findMembershipById(Identifier $id, bool $lock): ?PharmacyMembershipRecord
    {
        $query = $this->connection->table('pharmacy_memberships')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    public function findMembershipByUserAndOrganization(Identifier $userId, Identifier $organizationId, bool $lock): ?PharmacyMembershipRecord
    {
        $query = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->where('organization_id', $organizationId->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    public function findActiveBranchOperatorForUserAtBranch(
        Identifier $userId,
        Identifier $organizationId,
        Identifier $branchId,
    ): ?PharmacyMembershipRecord {
        $row = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->where('organization_id', $organizationId->value)
            ->where('branch_id', $branchId->value)
            ->where('role', PharmacyMembershipRole::BranchOperator->value)
            ->where('status', PharmacyMembershipStatus::Active->value)
            ->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    /**
     * @return list<PharmacyMembershipRecord>
     */
    public function listBranchOperatorMembershipsForBranch(Identifier $organizationId, Identifier $branchId): array
    {
        $rows = $this->connection->table('pharmacy_memberships')
            ->where('organization_id', $organizationId->value)
            ->where('branch_id', $branchId->value)
            ->where('role', PharmacyMembershipRole::BranchOperator->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapMembership($row);
            }
        }

        return $out;
    }

    public function findInvitationById(Identifier $id, bool $lock): ?PharmacyStaffInvitationRecord
    {
        $query = $this->connection->table('pharmacy_staff_invitations')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapInvitation($row) : null;
    }

    /**
     * @param  list<string>  $hmacs
     * @return list<PharmacyStaffInvitationRecord>
     */
    public function findPendingInvitationsForHmacs(Identifier $organizationId, Identifier $branchId, array $hmacs, bool $lock): array
    {
        if ($hmacs === []) {
            return [];
        }

        $query = $this->connection->table('pharmacy_staff_invitations')
            ->where('organization_id', $organizationId->value)
            ->where('branch_id', $branchId->value)
            ->where('status', PharmacyInvitationStatus::Pending->value)
            ->where(function ($inner) use ($hmacs): void {
                foreach ($hmacs as $hmac) {
                    $inner->orWhere('target_phone_lookup_hmac', BinaryColumn::bind($hmac));
                }
            })
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapInvitation($row);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertInvitation(array $attributes): void
    {
        try {
            $this->connection->table('pharmacy_staff_invitations')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateInvitation(Identifier $id, array $attributes): int
    {
        return $this->connection->table('pharmacy_staff_invitations')
            ->where('id', $id->value)
            ->update($attributes);
    }

    /**
     * @param  list<string>  $organizationIds
     * @return list<PharmacyStaffInvitationRecord>
     */
    public function listPendingInvitationsForOrganizations(array $organizationIds, bool $lock): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $query = $this->connection->table('pharmacy_staff_invitations')
            ->whereIn('organization_id', $organizationIds)
            ->where('status', PharmacyInvitationStatus::Pending->value)
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapInvitation($row);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $hmacs
     * @return list<PharmacyStaffInvitationRecord>
     */
    public function listPendingInvitationsByHmacs(array $hmacs, bool $lock): array
    {
        if ($hmacs === []) {
            return [];
        }

        $query = $this->connection->table('pharmacy_staff_invitations')
            ->where('status', PharmacyInvitationStatus::Pending->value)
            ->where(function ($inner) use ($hmacs): void {
                foreach ($hmacs as $hmac) {
                    $inner->orWhere('target_phone_lookup_hmac', BinaryColumn::bind($hmac));
                }
            })
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapInvitation($row);
            }
        }

        return $out;
    }

    /**
     * Distinct invitation rows where the subject is the inviter and/or the
     * invitation target HMAC matches any configured lookup HMAC for the
     * subject. Does not return phone, HMAC, or invitation secret material.
     *
     * @param  list<string>  $hmacs
     */
    public function countInvitationsLinkedToSubject(Identifier $userId, array $hmacs): int
    {
        return (int) $this->connection->table('pharmacy_staff_invitations')
            ->where(function ($outer) use ($userId, $hmacs): void {
                $outer->where('inviter_user_id', $userId->value);
                if ($hmacs === []) {
                    return;
                }
                $outer->orWhere(function ($targets) use ($hmacs): void {
                    foreach ($hmacs as $hmac) {
                        $targets->orWhere('target_phone_lookup_hmac', BinaryColumn::bind($hmac));
                    }
                });
            })
            ->distinct()
            ->count('id');
    }

    /**
     * Representative indexed geography query used as inspectable PostGIS
     * evidence. Not a public search endpoint.
     */
    public function countIndexedWithinMeters(float $longitude, float $latitude, int $meters): int
    {
        $row = $this->connection->selectOne(
            'SELECT count(*)::int AS matching FROM pharmacy_branches WHERE ST_DWithin(geography_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$longitude, $latitude, $meters],
        );

        return is_object($row) ? (int) $row->matching : 0;
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

    /**
     * @return list<string>
     */
    public function ownedOrganizationIdsForUser(Identifier $userId, bool $lock): array
    {
        $query = $this->connection->table('pharmacy_memberships')
            ->where('user_id', $userId->value)
            ->where('role', PharmacyMembershipRole::Owner->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $query->pluck('organization_id')->all()));
    }

    private function branchQuery(): Builder
    {
        return $this->connection->table('pharmacy_branches')
            ->select('pharmacy_branches.*')
            ->selectRaw('ST_Y(pharmacy_branches.geography_point::geometry) AS latitude')
            ->selectRaw('ST_X(pharmacy_branches.geography_point::geometry) AS longitude');
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
            isset($row->invited_at) && is_string($row->invited_at) && $row->invited_at !== ''
                ? new DateTimeImmutable($row->invited_at)
                : null,
            isset($row->accepted_at) && is_string($row->accepted_at) && $row->accepted_at !== ''
                ? new DateTimeImmutable($row->accepted_at)
                : null,
            isset($row->revoked_at) && is_string($row->revoked_at) && $row->revoked_at !== ''
                ? new DateTimeImmutable($row->revoked_at)
                : null,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapInvitation(stdClass $row): PharmacyStaffInvitationRecord
    {
        return new PharmacyStaffInvitationRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->organization_id),
            Identifier::fromTrusted((string) $row->branch_id),
            PharmacyMembershipRole::from((string) $row->role),
            PharmacyInvitationStatus::from((string) $row->status),
            BinaryColumn::asString($row->target_phone_lookup_hmac),
            (int) $row->target_phone_key_version,
            new DateTimeImmutable((string) $row->expires_at),
            new DateTimeImmutable((string) $row->invited_at),
            isset($row->accepted_at) && is_string($row->accepted_at) && $row->accepted_at !== ''
                ? new DateTimeImmutable($row->accepted_at)
                : null,
            isset($row->consumed_at) && is_string($row->consumed_at) && $row->consumed_at !== ''
                ? new DateTimeImmutable($row->consumed_at)
                : null,
            Identifier::fromTrusted((string) $row->inviter_user_id),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
