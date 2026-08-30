<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;
use stdClass;

final class ReplaceTotpService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly Authorize $authorizer,
        private readonly TotpVerifier $totp,
        private readonly NationalIdProtector $protector,
        private readonly CredentialIssuer $credentials,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly AppendAuditEvent $audit,
        private readonly RecordSessionRevokedEvents $sessionRevoked,
    ) {}

    /**
     * @return array{factor_id: string, provisioning_uri: string}
     */
    public function begin(ActorContext $actor): array
    {
        $this->assertSelfManage($actor);

        if ($actor->assuranceLevel !== AssuranceLevel::Aal2RecoveryCode || $actor->sessionId === null) {
            throw new InvalidValueObject('An authenticator factor is already pending or active.');
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor): array {
            $this->lockReplacementOwner($actor);
            [$active, $pending] = $this->lockedFactors($actor->userId);
            $this->assertRecoverySession($actor);

            if ($active === null) {
                throw new InvalidValueObject('No active authenticator factor exists.');
            }

            $now = $this->clock->now();
            if ($pending !== null) {
                $this->auth->discardUnverifiedTotp(Identifier::fromTrusted((string) $pending->id), $actor->userId, $now);
            }

            $factorId = $this->ids->next();
            $secret = $this->totp->generateSecret();

            try {
                $this->auth->insertTotpFactor([
                    'id' => $factorId->value,
                    'user_id' => $actor->userId->value,
                    'factor_type' => 'totp',
                    'secret_ciphertext' => $this->protector->encryptSecret('mfa_secret', $secret),
                    'key_version' => 1,
                    'last_used_counter' => null,
                    'last_used_at' => null,
                    'verified_at' => null,
                    'disabled_at' => null,
                    'disabled_by' => null,
                    'created_at' => $now->format('Y-m-d H:i:s.uP'),
                    'updated_at' => $now->format('Y-m-d H:i:s.uP'),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new InvalidValueObject('An authenticator factor is already pending or active.');
            }

            $this->audit->append($tx, 'auth.mfa_replace_started', 'mfa_factor', $factorId, [
                'reason_code' => 'replace',
                'replaced_factor_id' => (string) $active->id,
            ], $actor->userId, 'user');

            return [
                'factor_id' => $factorId->value,
                'provisioning_uri' => $this->totp->provisioningUri($secret, $actor->userId->value),
            ];
        });
    }

    /**
     * @return array{verified: bool, recovery_codes: list<string>}
     */
    public function confirm(ActorContext $actor, string $code): array
    {
        $this->assertSelfManage($actor);

        if ($actor->assuranceLevel !== AssuranceLevel::Aal2RecoveryCode || $actor->sessionId === null) {
            throw new InvalidValueObject('The verification code is invalid or expired.');
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $code): array {
            $this->lockReplacementOwner($actor);
            [$active, $pending] = $this->lockedFactors($actor->userId);
            $this->assertRecoverySession($actor);

            if ($pending === null) {
                throw new InvalidValueObject('No pending authenticator enrollment exists.');
            }

            if ($active === null) {
                throw new InvalidValueObject('No active authenticator factor exists.');
            }

            $now = $this->clock->now();
            $pendingId = Identifier::fromTrusted((string) $pending->id);
            $secret = $this->protector->decryptSecret('mfa_secret', (string) $pending->secret_ciphertext);
            $this->audit->append($tx, 'auth.sensitive_decrypt', 'mfa_factor', $pendingId, [
                'reason_code' => 'totp_replace_confirm',
                'purpose' => 'mfa_secret',
            ], $actor->userId, 'user');

            $result = $this->totp->verify($secret, $code, $now, null);
            if (! $result->valid) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            try {
                $this->auth->disableTotpFactor(Identifier::fromTrusted((string) $active->id), $actor->userId, $now);
                $this->auth->markTotpVerified($pendingId, $now);
            } catch (UniqueConstraintViolationException) {
                throw new InvalidValueObject('An authenticator factor is already pending or active.');
            }

            $count = (int) config('identity.mfa.recovery_code_count', 8);
            $codes = [];
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $plain = $this->credentials->recoveryCode();
                $codes[] = $plain;
                $rows[] = [
                    'id' => $this->ids->next()->value,
                    'user_id' => $actor->userId->value,
                    'factor_id' => $pending->id,
                    'code_hash' => $this->credentials->hashRecoveryCode($plain),
                    'consumed_at' => null,
                    'created_at' => $now->format('Y-m-d H:i:s.uP'),
                ];
            }
            $this->auth->insertRecoveryCodes($rows);

            $sessionId = $actor->sessionId;
            if ($sessionId === null) {
                throw new AuthorizationDenied;
            }

            $this->auth->updateSessionAssurance($sessionId, AssuranceLevel::Aal2Totp->value, $now);
            $affected = $this->auth->revokeOtherSessions($actor->userId, $sessionId, 'mfa_replace', $now);
            $this->sessionRevoked->onto($tx, $actor->userId, $affected, 'mfa_replace', $now);
            if ($actor->deviceId !== null) {
                $this->auth->revokeOtherDevices($actor->userId, $actor->deviceId, 'mfa_replace', $now);
            } else {
                $this->auth->revokeAllDevices($actor->userId, 'mfa_replace', $now);
            }

            $this->audit->append($tx, 'auth.mfa_replace_confirmed', 'mfa_factor', $pendingId, [
                'reason_code' => 'replace',
                'replaced_factor_id' => (string) $active->id,
            ], $actor->userId, 'user');

            return ['verified' => true, 'recovery_codes' => $codes];
        });
    }

    private function assertSelfManage(ActorContext $actor): void
    {
        $decision = $this->authorizer->decide($actor, Capabilities::MFA_MANAGE_SELF);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }
    }

    private function lockReplacementOwner(ActorContext $actor): void
    {
        $user = $this->identities->lockById($actor->userId);
        if ($user === null || ! $user->id->equals($actor->userId)) {
            throw new AuthorizationDenied;
        }
    }

    private function assertRecoverySession(ActorContext $actor): void
    {
        $sessionId = $actor->sessionId;
        if ($sessionId === null) {
            throw new AuthorizationDenied;
        }

        $session = $this->auth->lockSession($sessionId);
        if ($session === null
            || $session->revoked_at !== null
            || (string) $session->user_id !== $actor->userId->value
            || (string) $session->assurance_level !== AssuranceLevel::Aal2RecoveryCode->value) {
            throw new InvalidValueObject('An authenticator factor is already pending or active.');
        }
    }

    /**
     * @return array{0: ?stdClass, 1: ?stdClass}
     */
    private function lockedFactors(Identifier $userId): array
    {
        $active = null;
        $pending = null;
        foreach ($this->auth->lockEnabledTotpFactors($userId) as $factor) {
            if ($factor->verified_at !== null) {
                $active = $factor;
            } else {
                $pending = $factor;
            }
        }

        return [$active, $pending];
    }
}
