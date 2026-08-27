<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\SendOtp;
use Modules\Platform\Exceptions\ProviderNotEnabled;

/** Fail-closed OTP adapter. Phase 01 supplies the real SMS port. */
final class DisabledSendOtp implements SendOtp
{
    public function send(string $destinationFingerprint, string $purpose, array $context): void
    {
        throw new ProviderNotEnabled('SendOtp is not enabled in Phase 00.');
    }
}
