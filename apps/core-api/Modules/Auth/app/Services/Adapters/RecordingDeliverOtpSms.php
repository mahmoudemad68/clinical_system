<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Adapters;

use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Contracts\DeliverSecuritySms;
use Modules\Auth\Services\RecoveryOldChannelCopy;
use Modules\Platform\Exceptions\ProviderNotEnabled;

/**
 * Test double. Production never binds this. Codes are held in memory only.
 */
final class RecordingDeliverOtpSms implements DeliverOtpSms, DeliverSecuritySms
{
    /** @var list<array{purpose: string, locale: string}> */
    public array $sent = [];

    /** @var array<string, string> */
    public array $lastCodeByPurpose = [];

    /** @var list<array{kind: string, locale: string, destination: string, body: string}> */
    public array $notices = [];

    public bool $failNotices = false;

    public function deliver(string $e164Destination, string $code, string $locale, string $purpose): void
    {
        $this->sent[] = ['purpose' => $purpose, 'locale' => $locale];
        $this->lastCodeByPurpose[$purpose] = $code;
    }

    public function notify(string $e164Destination, string $noticeKind, string $locale): void
    {
        if ($this->failNotices) {
            throw new ProviderNotEnabled('OTP SMS delivery is not enabled.');
        }

        $this->notices[] = [
            'kind' => $noticeKind,
            'locale' => $locale,
            'destination' => $e164Destination,
            'body' => RecoveryOldChannelCopy::body($noticeKind, $locale),
        ];
    }
}
