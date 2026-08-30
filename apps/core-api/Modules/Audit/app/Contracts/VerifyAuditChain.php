<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

/**
 * Recomputes the hash chain and, when configured, checks external checkpoints.
 * Does not mutate rows.
 */
interface VerifyAuditChain
{
    /**
     * In-database SHA-256 chain only. Used before signing a checkpoint.
     *
     * @return array{ok: bool, checked: int, first_bad_sequence: int|null}
     */
    public function verifyDatabaseChain(): array;

    /**
     * @return array{
     *     ok: bool,
     *     checked: int,
     *     first_bad_sequence: int|null,
     *     checkpoint_ok: bool|null,
     *     checkpoint_reason: string|null
     * }
     */
    public function verify(): array;
}
