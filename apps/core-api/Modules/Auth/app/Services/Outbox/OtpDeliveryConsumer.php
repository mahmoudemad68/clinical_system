<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Outbox;

use DateTimeImmutable;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Platform\Services\Outbox\OutboxConsumer;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\Identifier;

final class OtpDeliveryConsumer implements OutboxConsumer
{
    public function __construct(
        private readonly AuthDirectory $auth,
        private readonly NationalIdProtector $protector,
        private readonly DeliverOtpSms $sms,
        private readonly PlatformMetrics $metrics,
    ) {}

    public function handles(): string
    {
        return 'auth.otp_delivery_requested';
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function consume(string $eventId, array $payload): void
    {
        $otpId = Identifier::fromString((string) $payload['otp_request_id']);
        $row = $this->auth->otpById($otpId);

        if ($row === null || $row->consumed_at !== null) {
            return;
        }

        $destination = $this->protector->decryptPhone(is_string($row->destination_ciphertext) ? $row->destination_ciphertext : (string) $row->destination_ciphertext);
        $code = $this->protector->decryptSecret('otp_code', is_string($row->code_ciphertext) ? $row->code_ciphertext : (string) $row->code_ciphertext);

        try {
            $this->sms->deliver($destination, $code, (string) $row->locale, (string) $row->purpose);
            $this->auth->markOtpDelivery($otpId, 'sent', $eventId);
            $this->metrics->increment('clinic_otp_requests_total', ['purpose' => (string) $row->purpose, 'result' => 'sent']);
            $created = new DateTimeImmutable((string) $row->created_at);
            $this->metrics->set(
                'clinic_otp_delivery_age_seconds',
                (float) max(0, time() - $created->getTimestamp()),
                ['purpose' => (string) $row->purpose],
            );
        } catch (ProviderNotEnabled) {
            $this->auth->markOtpDelivery($otpId, 'retryable', null);
            $this->metrics->increment('clinic_otp_requests_total', ['purpose' => (string) $row->purpose, 'result' => 'provider_disabled']);
        }
    }
}
