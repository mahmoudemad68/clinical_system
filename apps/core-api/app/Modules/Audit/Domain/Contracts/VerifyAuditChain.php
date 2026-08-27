<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

/**
 * Recomputes the hash chain. Does not mutate rows.
 */
interface VerifyAuditChain
{
    /**
     * @return array{ok: bool, checked: int, first_bad_sequence: int|null}
     */
    public function verify(): array;
}
