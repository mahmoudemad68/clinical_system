<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Enums\SensitiveDecryptPurpose;
use Modules\Identity\Services\AuditedSensitiveDecryptor;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

final class ConfirmTotpService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly Authorize $authorizer,
        private readonly TotpVerifier $totp,
        private readonly AuditedSensitiveDecryptor $decryptor,
        private readonly CredentialIssuer $credentials,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly ReplaceTotpService $replace,
    ) {}

    /**
     * @return array{verified: bool, recovery_codes: list<string>}
     */
    public function handle(ActorContext $actor, string $code): array
    {
        $decision = $this->authorizer->decide($actor, Capabilities::MFA_MANAGE_SELF);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        if ($this->auth->activeTotp($actor->userId) !== null) {
            return $this->replace->confirm($actor, $code);
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $code): array {
            $factor = $this->auth->pendingTotp($actor->userId);
            if ($factor === null) {
                throw new InvalidValueObject('No pending authenticator enrollment exists.');
            }

            $now = $this->clock->now();
            $secret = $this->decryptor->decrypt(
                SensitiveDecryptPurpose::TotpConfirm,
                (string) $factor->secret_ciphertext,
                'mfa_factor',
                Identifier::fromTrusted((string) $factor->id),
                $actor->userId,
                'user',
                $tx,
            );

            $result = $this->totp->verify($secret, $code, $now, null);
            if (! $result->valid) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            $this->auth->markTotpVerified(Identifier::fromTrusted((string) $factor->id), $now);
            $count = (int) config('identity.mfa.recovery_code_count', 8);
            $codes = [];
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $plain = $this->credentials->recoveryCode();
                $codes[] = $plain;
                $rows[] = [
                    'id' => $this->ids->next()->value,
                    'user_id' => $actor->userId->value,
                    'factor_id' => $factor->id,
                    'code_hash' => $this->credentials->hashRecoveryCode($plain),
                    'consumed_at' => null,
                    'created_at' => $now->format('Y-m-d H:i:s.uP'),
                ];
            }
            $this->auth->insertRecoveryCodes($rows);
            $this->audit->append($tx, 'auth.mfa_enroll_confirmed', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), ['reason_code' => 'enroll'], $actor->userId, 'user');

            return ['verified' => true, 'recovery_codes' => $codes];
        });
    }
}
