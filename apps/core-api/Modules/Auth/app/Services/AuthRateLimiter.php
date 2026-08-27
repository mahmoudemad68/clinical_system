<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Cache\RateLimiter;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Platform\Exceptions\RateLimited;

final class AuthRateLimiter implements AuthenticationRateLimiter
{
    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly array $limits,
    ) {}

    public function hitLogin(string $subjectHmac, string $ipPrefix): void
    {
        $this->hit('auth-login-subject:'.bin2hex($subjectHmac), (int) $this->limits['login_per_subject_per_minute'], 60);
        $this->hit('auth-login-ip:'.$ipPrefix, (int) $this->limits['login_per_ip_per_minute'], 60);
    }

    public function hitOtp(string $subjectHmac, string $ipPrefix): void
    {
        $this->hit('auth-otp-subject:'.bin2hex($subjectHmac), (int) $this->limits['otp_per_subject_per_hour'], 3600);
        $this->hit('auth-otp-ip:'.$ipPrefix, (int) $this->limits['otp_per_ip_per_hour'], 3600);
        $this->hit('auth-otp-global', (int) config('identity.otp.global_hourly_budget', 200), 3600);
    }

    public function hitRecovery(string $subjectHmac, ?string $ipPrefix = null): void
    {
        $this->hit('auth-recovery-subject:'.hash('sha256', $subjectHmac), (int) $this->limits['recovery_per_subject_per_hour'], 3600);
        if (is_string($ipPrefix) && $ipPrefix !== '') {
            $this->hit('auth-recovery-ip:'.$ipPrefix, (int) ($this->limits['recovery_per_ip_per_hour'] ?? 20), 3600);
        }
    }

    public function hitRefresh(string $familyId, string $ipPrefix): void
    {
        $this->hit('auth-refresh-family:'.$familyId, (int) ($this->limits['refresh_per_device_per_minute'] ?? 30), 60);
        $this->hit('auth-refresh-ip:'.$ipPrefix, (int) ($this->limits['refresh_per_ip_per_minute'] ?? 60), 60);
    }

    public function hitMfa(string $challengeId, string $ipPrefix): void
    {
        $this->hit('auth-mfa-challenge:'.$challengeId, (int) ($this->limits['mfa_per_challenge_per_minute'] ?? 10), 60);
        $this->hit('auth-mfa-ip:'.$ipPrefix, (int) ($this->limits['login_per_ip_per_minute'] ?? 20), 60);
    }

    public function hitOtpVerify(string $challengeId, string $ipPrefix): void
    {
        $this->hit('auth-otp-verify-challenge:'.$challengeId, (int) ($this->limits['otp_verify_per_challenge_per_minute'] ?? 10), 60);
        $this->hit('auth-otp-verify-ip:'.$ipPrefix, (int) ($this->limits['otp_verify_per_ip_per_minute'] ?? 30), 60);
    }

    private function hit(string $key, int $max, int $decay): void
    {
        if ($this->limiter->tooManyAttempts($key, $max)) {
            throw new RateLimited($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $decay);
    }
}
