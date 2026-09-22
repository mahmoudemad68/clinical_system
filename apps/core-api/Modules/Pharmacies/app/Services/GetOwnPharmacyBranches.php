<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Illuminate\Http\Request;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyBranchPrivateProjection;
use Modules\Pharmacies\Support\PharmacyBranchProjector;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;
use Modules\Platform\Contracts\CursorSigner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\CursorScope;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\PaginationCursor;

final class GetOwnPharmacyBranches
{
    public const LIST_OPERATION = 'pharmacies.branches.list_own';

    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyBranchProjector $projector,
        private readonly PharmacyOwnerGuard $guard,
        private readonly CursorSigner $cursors,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{has_more: bool, next: string|null, limit: int}}
     */
    public function handle(ActorContext $actor, Identifier $organizationId, Request $request): array
    {
        $this->guard->requireOwnedOrganization($actor, $organizationId, Capabilities::PHARMACIES_BRANCH_READ_OWN);
        $this->assertKnownQueryKeys($request);
        $limit = $this->limit($request);
        $scope = CursorScope::of(
            self::LIST_OPERATION,
            $actor->userId->value,
            $organizationId->value,
            ['organization_id' => $organizationId->value],
            ['created_at', 'id'],
        );
        $after = $this->after($request, $scope);
        $rows = $this->store->listBranchesForOrganization($organizationId, $limit + 1, $after);
        $hasMore = count($rows) > $limit;
        $page = $hasMore ? array_slice($rows, 0, $limit) : $rows;
        $items = array_map(fn ($row): array => $this->projector->owner($row)->toArray(), $page);

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

    public function show(ActorContext $actor, Identifier $organizationId, Identifier $branchId): PharmacyBranchPrivateProjection
    {
        $resolved = $this->guard->requireBranchRead($actor, $organizationId, $branchId);

        return $resolved['mode'] === 'owner'
            ? $this->projector->owner($resolved['branch'])
            : $this->projector->operator($resolved['branch']);
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
            return (int) config('pharmacies_module.list_default_limit', 25);
        }

        $limit = (int) $raw;
        $max = (int) config('pharmacies_module.list_max_limit', 100);
        if ($limit < 1 || $limit > $max) {
            throw new InvalidValueObject('Limit is not valid.');
        }

        return $limit;
    }
}
