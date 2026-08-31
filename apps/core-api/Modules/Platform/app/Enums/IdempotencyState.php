<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Lifecycle of an idempotency record (phase file, "Idempotency contract").
 *
 * The distinction between FailedRetryable and a permanent failure is the part
 * that is easy to get wrong. A permanent validation or authorization failure is
 * never cached as a successful business result, and it is not stored as
 * retryable either: the client must not be told to retry something that will
 * always be refused.
 */
enum IdempotencyState: string
{
    /**
     * Claimed and in flight. A concurrent duplicate waits briefly or receives
     * 409/202 with a polling reference. It never starts a second transition.
     */
    case Processing = 'PROCESSING';

    /**
     * Completed. The stored outcome is replayed for the same key and hash.
     */
    case Succeeded = 'SUCCEEDED';

    /**
     * Failed in a way that may succeed on retry, such as a dependency timeout.
     * The same key may be retried with the same request.
     */
    case FailedRetryable = 'FAILED_RETRYABLE';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * May a caller presenting the same key and the same request hash retry?
     */
    public function permitsRetry(): bool
    {
        return $this === self::FailedRetryable;
    }
}
