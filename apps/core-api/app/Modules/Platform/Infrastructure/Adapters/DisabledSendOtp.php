<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Adapters;

use App\Modules\Platform\Domain\Contracts\SendOtp;
use App\Modules\Platform\Domain\Exceptions\ProviderNotEnabled;

/** Fail-closed OTP adapter. Phase 01 supplies the real SMS port. */
final class DisabledSendOtp implements SendOtp
{
    public function send(string $destinationFingerprint, string $purpose, array $context): void
    {
        throw new ProviderNotEnabled('SendOtp is not enabled in Phase 00.');
    }
}
