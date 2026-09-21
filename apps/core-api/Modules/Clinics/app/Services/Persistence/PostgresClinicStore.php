<?php

declare(strict_types=1);

namespace Modules\Clinics\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Clinics\Support\ClinicLocationRecord;
use Modules\Clinics\Support\ClinicStaffInvitationRecord;
use Modules\Clinics\Support\ClinicStaffMembershipRecord;
use Modules\Clinics\Support\ClinicStaffProfileRecord;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresClinicStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function lockLookupIndex(string $key): void
    {
        $this->connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [bin2hex($key)]);
    }

    public function findLocationById(Identifier $id, bool $lock): ?ClinicLocationRecord
    {
        $query = $this->locationQuery()->where('clinic_locations.id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->mapLocation($row) : null;
    }

    /**
     * @return list<ClinicLocationRecord>
     */
    public function listLocationsForDoctor(
        Identifier $doctorId,
        int $limit,
        ?array $after,
    ): array {
        $query = $this->locationQuery()
            ->where('clinic_locations.doctor_id', $doctorId->value)
            ->orderBy('clinic_locations.created_at')
            ->orderBy('clinic_locations.id')
            ->limit($limit);

        if (is_array($after)) {
            $query->where(function ($inner) use ($after): void {
                $inner->where('clinic_locations.created_at', '>', $after['created_at'])
                    ->orWhere(function ($same) use ($after): void {
                        $same->where('clinic_locations.created_at', $after['created_at'])
                            ->where('clinic_locations.id', '>', $after['id']);
                    });
            });
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapLocation($row);
            }
        }

        return $out;
    }

    /**
     * @return list<ClinicLocationRecord>
     */
    public function listLocationsForActiveStaff(
        Identifier $userId,
        int $limit,
        ?array $after,
    ): array {
        $query = $this->locationQuery()
            ->join('clinic_staff_memberships', 'clinic_staff_memberships.location_id', '=', 'clinic_locations.id')
            ->join('clinic_staff_profiles', 'clinic_staff_profiles.id', '=', 'clinic_staff_memberships.staff_profile_id')
            ->where('clinic_staff_profiles.user_id', $userId->value)
            ->where('clinic_staff_memberships.status', ClinicMembershipStatus::Active->value)
            ->orderBy('clinic_locations.created_at')
            ->orderBy('clinic_locations.id')
            ->limit($limit);

        if (is_array($after)) {
            $query->where(function ($inner) use ($after): void {
                $inner->where('clinic_locations.created_at', '>', $after['created_at'])
                    ->orWhere(function ($same) use ($after): void {
                        $same->where('clinic_locations.created_at', $after['created_at'])
                            ->where('clinic_locations.id', '>', $after['id']);
                    });
            });
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapLocation($row);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertLocation(array $attributes, float $longitude, float $latitude): void
    {
        $columns = array_keys($attributes);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO clinic_locations ('.implode(', ', $columns).', geography_point) VALUES ('
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
    public function updateLocation(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('clinic_locations')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateLocationGeography(
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
            'UPDATE clinic_locations SET '.implode(', ', $assignments)
            .' WHERE id = ? AND version = ?',
            $bindings,
        );
    }

    public function findStaffProfileByUserId(Identifier $userId, bool $lock): ?ClinicStaffProfileRecord
    {
        $query = $this->connection->table('clinic_staff_profiles')->where('user_id', $userId->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapStaffProfile($row) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertStaffProfile(array $attributes): void
    {
        try {
            $this->connection->table('clinic_staff_profiles')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @return list<ClinicStaffMembershipRecord>
     */
    public function listMembershipsForLocation(Identifier $locationId): array
    {
        $rows = $this->connection->table('clinic_staff_memberships')
            ->where('location_id', $locationId->value)
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

    public function findMembershipById(Identifier $id, bool $lock): ?ClinicStaffMembershipRecord
    {
        $query = $this->connection->table('clinic_staff_memberships')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    public function findActiveMembershipForUserAtLocation(Identifier $userId, Identifier $locationId): ?ClinicStaffMembershipRecord
    {
        $row = $this->connection->table('clinic_staff_memberships')
            ->join('clinic_staff_profiles', 'clinic_staff_profiles.id', '=', 'clinic_staff_memberships.staff_profile_id')
            ->where('clinic_staff_profiles.user_id', $userId->value)
            ->where('clinic_staff_memberships.location_id', $locationId->value)
            ->where('clinic_staff_memberships.status', ClinicMembershipStatus::Active->value)
            ->select('clinic_staff_memberships.*')
            ->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    public function findGrantMembershipForProfileAtLocation(
        Identifier $staffProfileId,
        Identifier $locationId,
        bool $lock,
    ): ?ClinicStaffMembershipRecord {
        $query = $this->connection->table('clinic_staff_memberships')
            ->where('staff_profile_id', $staffProfileId->value)
            ->where('location_id', $locationId->value)
            ->whereIn('status', [
                ClinicMembershipStatus::Pending->value,
                ClinicMembershipStatus::Active->value,
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapMembership($row) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertMembership(array $attributes): void
    {
        try {
            $this->connection->table('clinic_staff_memberships')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMembership(Identifier $id, int $expectedVersion, array $attributes): int
    {
        return $this->connection->table('clinic_staff_memberships')
            ->where('id', $id->value)
            ->where('version', $expectedVersion)
            ->update($attributes);
    }

    public function findInvitationById(Identifier $id, bool $lock): ?ClinicStaffInvitationRecord
    {
        $query = $this->connection->table('clinic_staff_invitations')->where('id', $id->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapInvitation($row) : null;
    }

    public function findPendingInvitation(Identifier $locationId, string $phoneHmac, bool $lock): ?ClinicStaffInvitationRecord
    {
        $query = $this->connection->table('clinic_staff_invitations')
            ->where('location_id', $locationId->value)
            ->where('target_phone_lookup_hmac', BinaryColumn::bind($phoneHmac))
            ->where('status', ClinicInvitationStatus::Pending->value);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->mapInvitation($row) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insertInvitation(array $attributes): void
    {
        try {
            $this->connection->table('clinic_staff_invitations')->insert($attributes);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateInvitation(Identifier $id, array $attributes): int
    {
        return $this->connection->table('clinic_staff_invitations')
            ->where('id', $id->value)
            ->update($attributes);
    }

    /**
     * Staff-side holdings for a user. Owner-side location counts are added by
     * the privacy adapter using Doctors public eligibility (doctor_id list).
     *
     * @return array<string, int>
     */
    public function countStaffHoldingsForUser(Identifier $userId): array
    {
        $staff = $this->findStaffProfileByUserId($userId, false);
        $memberships = 0;
        $staffLocations = 0;
        if ($staff instanceof ClinicStaffProfileRecord) {
            $memberships = $this->connection->table('clinic_staff_memberships')
                ->where('staff_profile_id', $staff->id->value)
                ->count();
            $staffLocations = (int) $this->connection->table('clinic_staff_memberships')
                ->where('staff_profile_id', $staff->id->value)
                ->distinct()
                ->count('location_id');
        }

        $inviterInvites = $this->connection->table('clinic_staff_invitations')
            ->where('inviter_user_id', $userId->value)
            ->count();

        return [
            'clinic_locations_via_membership' => $staffLocations,
            'clinic_staff_profiles' => $staff instanceof ClinicStaffProfileRecord ? 1 : 0,
            'clinic_staff_memberships' => $memberships,
            'clinic_staff_invitations' => $inviterInvites,
        ];
    }

    /**
     * Locations are owned by doctor_id, which Clinics learns from the Doctors
     * public service rather than querying doctor_profiles.
     *
     * @param  list<string>  $doctorIds
     */
    public function countLocationsByDoctorIds(array $doctorIds): int
    {
        if ($doctorIds === []) {
            return 0;
        }

        return $this->connection->table('clinic_locations')->whereIn('doctor_id', $doctorIds)->count();
    }

    /**
     * @param  list<string>  $doctorIds
     * @return list<ClinicLocationRecord>
     */
    public function listLocationsByDoctorIds(array $doctorIds, bool $lock): array
    {
        if ($doctorIds === []) {
            return [];
        }

        $query = $this->locationQuery()->whereIn('clinic_locations.doctor_id', $doctorIds)->orderBy('clinic_locations.id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapLocation($row);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $locationIds
     * @return list<ClinicStaffMembershipRecord>
     */
    public function listGrantMembershipsForLocations(array $locationIds, bool $lock): array
    {
        if ($locationIds === []) {
            return [];
        }

        $query = $this->connection->table('clinic_staff_memberships')
            ->whereIn('location_id', $locationIds)
            ->whereIn('status', [
                ClinicMembershipStatus::Pending->value,
                ClinicMembershipStatus::Active->value,
            ])
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapMembership($row);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $locationIds
     * @return list<ClinicStaffInvitationRecord>
     */
    public function listPendingInvitationsForLocations(array $locationIds, bool $lock): array
    {
        if ($locationIds === []) {
            return [];
        }

        $query = $this->connection->table('clinic_staff_invitations')
            ->whereIn('location_id', $locationIds)
            ->where('status', ClinicInvitationStatus::Pending->value)
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
     * @return list<ClinicStaffInvitationRecord>
     */
    public function listPendingInvitationsByHmacs(array $hmacs, bool $lock): array
    {
        if ($hmacs === []) {
            return [];
        }

        $query = $this->connection->table('clinic_staff_invitations')
            ->where('status', ClinicInvitationStatus::Pending->value)
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
     * @return list<ClinicStaffMembershipRecord>
     */
    public function listGrantMembershipsForUser(Identifier $userId, bool $lock): array
    {
        $profile = $this->findStaffProfileByUserId($userId, $lock);
        if (! $profile instanceof ClinicStaffProfileRecord) {
            return [];
        }

        $query = $this->connection->table('clinic_staff_memberships')
            ->where('staff_profile_id', $profile->id->value)
            ->whereIn('status', [
                ClinicMembershipStatus::Pending->value,
                ClinicMembershipStatus::Active->value,
            ])
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = $this->mapMembership($row);
            }
        }

        return $out;
    }

    /**
     * Representative indexed geography query used as inspectable PostGIS
     * evidence. Not a public search endpoint.
     */
    public function countIndexedWithinMeters(float $longitude, float $latitude, int $meters): int
    {
        $row = $this->connection->selectOne(
            'SELECT count(*)::int AS matching FROM clinic_locations WHERE ST_DWithin(geography_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$longitude, $latitude, $meters],
        );

        return is_object($row) ? (int) $row->matching : 0;
    }

    private function locationQuery()
    {
        return $this->connection->table('clinic_locations')
            ->select('clinic_locations.*')
            ->selectRaw('ST_Y(clinic_locations.geography_point::geometry) AS latitude')
            ->selectRaw('ST_X(clinic_locations.geography_point::geometry) AS longitude');
    }

    private function mapLocation(stdClass $row): ClinicLocationRecord
    {
        return new ClinicLocationRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->doctor_id),
            (string) $row->public_name,
            BinaryColumn::asString($row->address_ciphertext),
            (int) $row->address_key_version,
            (string) $row->country_code,
            (float) $row->latitude,
            (float) $row->longitude,
            ClinicLocationStatus::from((string) $row->status),
            (int) $row->version,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapStaffProfile(stdClass $row): ClinicStaffProfileRecord
    {
        return new ClinicStaffProfileRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->user_id),
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapMembership(stdClass $row): ClinicStaffMembershipRecord
    {
        $inviter = isset($row->inviter_user_id) && is_string($row->inviter_user_id) && $row->inviter_user_id !== ''
            ? Identifier::fromTrusted($row->inviter_user_id)
            : null;
        $revoker = isset($row->revoker_user_id) && is_string($row->revoker_user_id) && $row->revoker_user_id !== ''
            ? Identifier::fromTrusted($row->revoker_user_id)
            : null;

        return new ClinicStaffMembershipRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->staff_profile_id),
            Identifier::fromTrusted((string) $row->location_id),
            ClinicStaffRole::from((string) $row->role),
            ClinicMembershipStatus::from((string) $row->status),
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
            $inviter,
            $revoker,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function mapInvitation(stdClass $row): ClinicStaffInvitationRecord
    {
        return new ClinicStaffInvitationRecord(
            Identifier::fromTrusted((string) $row->id),
            Identifier::fromTrusted((string) $row->location_id),
            ClinicStaffRole::from((string) $row->role),
            ClinicInvitationStatus::from((string) $row->status),
            BinaryColumn::asString($row->target_phone_lookup_hmac),
            (int) $row->target_phone_key_version,
            new DateTimeImmutable((string) $row->expires_at),
            isset($row->consumed_at) && is_string($row->consumed_at) && $row->consumed_at !== ''
                ? new DateTimeImmutable($row->consumed_at)
                : null,
            Identifier::fromTrusted((string) $row->inviter_user_id),
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
