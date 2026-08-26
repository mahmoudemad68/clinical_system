<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

interface AuthenticationRateLimiter
{
    public function hitLogin(string $subjectHmac, string $ipPrefix): void;

    public function hitOtp(string $subjectHmac, string $ipPrefix): void;

    public function hitRecovery(string $subjectHmac): void;
}
