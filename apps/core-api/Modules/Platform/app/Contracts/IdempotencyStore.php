<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Modules\Platform\Support\IdempotencyKey;
use Modules\Platform\Support\IdempotencyRecord;

/**
 * Storage for the idempotency contract (phase file, "Idempotency contract").
 *
 * Applies to booking, check-in, consultation completion, prescription
 * finalization and amendment, purchase receipt, POS sale, cancellation,
 * return/refund, and external synchronization.
 *
 * The store never holds secrets or large clinical payloads: it holds a status
 * code and a reference to the response, so replaying a result does not turn the
 * idempotency table into a second copy of the medical record.
 */
interface IdempotencyStore
{
    /**
     * Atomically claim a key for processing.
     *
     * Returns null when this caller won the claim and should proceed. Returns
     * the existing record when the key is already known, and the caller must
     * then decide between replay, conflict, and wait based on the record's
     * state and request hash.
     *
     * The claim must be atomic against concurrent callers. A read followed by a
     * write is not sufficient: two requests arriving together would both see no
     * record and both proceed, which is precisely the double-booking this
     * contract exists to prevent. Implementations rely on a unique constraint.
     */
    public function claim(IdempotencyKey $key, string $requestHash): ?IdempotencyRecord;

    /**
     * Look up a record without claiming it.
     */
    public function find(IdempotencyKey $key): ?IdempotencyRecord;

    /**
     * Finalize a claimed key with its outcome.
     *
     * $responseReference points at the stored response; it is not the response
     * body itself. Storing the body here would duplicate clinical or financial
     * content into a table with a different retention and access profile.
     */
    public function succeed(IdempotencyKey $key, int $statusCode, string $responseReference): void;

    /**
     * Mark a claimed key as retryable after a transient failure.
     *
     * A permanent validation or authorization failure must not come through
     * here. It is released instead, so the caller is never invited to retry
     * something that will always be refused.
     */
    public function failRetryable(IdempotencyKey $key, string $safeErrorClass): void;

    /**
     * Release a claim so the key may be used again from scratch.
     *
     * Used for permanent failures: the operation never happened, so the key
     * should not carry a cached outcome.
     */
    public function release(IdempotencyKey $key): void;

    /**
     * Delete records past their retention window.
     *
     * Retention must exceed the maximum client retry and offline window for the
     * operation, or a legitimate offline retry would create a second effect.
     *
     * @return int number of records removed
     */
    public function purgeExpired(): int;
}
