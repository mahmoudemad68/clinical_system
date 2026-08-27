<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

final readonly class OtpChallengeResult
{
    public function __construct(
        public string $challengeId,
        public string $status,
    ) {}

    /**
     * @return array{challenge_id: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'status' => $this->status,
        ];
    }
}
