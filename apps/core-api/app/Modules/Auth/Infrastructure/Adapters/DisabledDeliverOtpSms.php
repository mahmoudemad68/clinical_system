<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Adapters;

use App\Modules\Auth\Domain\Contracts\DeliverOtpSms;
use App\Modules\Platform\Domain\Exceptions\ProviderNotEnabled;

final class DisabledDeliverOtpSms implements DeliverOtpSms
{
    public function deliver(string $e164Destination, string $code, string $locale, string $purpose): void
    {
        throw new ProviderNotEnabled('OTP SMS delivery is not enabled.');
    }
}
