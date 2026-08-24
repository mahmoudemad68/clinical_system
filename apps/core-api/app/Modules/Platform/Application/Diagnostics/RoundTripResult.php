<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Diagnostics;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

/**
 * Outcome of the foundation slice.
 *
 * outboxEventId is returned so a test can assert that a forced duplicate
 * delivery of that exact event still produces exactly one effect.
 */
final readonly class RoundTripResult
{
    public function __construct(
        public Identifier $diagnosticsId,
        public Identifier $outboxEventId,
        public DateTimeImmutable $committedAt,
        public bool $idempotentReplay,
    ) {
    }

    /**
     * @return array{diagnostics_id: string, outbox_event_id: string, committed_at: string, idempotent_replay: bool}
     */
    public function toArray(): array
    {
        return [
            'diagnostics_id' => $this->diagnosticsId->value,
            'outbox_event_id' => $this->outboxEventId->value,
            'committed_at' => $this->committedAt->format(DATE_RFC3339),
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }

    public function asReplay(): self
    {
        return new self($this->diagnosticsId, $this->outboxEventId, $this->committedAt, true);
    }
}
