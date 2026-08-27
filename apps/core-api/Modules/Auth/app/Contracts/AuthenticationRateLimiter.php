<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

interface AuthenticationRateLimiter
{
    public function hitLogin(string $subjectHmac, string $ipPrefix): void;

    public function hitOtp(string $subjectHmac, string $ipPrefix): void;

    public function hitRecovery(string $subjectHmac, ?string $ipPrefix = null): void;

    public function hitRefresh(string $familyId, string $ipPrefix): void;

    public function hitMfa(string $challengeId, string $ipPrefix): void;

    public function hitOtpVerify(string $challengeId, string $ipPrefix): void;
}
