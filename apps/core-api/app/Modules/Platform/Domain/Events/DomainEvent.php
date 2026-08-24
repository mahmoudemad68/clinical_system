<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Events;

use App\Modules\Platform\Domain\ValueObjects\Classification;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

/**
 * A fact that already happened and is already committed.
 *
 * Implementations live in the module that owns the aggregate. The envelope
 * fields here match packages/contracts/events/envelope.schema.json, which is
 * the authoritative contract; this interface is the PHP-side expression of it.
 *
 * Payloads carry identifiers and the few non-sensitive facts a consumer needs.
 * A consumer wanting more re-reads it from the owning module under its own
 * authorization, so an event never becomes a way around a policy check.
 */
interface DomainEvent
{
    /**
     * Namespaced, past-tense type, for example "appointment.booked".
     *
     * Must match the pattern in the envelope schema and the filename of the
     * payload schema under packages/contracts/events/.
     */
    public function eventType(): string;

    /**
     * Payload schema version. Increments only on a breaking payload change.
     */
    public function schemaVersion(): int;

    public function aggregateType(): string;

    public function aggregateId(): Identifier;

    /**
     * When the fact became true, assigned inside the originating transaction.
     *
     * Not the publication time: the outbox row records that separately.
     */
    public function occurredAt(): DateTimeImmutable;

    /**
     * Highest classification present in the payload.
     *
     * Credentials never travel in an event, which is why Classification has no
     * Credential case reachable from here.
     */
    public function classification(): Classification;

    /**
     * The minimal, classified payload.
     *
     * Must validate against the schema named by eventType() and
     * schemaVersion(). The contract test asserts exactly that.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
