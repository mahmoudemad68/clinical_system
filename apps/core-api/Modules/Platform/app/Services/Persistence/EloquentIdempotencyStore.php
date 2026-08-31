<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdempotencyStore;
use Modules\Platform\Enums\IdempotencyState;
use Modules\Platform\Support\IdempotencyKey;
use Modules\Platform\Support\IdempotencyRecord;

/**
 * PostgreSQL-backed idempotency storage.
 *
 * The claim is the load-bearing part. It is an INSERT that relies on the unique
 * constraint on `key_hash`, not a SELECT followed by an INSERT: two concurrent
 * requests both find nothing on a read, both proceed, and both book the
 * appointment. Letting the database arbitrate is the only version that is
 * actually safe under concurrency, which is why the phase file puts the final
 * concurrency defence in database constraints rather than application checks.
 *
 * It uses ON CONFLICT DO NOTHING rather than catching a unique-violation
 * exception. That distinction is not stylistic. In PostgreSQL a statement that
 * raises inside a transaction aborts the whole transaction: every subsequent
 * statement fails with SQLSTATE 25P02 until rollback. An implementation that
 * catches the violation and then reads the existing row therefore works only
 * when no transaction is open, and poisons the caller's transaction when one
 * is — which is exactly the situation an application coordinator creates
 * (ADR 0004). ON CONFLICT never raises, so the claim is safe to call from
 * inside or outside a transaction.
 */
final class EloquentIdempotencyStore implements IdempotencyStore
{
    private const TABLE = 'idempotency_keys';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
        private readonly int $retentionHours = 72,
    ) {}

    public function claim(IdempotencyKey $key, string $requestHash): ?IdempotencyRecord
    {
        $now = $this->clock->now();

        $inserted = $this->insertIfAbsent($key, $requestHash, $now);

        if ($inserted) {
            // Won the claim. Caller proceeds with the operation.
            return null;
        }

        $existing = $this->find($key);

        if ($existing === null) {
            // The row vanished between the conflicting insert and the read,
            // which means retention purged it. Try once more; a second miss
            // would mean sustained contention with the purge job, and failing
            // to claim is safer than proceeding twice.
            return $this->insertIfAbsent($key, $requestHash, $now) ? null : $this->find($key);
        }

        // An expired record is not a valid outcome to replay. Reclaim it
        // rather than serving a stale result to a legitimate new intent.
        if ($existing->isExpired($now)) {
            $this->connection->table(self::TABLE)->where('key_hash', $key->storageKey)->delete();

            return $this->claim($key, $requestHash);
        }

        return $existing;
    }

    /**
     * Atomically insert the claim row, doing nothing if the key already exists.
     *
     * Returns true when this caller won the claim.
     *
     * ON CONFLICT DO NOTHING is what keeps this safe inside a transaction: it
     * reports the conflict through the affected-row count instead of raising,
     * so the caller's transaction is never aborted.
     */
    private function insertIfAbsent(
        IdempotencyKey $key,
        string $requestHash,
        DateTimeImmutable $now,
    ): bool {
        $affected = $this->connection->affectingStatement(
            'INSERT INTO '.self::TABLE.' ('
            .'key_hash, operation_id, request_hash, state, status_code, '
            .'response_reference, safe_error_class, created_at, updated_at, expires_at'
            .') VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?) '
            .'ON CONFLICT (key_hash) DO NOTHING',
            [
                $key->storageKey,
                $key->operationId,
                $requestHash,
                IdempotencyState::Processing->value,
                $this->format($now),
                $this->format($now),
                $this->format($now->modify(sprintf('+%d hours', $this->retentionHours))),
            ],
        );

        return $affected === 1;
    }

    public function find(IdempotencyKey $key): ?IdempotencyRecord
    {
        $row = $this->connection->table(self::TABLE)->where('key_hash', $key->storageKey)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function succeed(IdempotencyKey $key, int $statusCode, string $responseReference): void
    {
        $this->connection->table(self::TABLE)
            ->where('key_hash', $key->storageKey)
            ->update([
                'state' => IdempotencyState::Succeeded->value,
                'status_code' => $statusCode,
                'response_reference' => $responseReference,
                'safe_error_class' => null,
                'updated_at' => $this->format($this->clock->now()),
            ]);
    }

    public function failRetryable(IdempotencyKey $key, string $safeErrorClass): void
    {
        $this->connection->table(self::TABLE)
            ->where('key_hash', $key->storageKey)
            ->update([
                'state' => IdempotencyState::FailedRetryable->value,
                // The error class is a stable, non-sensitive label such as
                // "dependency_timeout". Never a provider message or a payload.
                'safe_error_class' => $safeErrorClass,
                'updated_at' => $this->format($this->clock->now()),
            ]);
    }

    public function release(IdempotencyKey $key): void
    {
        // Permanent validation or authorization failure: the operation never
        // happened, so the key must not carry a cached outcome, and the caller
        // must not be invited to retry something that will always be refused.
        $this->connection->table(self::TABLE)->where('key_hash', $key->storageKey)->delete();
    }

    public function purgeExpired(): int
    {
        return $this->connection->table(self::TABLE)
            ->where('expires_at', '<', $this->format($this->clock->now()))
            ->delete();
    }

    private function hydrate(object $row): IdempotencyRecord
    {
        return new IdempotencyRecord(
            requestHash: (string) $row->request_hash,
            state: IdempotencyState::from((string) $row->state),
            statusCode: $row->status_code === null ? null : (int) $row->status_code,
            responseReference: $row->response_reference === null ? null : (string) $row->response_reference,
            safeErrorClass: $row->safe_error_class === null ? null : (string) $row->safe_error_class,
            createdAt: new DateTimeImmutable((string) $row->created_at, new DateTimeZone('UTC')),
            expiresAt: new DateTimeImmutable((string) $row->expires_at, new DateTimeZone('UTC')),
        );
    }

    private function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
