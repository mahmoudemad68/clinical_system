<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Illuminate\Http\Request;
use Modules\Access\Support\Capabilities;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicLocationPrivateProjection;
use Modules\Clinics\Support\ClinicLocationProjector;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\CursorSigner;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\CursorScope;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\PaginationCursor;

final class GetOwnClinicLocations
{
    public const LIST_OPERATION = 'clinics.locations.list_own';

    public function __construct(
        private readonly PostgresClinicStore $store,
        private readonly ClinicLocationProjector $projector,
        private readonly ClinicOwnerGuard $guard,
        private readonly CursorSigner $cursors,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{has_more: bool, next: string|null, limit: int}}
     */
    public function handle(ActorContext $actor, Request $request): array
    {
        if (! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $this->assertKnownQueryKeys($request);
        $limit = $this->limit($request);
        $filters = ['mode' => $actor->accountType->value];
        $scope = CursorScope::of(
            self::LIST_OPERATION,
            $actor->userId->value,
            null,
            $filters,
            ['created_at', 'id'],
        );
        $after = $this->after($request, $scope);

        if ($actor->accountType === AccountType::Doctor) {
            $owner = $this->guard->requireApprovedPrivilegedDoctor($actor, Capabilities::CLINICS_LOCATION_READ_OWN);
            $rows = $this->store->listLocationsForDoctor($owner->doctorId, $limit + 1, $after);
            $hasMore = count($rows) > $limit;
            $page = $hasMore ? array_slice($rows, 0, $limit) : $rows;
            $items = array_map(fn ($row): array => $this->projector->owner($row)->toArray(), $page);
        } elseif ($actor->accountType === AccountType::Secretary) {
            $this->guard->requireActiveSecretary($actor, Capabilities::CLINICS_LOCATION_READ_OWN);
            $rows = $this->store->listLocationsForActiveStaff($actor->userId, $limit + 1, $after);
            $hasMore = count($rows) > $limit;
            $page = $hasMore ? array_slice($rows, 0, $limit) : $rows;
            $items = array_map(fn ($row): array => $this->projector->staff($row)->toArray(), $page);
        } else {
            throw new FeatureUnavailable;
        }

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = $this->cursors->encode(PaginationCursor::forScope($scope, [
                'created_at' => $last->createdAt->format('Y-m-d H:i:s.uP'),
                'id' => $last->id->value,
            ]));
        }

        return [
            'items' => $items,
            'pagination' => [
                'has_more' => $hasMore,
                'next' => $next,
                'limit' => $limit,
            ],
        ];
    }

    public function show(ActorContext $actor, Identifier $locationId): ClinicLocationPrivateProjection
    {
        $resolved = $this->guard->requireLocationRead($actor, $locationId);
        $location = $resolved['location'];

        return $resolved['mode'] === 'owner'
            ? $this->projector->owner($location)
            : $this->projector->staff($location);
    }

    /**
     * @return array{created_at: string, id: string}|null
     */
    private function after(Request $request, CursorScope $scope): ?array
    {
        $cursor = $request->query('cursor');
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        $decoded = $this->cursors->decode($cursor, $scope);
        $createdAt = $decoded->position['created_at'] ?? null;
        $id = $decoded->position['id'] ?? null;
        if (! is_string($createdAt) || $createdAt === '' || ! is_string($id) || $id === '') {
            throw new InvalidValueObject('Pagination cursor payload is malformed.');
        }

        return ['created_at' => $createdAt, 'id' => $id];
    }

    private function assertKnownQueryKeys(Request $request): void
    {
        $unknown = array_values(array_diff(array_keys($request->query()), ['cursor', 'limit']));
        if ($unknown !== []) {
            throw new InvalidValueObject('Unexpected property.');
        }
    }

    private function limit(Request $request): int
    {
        $raw = $request->query('limit');
        if ($raw === null || $raw === '') {
            return (int) config('clinics_module.list_default_limit', 25);
        }

        $limit = (int) $raw;
        $max = (int) config('clinics_module.list_max_limit', 100);
        if ($limit < 1 || $limit > $max) {
            throw new InvalidValueObject('Limit is not valid.');
        }

        return $limit;
    }
}
