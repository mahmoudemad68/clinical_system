<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdempotencyStore;
use App\Modules\Platform\Domain\ValueObjects\IdempotencyKey;
use App\Modules\Platform\Domain\ValueObjects\IdempotencyRecord;
use App\Modules\Platform\Domain\ValueObjects\IdempotencyState;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * PostgreSQL-backed idempotency storage.
 *
 * The claim is the load-bearing part. It is an INSERT that relies on the unique
 * constraint on `key_hash`, not a SELECT followed by an INSERT: two concurrent
 * requests both find nothing on a read, both proceed, and both book the
 * appointment. Letting the database arbitrate is the only version that is
 * actually safe under concurrency, which is why the phase file puts the final
 * concurrency defence in database constraints rather than application checks.
 */
final class EloquentIdempotencyStore implements IdempotencyStore
{
    private const TABLE = 'idempotency_keys';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
        private readonly int $retentionHours = 72,
    ) {
    }

    public function claim(IdempotencyKey $key, string $requestHash): ?IdempotencyRecord
    {
        $now = $this->clock->now();

        try {
            $this->connection->table(self::TABLE)->insert([
                'key_hash' => $key->storageKey,
                'operation_id' => $key->operationId,
                'request_hash' => $requestHash,
                'state' => IdempotencyState::Processing->value,
                'status_code' => null,
                'response_reference' => null,
                'safe_error_class' => null,
                'created_at' => $this->format($now),
                'updated_at' => $this->format($now),
                'expires_at' => $this->format($now->modify(sprintf('+%d hours', $this->retentionHours))),
            ]);

            // Won the claim. Caller proceeds with the operation.
            return null;
        } catch (UniqueConstraintViolationException) {
            // Someone else holds this key. Return what they stored so the
            // caller can decide between replay, conflict, and wait.
            $existing = $this->find($key);

            if ($existing === null) {
                // The row disappeared between the insert failing and the read,
                // which means retention purged it. Treat as claimable.
                return null;
            }

            // An expired record is not a valid outcome to replay. Reclaim it
            // rather than serving a stale result to a legitimate new intent.
            if ($existing->isExpired($now)) {
                $this->connection->table(self::TABLE)->where('key_hash', $key->storageKey)->delete();

                return $this->claim($key, $requestHash);
            }

            return $existing;
        }
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
