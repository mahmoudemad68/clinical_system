<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * The query identity a pagination cursor is bound to.
 *
 * Two requests may use the same cursor only when they agree on all of:
 * the operation, the actor, the tenant, the filters, and the ordering. Changing
 * any of them makes a previously issued position meaningless, and in the case
 * of the actor or tenant, unsafe.
 *
 * The actor is included precisely so a cursor cannot be handed to a different
 * user and replayed against their result set.
 */
final readonly class CursorScope
{
    /**
     * @param  array<string, scalar|array<int, scalar>>  $filters
     * @param  array<int, string>  $ordering
     */
    private function __construct(
        private string $operationId,
        private string $actorKey,
        private ?string $tenantKey,
        private array $filters,
        private array $ordering,
    ) {}

    /**
     * @param  array<string, scalar|array<int, scalar>>  $filters
     * @param  array<int, string>  $ordering
     */
    public static function of(
        string $operationId,
        string $actorKey,
        ?string $tenantKey,
        array $filters,
        array $ordering,
    ): self {
        // Filters are sorted so that ?a=1&b=2 and ?b=2&a=1 produce one scope.
        // Ordering is not sorted: its sequence is semantic.
        ksort($filters);

        return new self($operationId, $actorKey, $tenantKey, $filters, $ordering);
    }

    /**
     * A stable, non-reversible fingerprint of this scope.
     *
     * Hashed rather than embedded so the cursor never discloses another user's
     * identifier, the tenant structure, or the filter values back to a client
     * that decodes it.
     */
    public function hash(): string
    {
        $canonical = json_encode(
            [
                'op' => $this->operationId,
                'actor' => $this->actorKey,
                'tenant' => $this->tenantKey,
                'filters' => $this->filters,
                'order' => $this->ordering,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return substr(hash('sha256', $canonical), 0, 32);
    }
}
