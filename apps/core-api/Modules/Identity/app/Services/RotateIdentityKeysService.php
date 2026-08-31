<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use DateTimeImmutable;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\SensitiveDecryptPurpose;
use Modules\Identity\Support\IdentityKeyRotationReport;
use Modules\Identity\Support\NationalId;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

/**
 * Provider-neutral batched identity key rotation (ADR 0013).
 *
 * Reads every configured HMAC version and rewrites onto the current
 * encryption/HMAC versions. Short-lived OTP and refresh-replay ciphertext is
 * counted, not rewritten; retirement stays blocked until those rows expire or
 * prune. Never prints plaintext.
 *
 * Listed in ApprovedCoordinators: Identity phone/NID rows plus Auth OTP HMAC
 * rebind / TOTP / push-token ciphertext in one per-row transaction.
 */
final class RotateIdentityKeysService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly NationalIdProtector $protector,
        private readonly AuditedSensitiveDecryptor $decryptor,
        private readonly Clock $clock,
    ) {}

    public function inspect(): IdentityKeyRotationReport
    {
        return $this->report(0, 0, 0, 0);
    }

    public function apply(int $batch): IdentityKeyRotationReport
    {
        $batch = max(1, $batch);
        $enc = $this->protector->encryptionVersion();
        $hmac = $this->protector->hmacVersion();
        $now = $this->clock->now();

        $rewrittenPhone = $this->rewritePhones($enc, $hmac, $batch, $now);
        $rewrittenNationalId = $this->rewriteNationalIds($enc, $hmac, $batch, $now);
        $rewrittenTotp = $this->rewriteTotp($enc, $batch, $now);
        $rewrittenPush = $this->rewritePushTokens($enc, $batch);

        return $this->report($rewrittenPhone, $rewrittenNationalId, $rewrittenTotp, $rewrittenPush);
    }

    private function rewritePhones(int $enc, int $hmac, int $batch, DateTimeImmutable $now): int
    {
        $rewritten = 0;

        foreach ($this->identities->phonesNeedingRekey($enc, $hmac, $batch) as $row) {
            $this->transactions->run(function (TransactionContext $tx) use ($row, $enc, $hmac, $now): void {
                $id = Identifier::fromTrusted((string) $row->id);
                $plain = $this->decryptor->decrypt(
                    SensitiveDecryptPurpose::PhoneKeyRotation,
                    BinaryColumn::asString($row->phone_e164_encrypted),
                    'user',
                    $id,
                    null,
                    'system',
                    $tx,
                );
                $phone = $this->protector->phone($plain);
                $oldHmac = BinaryColumn::asString($row->phone_lookup_hmac);
                $newHmac = $this->protector->phoneHmac($phone);
                $this->identities->rewritePhoneCrypto(
                    $id,
                    $this->protector->encryptPhone($phone),
                    $newHmac,
                    $enc,
                    $hmac,
                    $now,
                );
                $this->auth->rebindOtpSubjectHmac($oldHmac, $newHmac);
            });
            $rewritten++;
        }

        return $rewritten;
    }

    private function rewriteNationalIds(int $enc, int $hmac, int $batch, DateTimeImmutable $now): int
    {
        $rewritten = 0;
        $allowSynthetic = (bool) config('identity.allow_synthetic_national_ids', false);

        foreach ($this->identities->nationalIdsNeedingRekey($enc, $hmac, $batch) as $row) {
            $this->transactions->run(function (TransactionContext $tx) use ($row, $enc, $hmac, $now, $allowSynthetic): void {
                $id = Identifier::fromTrusted((string) $row->id);
                $plain = $this->decryptor->decrypt(
                    SensitiveDecryptPurpose::NationalIdKeyRotation,
                    BinaryColumn::asString($row->national_id_encrypted),
                    'identity_national_id',
                    $id,
                    null,
                    'system',
                    $tx,
                );
                $nationalId = NationalId::fromUntrusted($plain, $allowSynthetic);
                $this->identities->rewriteNationalIdCrypto(
                    $id,
                    $this->protector->encryptNationalId($nationalId),
                    $this->protector->nationalIdHmac($nationalId),
                    $enc,
                    $hmac,
                    $now,
                );
            });
            $rewritten++;
        }

        return $rewritten;
    }

    private function rewriteTotp(int $enc, int $batch, DateTimeImmutable $now): int
    {
        $rewritten = 0;

        foreach ($this->auth->totpFactorsNeedingRekey($enc, $batch) as $row) {
            $this->transactions->run(function (TransactionContext $tx) use ($row, $enc, $now): void {
                $id = Identifier::fromTrusted((string) $row->id);
                $plain = $this->decryptor->decrypt(
                    SensitiveDecryptPurpose::TotpKeyRotation,
                    BinaryColumn::asString($row->secret_ciphertext),
                    'mfa_factor',
                    $id,
                    null,
                    'system',
                    $tx,
                );
                $this->auth->rewriteTotpSecret(
                    $id,
                    $this->protector->encryptSecret('mfa_secret', $plain),
                    $enc,
                    $now,
                );
            });
            $rewritten++;
        }

        return $rewritten;
    }

    private function rewritePushTokens(int $enc, int $batch): int
    {
        $rewritten = 0;

        foreach ($this->auth->devicesWithPushTokenNeedingRekey($enc, $batch) as $row) {
            $this->transactions->run(function (TransactionContext $tx) use ($row): void {
                $id = Identifier::fromTrusted((string) $row->id);
                $plain = $this->decryptor->decrypt(
                    SensitiveDecryptPurpose::PushTokenKeyRotation,
                    BinaryColumn::asString($row->push_token_ciphertext),
                    'user_device',
                    $id,
                    null,
                    'system',
                    $tx,
                );
                $this->auth->rewritePushToken($id, $this->protector->encryptSecret('push_token', $plain));
            });
            $rewritten++;
        }

        return $rewritten;
    }

    private function report(
        int $rewrittenPhone,
        int $rewrittenNationalId,
        int $rewrittenTotp,
        int $rewrittenPush,
    ): IdentityKeyRotationReport {
        $enc = $this->protector->encryptionVersion();
        $hmac = $this->protector->hmacVersion();
        $pendingPhone = $this->identities->countPhonesNeedingRekey($enc, $hmac);
        $pendingNationalId = $this->identities->countNationalIdsNeedingRekey($enc, $hmac);
        $pendingTotp = $this->auth->countTotpNeedingRekey($enc);
        $pendingPush = $this->auth->countPushTokensNeedingRekey($enc);
        $liveOtp = $this->auth->countLiveOtpEncryptionBelow($enc);
        $replay = $this->auth->countRefreshReplayBelow($enc);

        return new IdentityKeyRotationReport(
            $hmac,
            $enc,
            $pendingPhone,
            $pendingNationalId,
            $pendingTotp,
            $pendingPush,
            $liveOtp,
            $replay,
            $rewrittenPhone,
            $rewrittenNationalId,
            $rewrittenTotp,
            $rewrittenPush,
            $pendingPhone === 0
                && $pendingNationalId === 0
                && $pendingTotp === 0
                && $pendingPush === 0
                && $liveOtp === 0
                && $replay === 0,
        );
    }
}
