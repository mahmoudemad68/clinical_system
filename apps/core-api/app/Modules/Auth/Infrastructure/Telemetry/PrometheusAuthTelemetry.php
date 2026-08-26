<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Telemetry;

use App\Modules\Auth\Domain\Contracts\AuthTelemetry;
use App\Modules\Platform\Infrastructure\Telemetry\PlatformMetrics;

final class PrometheusAuthTelemetry implements AuthTelemetry
{
    public function __construct(private readonly PlatformMetrics $metrics) {}

    public function authAttempt(array $labels): void
    {
        $this->metrics->increment('clinic_auth_attempts_total', $labels);
    }

    public function otp(array $labels): void
    {
        $this->metrics->increment('clinic_otp_requests_total', $labels);
    }

    public function mfa(array $labels): void
    {
        $this->metrics->increment('clinic_mfa_challenges_total', $labels);
    }

    public function authorization(array $labels): void
    {
        $this->metrics->increment('clinic_authorization_decisions_total', $labels);
    }

    public function claim(array $labels): void
    {
        $this->metrics->increment('clinic_profile_claims_total', $labels);
    }
}
