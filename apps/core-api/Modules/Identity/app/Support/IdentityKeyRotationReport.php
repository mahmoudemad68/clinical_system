<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

/**
 * Count-only rotation / retirement status. Never includes plaintext or keys.
 */
final readonly class IdentityKeyRotationReport
{
    public function __construct(
        public int $hmacCurrent,
        public int $encryptionCurrent,
        public int $pendingPhone,
        public int $pendingNationalId,
        public int $pendingTotp,
        public int $pendingPushToken,
        public int $liveOtpOldEncryption,
        public int $liveRefreshReplay,
        public int $rewrittenPhone,
        public int $rewrittenNationalId,
        public int $rewrittenTotp,
        public int $rewrittenPushToken,
        public bool $retirementSafe,
    ) {}

    /**
     * @return array<string, int|bool|string>
     */
    public function toSafeArray(): array
    {
        return [
            'hmac_current' => $this->hmacCurrent,
            'encryption_current' => $this->encryptionCurrent,
            'pending_phone' => $this->pendingPhone,
            'pending_national_id' => $this->pendingNationalId,
            'pending_totp' => $this->pendingTotp,
            'pending_push_token' => $this->pendingPushToken,
            'live_otp_old_encryption' => $this->liveOtpOldEncryption,
            'live_refresh_replay' => $this->liveRefreshReplay,
            'rewritten_phone' => $this->rewrittenPhone,
            'rewritten_national_id' => $this->rewrittenNationalId,
            'rewritten_totp' => $this->rewrittenTotp,
            'rewritten_push_token' => $this->rewrittenPushToken,
            'retirement_safe' => $this->retirementSafe,
            'otp_ciphertext_policy' => 'expire_do_not_reencrypt',
            'refresh_replay_policy' => 'expire_do_not_reencrypt',
        ];
    }
}
