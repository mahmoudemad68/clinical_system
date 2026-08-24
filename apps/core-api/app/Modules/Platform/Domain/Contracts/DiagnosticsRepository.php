<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

/**
 * Persistence port for the Phase 00 diagnostics slice.
 *
 * A domain-owned interface with an Eloquent implementation in Infrastructure.
 * The handler originally wrote through Illuminate's ConnectionInterface
 * directly, which put a framework type in the Application layer; deptrac
 * flagged it, and the phase file is explicit that application handlers
 * coordinate transactions and ports rather than calling facades hidden from
 * tests.
 *
 * Implementations must write on the connection that owns the active
 * transaction, so the row and its outbox row commit together or not at all.
 */
interface DiagnosticsRepository
{
    /**
     * Insert one synthetic diagnostics record.
     *
     * Must run inside the caller's transaction.
     */
    public function record(
        Identifier $diagnosticsId,
        string $label,
        int $echoDelayMs,
        Identifier $outboxEventId,
        Identifier $correlationId,
        DateTimeImmutable $recordedAt,
    ): void;
}
