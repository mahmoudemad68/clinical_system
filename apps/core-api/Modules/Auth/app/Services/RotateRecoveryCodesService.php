<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

final class RotateRecoveryCodesService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly Authorize $authorizer,
        private readonly TotpVerifier $totp,
        private readonly NationalIdProtector $protector,
        private readonly CredentialIssuer $credentials,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @return array{recovery_codes: list<string>}
     */
    public function handle(ActorContext $actor, string $code): array
    {
        $decision = $this->authorizer->decide($actor, Capabilities::MFA_MANAGE_SELF);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $code): array {
            $factor = $this->auth->activeTotp($actor->userId);
            if ($factor === null) {
                throw new InvalidValueObject('No active authenticator factor exists.');
            }

            $now = $this->clock->now();
            $secret = $this->protector->decryptSecret('mfa_secret', (string) $factor->secret_ciphertext);
            $this->audit->append($tx, 'auth.sensitive_decrypt', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), [
                'reason_code' => 'recovery_codes_rotate',
                'purpose' => 'mfa_secret',
            ], $actor->userId, 'user');
            $result = $this->totp->verify(
                $secret,
                $code,
                $now,
                $factor->last_used_counter !== null ? (int) $factor->last_used_counter : null,
            );
            if (! $result->valid || $result->acceptedCounter === null) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            $this->auth->updateTotpCounter(Identifier::fromTrusted((string) $factor->id), $result->acceptedCounter, $now);
            $this->auth->deleteUnconsumedRecoveryCodes($actor->userId);
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
            $this->audit->append($tx, 'auth.mfa_recovery_codes_rotated', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), ['reason_code' => 'rotate'], $actor->userId, 'user');

            return ['recovery_codes' => $codes];
        });
    }
}
