<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Modules\Platform\Contracts\SendPush;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Throwable;

/**
 * FCM delivery via kreait. Lock-screen copy stays generic.
 */
final class FirebaseSendPush implements SendPush
{
    public function __construct(
        private readonly Messaging $messaging,
        private readonly PlatformMetrics $metrics,
    ) {}

    public function send(string $deviceTokenFingerprint, string $notificationType, array $data): void
    {
        $payload = ['type' => $notificationType];

        foreach ($data as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $payload[(string) $key] = (string) $value;
        }

        $message = CloudMessage::new()
            ->withToken($deviceTokenFingerprint)
            ->withNotification(FcmNotification::fromArray([
                'title' => 'Clinic',
                'body' => 'You have a new notice',
            ]))
            ->withData($payload);

        try {
            $this->messaging->send($message);
        } catch (Throwable $e) {
            $this->metrics->increment('clinic_provider_failures_total', ['error_class' => 'push']);

            throw $e;
        }
    }
}
