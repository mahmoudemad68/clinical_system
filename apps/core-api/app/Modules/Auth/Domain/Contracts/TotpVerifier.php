<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

use DateTimeImmutable;

final readonly class TotpVerification
{
    public function __construct(
        public bool $valid,
        public ?int $acceptedCounter,
    ) {}
}

interface TotpVerifier
{
    public function generateSecret(): string;

    public function verify(string $secret, string $code, DateTimeImmutable $now, ?int $lastCounter): TotpVerification;

    public function codeAt(string $secret, DateTimeImmutable $now): string;

    public function provisioningUri(string $secret, string $accountLabel): string;
}
