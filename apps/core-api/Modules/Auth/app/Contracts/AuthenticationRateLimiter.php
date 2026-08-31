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

    /**
     * Clear identifiable subject-scoped limiter keys. Shared IP keys are left alone.
     *
     * @param  list<string>  $refreshFamilyIds
     * @param  list<string>  $mfaChallengeIds
     * @param  list<string>  $otpIds
     */
    public function forgetSubject(
        string $subjectHmac,
        array $refreshFamilyIds = [],
        array $mfaChallengeIds = [],
        array $otpIds = [],
    ): void;
}
