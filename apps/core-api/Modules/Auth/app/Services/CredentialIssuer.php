<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Contracts\RandomBytes;

final class CredentialIssuer
{
    public function __construct(
        private readonly RandomBytes $random,
        private readonly HmacHasher $hmac,
    ) {}

    public function randomToken(): string
    {
        return rtrim(strtr(base64_encode($this->random->next(32)), '+/', '-_'), '=');
    }

    public function hashToken(string $token): string
    {
        return $this->hmac->digest('session_token', $token);
    }

    public function otpCode(): string
    {
        $n = unpack('N', $this->random->next(4));
        $value = is_array($n) ? $n[1] % 1_000_000 : 0;

        return str_pad((string) abs($value), 6, '0', STR_PAD_LEFT);
    }

    public function hashOtp(string $challengeId, string $purpose, string $code): string
    {
        return $this->hmac->digest('otp_code', $challengeId.'|'.$purpose.'|'.$code);
    }

    public function recoveryCode(): string
    {
        return strtoupper(bin2hex($this->random->next(5)));
    }

    public function hashRecoveryCode(string $code): string
    {
        return $this->hmac->digest('mfa_recovery', strtoupper($code));
    }
}
