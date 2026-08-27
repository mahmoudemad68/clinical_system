<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Adapters;

use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Platform\Exceptions\ProviderNotEnabled;

final class DisabledDeliverOtpSms implements DeliverOtpSms
{
    public function deliver(string $e164Destination, string $code, string $locale, string $purpose): void
    {
        throw new ProviderNotEnabled('OTP SMS delivery is not enabled.');
    }
}
