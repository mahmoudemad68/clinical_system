<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

/**
 * Non-secret account-security SMS. Isolated from OTP codes.
 */
interface DeliverSecuritySms
{
    public function notify(string $e164Destination, string $noticeKind, string $locale): void;
}
