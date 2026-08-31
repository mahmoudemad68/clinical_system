<?php

declare(strict_types=1);

namespace Modules\Audit\Exceptions;

use RuntimeException;

/**
 * Checkpoint signing or persistence failed. Messages must never include key material.
 */
final class AuditChainCheckpointFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'checkpoint_failed',
    ) {
        parent::__construct($message);
    }
}
