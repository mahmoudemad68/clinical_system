<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Access\Domain\Contracts\Authorize;
use App\Modules\Access\Domain\ValueObjects\Capabilities;
use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\TotpVerifier;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthorizationDenied;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

final class ConfirmTotpHandler
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
     * @return array{verified: bool, recovery_codes: list<string>}
     */
    public function handle(ActorContext $actor, string $code): array
    {
        $decision = $this->authorizer->decide($actor, Capabilities::MFA_MANAGE_SELF);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $code): array {
            $factor = $this->auth->pendingTotp($actor->userId);
            if ($factor === null) {
                throw new InvalidValueObject('No pending authenticator enrollment exists.');
            }

            $now = $this->clock->now();
            $secret = $this->protector->decryptSecret('mfa_secret', (string) $factor->secret_ciphertext);
            $this->audit->append($tx, 'auth.sensitive_decrypt', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), [
                'reason_code' => 'totp_confirm',
                'purpose' => 'mfa_secret',
            ], $actor->userId, 'user');

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
