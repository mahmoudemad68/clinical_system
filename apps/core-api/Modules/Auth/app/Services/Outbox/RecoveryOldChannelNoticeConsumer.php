<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Outbox;

use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\DeliverSecuritySms;
use Modules\Auth\Enums\RecoveryNoticeKind;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\SensitiveDecryptPurpose;
use Modules\Identity\Services\AuditedSensitiveDecryptor;
use Modules\Platform\Contracts\SendPush;
use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Platform\Services\Outbox\OutboxConsumer;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\Identifier;
use Throwable;

final class RecoveryOldChannelNoticeConsumer implements OutboxConsumer
{
    public function __construct(
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly AuditedSensitiveDecryptor $decryptor,
        private readonly DeliverSecuritySms $sms,
        private readonly SendPush $push,
        private readonly PlatformMetrics $metrics,
    ) {}

    public function handles(): string
    {
        return 'auth.recovery_old_channel_notice_requested';
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function consume(string $eventId, array $payload): void
    {
        $kind = RecoveryNoticeKind::tryFrom((string) ($payload['notice_kind'] ?? ''));
        $locale = (string) ($payload['locale'] ?? 'en');
        $requestId = Identifier::fromString((string) ($payload['recovery_request_id'] ?? ''));
        $row = $this->auth->recoveryRequestById($requestId);

        if ($kind === null || $row === null) {
            return;
        }

        $userId = Identifier::fromTrusted((string) $row->user_id);
        $cipher = $this->identities->encryptedPhone($userId);

        if ($cipher === null) {
            return;
        }

        $destination = $this->decryptor->decrypt(
            SensitiveDecryptPurpose::PhoneRecoveryNotice,
            $cipher,
            'user',
            $userId,
            null,
            'system',
        );

        try {
            $this->sms->notify($destination, $kind->value, $locale);
            $this->metrics->increment('clinic_auth_attempts_total', [
                'result' => 'sent',
                'method' => 'recovery_notice',
                'actor_class' => 'unknown',
                'reason_code' => $kind->value,
            ]);
        } catch (ProviderNotEnabled $e) {
            $this->metrics->increment('clinic_auth_attempts_total', [
                'result' => 'provider_disabled',
                'method' => 'recovery_notice',
                'actor_class' => 'unknown',
                'reason_code' => $kind->value,
            ]);

            throw $e;
        }

        $pushType = $kind === RecoveryNoticeKind::Applied
            ? 'auth.recovery_applied'
            : 'auth.recovery_queued';

        foreach ($this->auth->pushTokenCiphers($userId) as $tokenCipher) {
            try {
                $token = $this->decryptor->decrypt(
                    SensitiveDecryptPurpose::PushTokenDelivery,
                    $tokenCipher,
                    'user',
                    $userId,
                    null,
                    'system',
                );
                $this->push->send($token, $pushType, ['ref' => $requestId->value]);
            } catch (Throwable) {
                // Push is best-effort after the SMS notice is accepted.
            }
        }
    }
}
