<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Keyset page of reviewer queue items. Cursor encoding stays at the Admin
 * HTTP boundary.
 *
 * @phpstan-type Position array{submitted_at: string, case_id: string}
 */
final readonly class ReviewerQueuePage
{
    /**
     * @param  list<ReviewerQueueItemProjection>  $items
     * @param  Position|null  $nextPosition
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
        public ?array $nextPosition,
        public int $limit,
    ) {}
}
