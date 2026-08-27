<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use DateTimeImmutable;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Enums\OtpPurpose;
use Modules\Auth\Events\CredentialVersionChanged;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\RecordInboxNotification;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Support\Identifier;

final class CompleteRecoveryService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly CredentialIssuer $credentials,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly Clock $clock,
        private readonly AuthenticationRateLimiter $rates,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly RecordInboxNotification $inbox,
    ) {}

    /**
     * @return array{status: string}
     */
    public function handle(string $challengeId, string $code, string $password, string $ipPrefix = '0.0.0.0'): array
    {
        if (! PlatformFeatures::enabled(PlatformFeatures::AUTH_RECOVERY)) {
            throw new FeatureUnavailable;
        }

        $this->policy->assert($password);
        $this->rates->hitRecovery('complete:'.strtolower(trim($challengeId)), $ipPrefix);
        $id = Identifier::fromString($challengeId);

        $result = $this->transactions->run(function (TransactionContext $tx) use ($id, $code, $password): array {
            $row = $this->auth->lockOtp($id);
            $now = $this->clock->now();

            if ($row === null || (string) $row->purpose !== OtpPurpose::Recovery->value) {
                return ['denied' => true];
            }

            $hmac = (string) $row->subject_lookup_hmac;
            $this->rates->hitRecovery($hmac);

            if ($row->consumed_at !== null || $row->invalidated_at !== null || new DateTimeImmutable((string) $row->expires_at) <= $now) {
                return ['denied' => true];
            }

            $attempts = (int) $row->attempts + 1;
            $this->auth->incrementOtpAttempts($id, $attempts);

            $expected = (string) $row->code_hash;
            if (! hash_equals($expected, $this->credentials->hashOtp((string) $row->id, (string) $row->purpose, $code))
                || $attempts > (int) $row->max_attempts) {
                return ['denied' => true];
            }

            $this->auth->consumeOtp($id, $now);
            $user = $this->identities->findByPhoneHmac($hmac);

            if ($user === null) {
                return ['denied' => false, 'status' => 'completed'];
            }

            $passwordHash = $this->hasher->hash($password);
            $requestId = $this->ids->next();
            $privileged = in_array($user->accountType->value, ['admin', 'doctor', 'pharmacy', 'secretary'], true);
            $cooling = (int) config('identity.recovery.cooling_off_seconds', 86400);

            if ($privileged) {
                $this->auth->insertRecoveryRequest(
                    $requestId,
                    $user->id,
                    $id,
                    'manual_review',
                    $passwordHash,
                    null,
                    null,
                    $now,
                );
                $this->audit->append($tx, 'auth.recovery_manual_review', 'user', $user->id, ['reason_code' => 'privileged_recovery'], $user->id, 'user');
                $this->inbox->record('user', $user->id->value, 'auth.recovery_manual_review', [
                    'recovery_request_id' => $requestId->value,
                    'status' => 'manual_review',
                ]);

                return ['denied' => false, 'status' => 'manual_review'];
            }

            $until = $cooling > 0 ? $now->modify(sprintf('+%d seconds', $cooling)) : null;
            $appliedAt = $cooling > 0 ? null : $now;
            $status = $cooling > 0 ? 'cooling_off' : 'applied';

            $this->auth->insertRecoveryRequest(
                $requestId,
                $user->id,
                $id,
                $status,
                $passwordHash,
                $until,
                $appliedAt,
                $now,
            );

            if ($status !== 'applied') {
                $this->audit->append($tx, 'auth.recovery_cooling_off', 'user', $user->id, ['reason_code' => 'cooling_off'], $user->id, 'user');
                $this->inbox->record('user', $user->id->value, 'auth.recovery_cooling_off', [
                    'recovery_request_id' => $requestId->value,
                    'status' => 'cooling_off',
                ]);

                return ['denied' => false, 'status' => 'cooling_off'];
            }

            $version = $user->credentialVersion + 1;
            $this->identities->replacePassword($user->id, $passwordHash, $version, $now);
            $this->auth->revokeAllSessions($user->id, 'recovery', $now);
            $this->auth->revokeAllDevices($user->id, 'recovery', $now);
            $tx->recordEvent(new CredentialVersionChanged($user->id, $version, 'recovery', $now));
            $this->audit->append($tx, 'auth.recovery_completed', 'user', $user->id, ['reason_code' => 'recovery'], $user->id, 'user');

            return ['denied' => false, 'status' => 'applied'];
        });

        if (($result['denied'] ?? false) === true) {
            throw new InvalidValueObject('The verification code is invalid or expired.');
        }

        return ['status' => (string) ($result['status'] ?? 'completed')];
    }
}
