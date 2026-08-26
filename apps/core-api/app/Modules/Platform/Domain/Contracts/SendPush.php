<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Send a push notification. Isolated from OTP and SMS.
 *
 * Payloads must be generic lock-screen text. Identifiers, not clinical content.
 */
interface SendPush
{
    /**
     * @param  array<string, scalar>  $data  opaque resource references only
     */
    public function send(string $deviceTokenFingerprint, string $notificationType, array $data): void;
}
