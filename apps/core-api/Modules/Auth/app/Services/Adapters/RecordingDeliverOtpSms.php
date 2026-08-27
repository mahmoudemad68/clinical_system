<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Adapters;

use Modules\Auth\Contracts\DeliverOtpSms;

/**
 * Test double. Production never binds this. Codes are held in memory only.
 */
final class RecordingDeliverOtpSms implements DeliverOtpSms
{
    /** @var list<array{purpose: string, locale: string}> */
    public array $sent = [];

    /** @var array<string, string> */
    public array $lastCodeByPurpose = [];

    public function deliver(string $e164Destination, string $code, string $locale, string $purpose): void
    {
        $this->sent[] = ['purpose' => $purpose, 'locale' => $locale];
        $this->lastCodeByPurpose[$purpose] = $code;
    }
}
