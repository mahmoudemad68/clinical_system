<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

interface AuthTelemetry
{
    /**
     * @param  array<string, string>  $labels
     */
    public function authAttempt(array $labels): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function otp(array $labels): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function mfa(array $labels): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function authorization(array $labels): void;

    /**
     * @param  array<string, string>  $labels
     */
    public function claim(array $labels): void;
}
