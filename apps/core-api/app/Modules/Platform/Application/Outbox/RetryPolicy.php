<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Outbox;

/**
 * Capped exponential backoff with jitter (ADR 0004, step 5).
 *
 * Three properties, each for a specific failure mode:
 *
 *   exponential  a dependency that is down stays down for a while; retrying
 *                every second just adds load to something already struggling.
 *
 *   capped       without a cap, a long outage pushes the next attempt weeks
 *                out and the event effectively disappears.
 *
 *   jittered     without jitter, every worker that failed during the same
 *                outage retries at the same instant and re-creates the
 *                thundering herd that caused the outage. This is the property
 *                most often omitted, and the one that matters most at scale.
 *
 * Jitter is full-range rather than a small percentage: partial jitter still
 * leaves a pronounced spike at the base delay.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $baseSeconds = 2,
        private readonly int $maxSeconds = 3600,
        private readonly int $maxAttempts = 8,
    ) {}

    /**
     * Should another attempt be made after this many failures?
     */
    public function shouldRetry(int $attempts): bool
    {
        return $attempts < $this->maxAttempts;
    }

    /**
     * Delay in seconds before attempt number $attempts + 1.
     *
     * @param  callable(int, int): int|null  $randomizer  injected for deterministic tests
     */
    public function delayFor(int $attempts, ?callable $randomizer = null): int
    {
        $exponent = max(0, $attempts);

        // Clamp the exponent before shifting: 2 ** 64 overflows to float and
        // the cap below would then compare against an approximate value.
        $uncapped = $exponent > 30
            ? $this->maxSeconds
            : $this->baseSeconds * (2 ** $exponent);

        $ceiling = (int) min($uncapped, $this->maxSeconds);

        $random = $randomizer ?? static fn (int $min, int $max): int => random_int($min, $max);

        // Full jitter: uniform in [0, ceiling]. A retry may therefore happen
        // immediately, which is fine and is what breaks up the herd.
        return $random(0, max(0, $ceiling));
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
