<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Enums\ScanOutcome;

/**
 * Scanner result for a server-resolved byte stream. Never includes the
 * scanner raw payload, signature names, or object locators.
 */
final readonly class ScanVerdict
{
    public function __construct(
        public ScanOutcome $outcome,
        public string $scannerIdentity,
        public ?string $scannerVersion,
    ) {}

    public static function clean(string $scannerIdentity, ?string $scannerVersion): self
    {
        return new self(ScanOutcome::Clean, $scannerIdentity, $scannerVersion);
    }

    public static function infected(string $scannerIdentity, ?string $scannerVersion): self
    {
        return new self(ScanOutcome::Infected, $scannerIdentity, $scannerVersion);
    }

    public static function unavailable(string $scannerIdentity = 'disabled', ?string $scannerVersion = null): self
    {
        return new self(ScanOutcome::Unavailable, $scannerIdentity, $scannerVersion);
    }

    public static function invalid(string $scannerIdentity = 'disabled', ?string $scannerVersion = null): self
    {
        return new self(ScanOutcome::Invalid, $scannerIdentity, $scannerVersion);
    }

    public function isClean(): bool
    {
        return $this->outcome === ScanOutcome::Clean;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === ScanOutcome::Unavailable;
    }
}
