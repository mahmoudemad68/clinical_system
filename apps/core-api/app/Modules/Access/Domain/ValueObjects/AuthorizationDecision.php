<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\ValueObjects;

final readonly class AuthorizationDecision
{
    public function __construct(
        public bool $allowed,
        public string $reasonCode,
        public string $actionGroup,
    ) {}

    public static function deny(string $reasonCode, string $actionGroup = 'unknown'): self
    {
        return new self(false, $reasonCode, $actionGroup);
    }

    public static function allow(string $actionGroup): self
    {
        return new self(true, 'allow', $actionGroup);
    }
}
