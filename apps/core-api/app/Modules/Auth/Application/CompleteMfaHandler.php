<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthenticationRateLimiter;
use App\Modules\Auth\Domain\Contracts\AuthTelemetry;
use App\Modules\Auth\Domain\Contracts\TotpVerifier;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\ValueObjects\AssuranceLevel;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class CompleteMfaHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly TotpVerifier $totp,
        private readonly NationalIdProtector $protector,
        private readonly IssueAuthenticatedSession $sessions,
        private readonly Clock $clock,
        private readonly AuthTelemetry $telemetry,
        private readonly AuthenticationRateLimiter $rates,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $challengeId, string $code, string $ipPrefix = '0.0.0.0'): array
    {
        $id = Identifier::fromString($challengeId);
        $this->rates->hitMfa($id->value, $ipPrefix);

        $result = $this->transactions->run(function (TransactionContext $tx) use ($id, $code): array {
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

            $secret = $this->protector->decryptSecret('mfa_secret', (string) $factor->secret_ciphertext);
            $this->audit->append($tx, 'auth.sensitive_decrypt', 'mfa_factor', Identifier::fromTrusted((string) $factor->id), [
                'reason_code' => 'totp_verify',
                'purpose' => 'mfa_secret',
            ], $user->id, 'user');
            $totp = $this->totp->verify(
                $secret,
                $code,
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
