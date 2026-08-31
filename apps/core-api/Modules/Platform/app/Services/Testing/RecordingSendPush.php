<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Testing;

use Modules\Platform\Contracts\SendPush;
use RuntimeException;

/**
 * In-process SendPush double. Never talks to FCM.
 */
final class RecordingSendPush implements SendPush
{
    /** @var list<array{token: string, type: string, data: array<string, bool|float|int|string>}> */
    public array $sent = [];

    public function __construct(
        private readonly bool $fail = false,
    ) {}

    public function send(string $deviceTokenFingerprint, string $notificationType, array $data): void
    {
        $this->sent[] = [
            'token' => $deviceTokenFingerprint,
            'type' => $notificationType,
            'data' => $data,
        ];

        if ($this->fail) {
            throw new RuntimeException('Synthetic push failure.');
        }
    }
}
