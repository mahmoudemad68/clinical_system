<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

/**
 * Machine-readable Phase-01 subject enumeration without secrets.
 *
 * @phpstan-type HoldingExport array{
 *     holding: string,
 *     action: string,
 *     notes: string,
 *     count: int|null
 * }
 */
final readonly class SubjectDataExport
{
    /**
     * @param  list<HoldingExport>  $holdings
     * @param  array<string, string>  $legalStatus
     * @param  list<string>  $operationalFollowThrough
     */
    public function __construct(
        public string $subjectId,
        public string $accountStatus,
        public string $accountType,
        public array $holdings,
        public array $legalStatus,
        public array $operationalFollowThrough,
    ) {}

    /**
     * @return array{
     *     subject_id: string,
     *     account_status: string,
     *     account_type: string,
     *     holdings: list<HoldingExport>,
     *     legal_status: array<string, string>,
     *     operational_follow_through: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'account_status' => $this->accountStatus,
            'account_type' => $this->accountType,
            'holdings' => $this->holdings,
            'legal_status' => $this->legalStatus,
            'operational_follow_through' => $this->operationalFollowThrough,
        ];
    }
}
