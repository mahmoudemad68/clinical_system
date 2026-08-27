<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

interface DeliverOtpSms
{
    public function deliver(string $e164Destination, string $code, string $locale, string $purpose): void;
}
