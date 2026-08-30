<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use DateTimeImmutable;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\AuthTelemetry;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

final class CompleteMfaService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly TotpVerifier $totp,
        private readonly NationalIdProtector $protector,
        private readonly CredentialIssuer $credentials,
        private readonly IssueAuthenticatedSession $sessions,
        private readonly Clock $clock,
        private readonly AuthTelemetry $telemetry,
        private readonly AuthenticationRateLimiter $rates,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * Mutually exclusive TOTP or recovery-code proof for MFA completion.
     *
     * @return array<string, list<string>>
     */
    public static function proofRules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string', 'size:6'],
            'recovery_code' => ['required_without:code', 'prohibits:code', 'string', 'min:8', 'max:32'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $challengeId, ?string $totpCode, string $ipPrefix = '0.0.0.0', ?string $recoveryCode = null): array
    {
        $this->rates->hitMfa(strtolower(trim($challengeId)), $ipPrefix);
        $id = Identifier::fromString($challengeId);
        $totpCode = is_string($totpCode) && $totpCode !== '' ? $totpCode : null;
        $recoveryCode = is_string($recoveryCode) && $recoveryCode !== '' ? trim($recoveryCode) : null;
        if ($recoveryCode === '') {
            $recoveryCode = null;
        }

        $result = $this->transactions->run(function (TransactionContext $tx) use ($id, $totpCode, $recoveryCode): array {
            $usingTotp = $totpCode !== null;
            $usingRecovery = $recoveryCode !== null;
            if ($usingTotp === $usingRecovery) {
                $this->telemetry->mfa(['result' => 'denied']);

                return ['denied' => true];
            }

            $challenge = $this->auth->lockMfaChallenge($id);
            $now = $this->clock->now();

            if ($challenge === null || $challenge->consumed_at !== null || new DateTimeImmutable((string) $challenge->expires_at) <= $now) {
                $this->telemetry->mfa(['result' => 'expired']);

                return ['denied' => true];
            }

            $attempts = (int) $challenge->attempts + 1;
            $this->auth->bumpMfaChallengeAttempts($id, $attempts);

            $user = $this->identities->lockById(Identifier::fromTrusted((string) $challenge->user_id));
            $factor = $user === null ? null : $this->auth->activeTotp($user->id);

            if ($user === null || $factor === null || $attempts > 5) {
                $this->telemetry->mfa(['result' => 'denied']);

                return ['denied' => true];
            }

            if ($usingRecovery) {
                $row = $this->auth->lockRecoveryCode(
                    $user->id,
                    $this->credentials->hashRecoveryCode((string) $recoveryCode),
                );
                if ($row === null || ! $this->auth->consumeRecoveryCode(Identifier::fromTrusted((string) $row->id), $now)) {
                    $this->telemetry->mfa(['result' => 'denied']);

                    return ['denied' => true];
                }

                $this->auth->consumeMfaChallenge($id, $now);
                $this->telemetry->mfa(['result' => 'issued']);
                $this->audit->append($tx, 'auth.mfa_recovery_code_consumed', 'mfa_recovery_code', Identifier::fromTrusted((string) $row->id), [
                    'reason_code' => 'mfa_complete',
                ], $user->id, 'user');

                return ['denied' => false] + $this->sessions->issue(
                    $tx,
                    $user,
                    (string) $challenge->client_class,
                    (string) $challenge->platform,
                    (string) $challenge->device_label,
                    $now,
                    AssuranceLevel::Aal2RecoveryCode,
                );
            }

            $secret = $this->protector->decryptSecret('mfa_secret', (string) $factor->secret_ciphertext);
            $this->audit->append($tx, 'auth.sensitive_decrypt', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), [
                'reason_code' => 'totp_verify',
                'purpose' => 'mfa_secret',
            ], $user->id, 'user');
            $totp = $this->totp->verify(
                $secret,
                (string) $totpCode,
                $now,
                $factor->last_used_counter !== null ? (int) $factor->last_used_counter : null,
            );

            if (! $totp->valid || $totp->acceptedCounter === null) {
                $this->telemetry->mfa(['result' => 'denied']);

                return ['denied' => true];
            }

            $this->auth->updateTotpCounter(Identifier::fromTrusted((string) $factor->id), $totp->acceptedCounter, $now);
            $this->auth->consumeMfaChallenge($id, $now);
            $this->telemetry->mfa(['result' => 'issued']);

            return ['denied' => false] + $this->sessions->issue(
                $tx,
                $user,
                (string) $challenge->client_class,
                (string) $challenge->platform,
                (string) $challenge->device_label,
                $now,
                AssuranceLevel::Aal2Totp,
            );
        });

        if (($result['denied'] ?? false) === true) {
            throw new InvalidValueObject('The verification code is invalid or expired.');
        }

        unset($result['denied']);

        return $result;
    }
}
