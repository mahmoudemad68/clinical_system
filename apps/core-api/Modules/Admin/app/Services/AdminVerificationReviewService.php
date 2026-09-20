<?php

declare(strict_types=1);

namespace Modules\Admin\Services;

use Illuminate\Http\Request;
use Modules\Admin\Support\AdminVerificationRules;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\CursorSigner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\CursorScope;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\PaginationCursor;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Support\ReviewerQueueFilters;
use Modules\Verification\Support\ReviewerQueuePage;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Admin HTTP facade. Delegates every verification write and query to
 * Verification public services. Does not query persistence.
 */
final class AdminVerificationReviewService
{
    public const QUEUE_OPERATION = 'admin.verification_cases.list';

    public function __construct(
        private readonly VerificationService $verification,
        private readonly VerificationDocumentService $documents,
        private readonly VerificationPolicy $policy,
        private readonly CursorSigner $cursors,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{has_more: bool, next: string|null, limit: int}}
     */
    public function queue(ActorContext $reviewer, Request $request): array
    {
        $query = AdminVerificationRules::queueQuery($request, $this->policy);
        $filters = new ReviewerQueueFilters(
            $query['assignment'],
            VerificationCaseType::from($query['case_type']),
            VerificationCaseStatus::from($query['status']),
            $query['limit'],
        );
        $scope = $this->queueScope($reviewer, $filters);
        $after = null;
        if ($query['cursor'] !== null) {
            $cursor = $this->cursors->decode($query['cursor'], $scope);
            $after = $this->positionFromCursor($cursor);
        }

        $page = $this->verification->listReviewQueue($reviewer, $filters, $after);

        return [
            'items' => array_map(
                static fn ($item): array => $item->toArray(),
                $page->items,
            ),
            'pagination' => [
                'has_more' => $page->hasMore,
                'next' => $this->encodeNext($page, $scope),
                'limit' => $page->limit,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(ActorContext $reviewer, Identifier $caseId): array
    {
        return $this->verification->reviewerCase($reviewer, $caseId)->toArray();
    }

    /**
     * @param  array{expected_case_version: int}  $input
     * @return array<string, mixed>
     */
    public function claim(ActorContext $reviewer, Identifier $caseId, array $input): array
    {
        return $this->verification->claimCase(
            $reviewer,
            $caseId,
            (int) $input['expected_case_version'],
        )->toArray();
    }

    /**
     * @param  array{decision: string, reason_code: string, expected_case_version: int, notes?: string|null}  $input
     * @return array<string, mixed>
     */
    public function decide(ActorContext $reviewer, Identifier $caseId, array $input): array
    {
        $notes = $input['notes'] ?? null;
        $notes = is_string($notes) && $notes !== '' ? $notes : null;

        return $this->verification->recordDecision(
            $reviewer,
            $caseId,
            (string) $input['decision'],
            (string) $input['reason_code'],
            (int) $input['expected_case_version'],
            $notes,
        )->toDecisionOutcome();
    }

    /**
     * @return array<string, mixed>
     */
    public function documentAccess(ActorContext $reviewer, Identifier $caseId, Identifier $documentId): array
    {
        return $this->documents->issueReviewerReadGrant($reviewer, $caseId, $documentId)->toArray();
    }

    private function queueScope(ActorContext $reviewer, ReviewerQueueFilters $filters): CursorScope
    {
        return CursorScope::of(
            self::QUEUE_OPERATION,
            $reviewer->userId->value,
            null,
            $filters->cursorFilterMap(),
            ReviewerQueueFilters::ORDERING,
        );
    }

    /**
     * @return array{submitted_at: string, case_id: string}
     */
    private function positionFromCursor(PaginationCursor $cursor): array
    {
        $submittedAt = $cursor->position['submitted_at'] ?? null;
        $caseId = $cursor->position['case_id'] ?? null;
        if (! is_string($submittedAt) || $submittedAt === '' || ! is_string($caseId) || $caseId === '') {
            throw new InvalidValueObject('Pagination cursor payload is malformed.');
        }

        return [
            'submitted_at' => $submittedAt,
            'case_id' => $caseId,
        ];
    }

    private function encodeNext(ReviewerQueuePage $page, CursorScope $scope): ?string
    {
        if (! $page->hasMore || $page->nextPosition === null) {
            return null;
        }

        return $this->cursors->encode(PaginationCursor::forScope($scope, $page->nextPosition));
    }
}
