<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * Send a one-time password. Isolated from push, email, and SMS marketing.
 *
 * Phase 00 ships the port and a fail-closed adapter. IAM OTP is Phase 01.
 */
interface SendOtp
{
    /**
     * @param  array{purpose: string, locale: string}  $context
     */
    public function send(string $destinationFingerprint, string $purpose, array $context): void;
}
