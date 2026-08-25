<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Outbox;

/**
 * Handles one published event type.
 *
 * Delivery is at-least-once, so every consumer must be idempotent on
 * `event_id` (ADR 0004). "Idempotent" here means idempotent in *effect*: a
 * second delivery may run, but it must not produce a second effect.
 *
 * A consumer that receives a schema version it does not support must reject
 * rather than guess. Guessing at an unknown payload shape is how a partially
 * understood event silently corrupts state.
 */
interface OutboxConsumer
{
    /**
     * Event type this consumer handles, for example "appointment.booked".
     */
    public function handles(): string;

    /**
     * Payload schema versions this consumer understands.
     *
     * During a dual-read migration a consumer accepts both N and N+1. An
     * event carrying anything else is dead-lettered with
     * UNSUPPORTED_SCHEMA_VERSION rather than acknowledged unprocessed.
     *
     * @return list<int>
     */
    public function supportedVersions(): array;

    /**
     * Process one event.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \Throwable to signal failure; the dispatcher decides retry vs dead-letter
     */
    public function consume(string $eventId, array $payload): void;
}
