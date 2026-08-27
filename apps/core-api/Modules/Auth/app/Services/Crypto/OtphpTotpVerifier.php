<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Crypto;

use DateTimeImmutable;
use Modules\Auth\Contracts\TotpVerification;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Platform\Contracts\RandomBytes;
use OTPHP\TOTP;

final class OtphpTotpVerifier implements TotpVerifier
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly RandomBytes $random,
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly int $skewPeriods = 1,
    ) {}

    public function generateSecret(): string
    {
        $raw = $this->random->next(20);
        $bits = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0');
            $out .= self::BASE32[bindec($chunk)];
        }

        return substr($out, 0, 32);
    }

    public function verify(string $secret, string $code, DateTimeImmutable $now, ?int $lastCounter): TotpVerification
    {
        if (preg_match('/^\d{'.$this->digits.'}$/', $code) !== 1) {
            return new TotpVerification(false, null);
        }

        $totp = $this->totp($secret);
        $timestamp = $now->getTimestamp();
        $window = $this->skewPeriods;

        for ($offset = -$window; $offset <= $window; $offset++) {
            $at = $timestamp + ($offset * $this->period);
            $counter = intdiv($at, $this->period);

            if ($lastCounter !== null && $counter <= $lastCounter) {
                continue;
            }

            if (hash_equals($totp->at($at), $code)) {
                return new TotpVerification(true, $counter);
            }
        }

        return new TotpVerification(false, null);
    }

    public function codeAt(string $secret, DateTimeImmutable $now): string
    {
        return $this->totp($secret)->at($now->getTimestamp());
    }

    public function provisioningUri(string $secret, string $accountLabel): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($accountLabel);
        $totp->setIssuer('Clinic');

        return $totp->getProvisioningUri();
    }

    private function totp(string $secret): TOTP
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setDigest('sha1');
        $totp->setDigits($this->digits);
        $totp->setPeriod($this->period);

        return $totp;
    }
}
