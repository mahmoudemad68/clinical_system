<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

final readonly class TotpVerification
{
    public function __construct(
        public bool $valid,
        public ?int $acceptedCounter,
    ) {}
}
