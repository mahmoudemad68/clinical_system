<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

use DateTimeImmutable;

interface TotpVerifier
{
    public function generateSecret(): string;

    public function verify(string $secret, string $code, DateTimeImmutable $now, ?int $lastCounter): TotpVerification;

    public function codeAt(string $secret, DateTimeImmutable $now): string;

    public function provisioningUri(string $secret, string $accountLabel): string;
}
