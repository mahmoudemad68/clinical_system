<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

interface DeliverOtpSms
{
    public function deliver(string $e164Destination, string $code, string $locale, string $purpose): void;
}
