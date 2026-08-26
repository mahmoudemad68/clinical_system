<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthenticationRateLimiter;
use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use App\Modules\Auth\Domain\Events\CredentialVersionChanged;
use App\Modules\Auth\Domain\Rules\PasswordPolicy;
use App\Modules\Auth\Domain\ValueObjects\OtpPurpose;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\FeatureUnavailable;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class CompleteRecoveryHandler
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
    ) {}

    public function handle(string $challengeId, string $code, string $password): void
    {
        if (! PlatformFeatures::enabled(PlatformFeatures::AUTH_RECOVERY)) {
            throw new FeatureUnavailable;
        }

        $this->policy->assert($password);
        $id = Identifier::fromString($challengeId);

        $this->transactions->run(function (TransactionContext $tx) use ($id, $code, $password): void {
            $row = $this->auth->lockOtp($id);
            $now = $this->clock->now();

            if ($row === null || (string) $row->purpose !== OtpPurpose::Recovery->value) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            $hmac = (string) $row->subject_lookup_hmac;
            $this->rates->hitRecovery($hmac);

            if ($row->consumed_at !== null || $row->invalidated_at !== null || new DateTimeImmutable((string) $row->expires_at) <= $now) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            $expected = (string) $row->code_hash;
            if (! hash_equals($expected, $this->credentials->hashOtp((string) $row->id, (string) $row->purpose, $code))) {
                throw new InvalidValueObject('The verification code is invalid or expired.');
            }

            $this->auth->consumeOtp($id, $now);
            $user = $this->identities->findByPhoneHmac($hmac);

            if ($user === null) {
                return;
            }

            $version = $user->credentialVersion + 1;
            $this->identities->replacePassword($user->id, $this->hasher->hash($password), $version, $now);
            $this->auth->revokeAllSessions($user->id, 'recovery', $now);
            $this->auth->revokeAllDevices($user->id, 'recovery', $now);
            $tx->recordEvent(new CredentialVersionChanged($user->id, $version, 'recovery', $now));
            $this->audit->append($tx, 'auth.recovery_completed', 'user', $user->id, ['reason_code' => 'recovery'], $user->id, 'user');
        });
    }
}
