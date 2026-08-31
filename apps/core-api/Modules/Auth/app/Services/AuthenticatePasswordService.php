<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\AuthTelemetry;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Enums\ClientClass;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthenticationFailed;

final class AuthenticatePasswordService
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly NationalIdProtector $protector,
        private readonly UserDirectory $identities,
        private readonly PasswordHasher $hasher,
        private readonly AuthenticationRateLimiter $rates,
        private readonly IssueAuthenticatedSession $sessions,
        private readonly Clock $clock,
        private readonly AuthTelemetry $telemetry,
        private readonly RecordPrivilegedFailure $privilegedFailures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $phone, string $password, string $clientClass, string $platform, string $deviceLabel, ?string $ipPrefix): array
    {
        $parsed = $this->protector->phone($phone);
        $hmac = $this->protector->phoneHmac($parsed);
        $this->rates->hitLogin($hmac, $ipPrefix ?? '0.0.0.0');

        $user = $this->identities->findByPhoneHmacs($this->protector->phoneLookupHmacs($parsed));

        if ($user === null) {
            $this->hasher->dummyVerify($password);
            $this->telemetry->authAttempt(['result' => 'unknown', 'method' => 'password', 'actor_class' => 'unknown']);
            throw new AuthenticationFailed;
        }

        $verified = $this->hasher->verify($password, $user->passwordHash);
        if (! $verified || ! $user->status->canReceiveDeviceSession()) {
            $this->telemetry->authAttempt(['result' => 'denied', 'method' => 'password', 'actor_class' => $user->accountType->actorClass()]);
            $this->recordPrivilegedAuthenticationFailure(
                $user,
                $verified ? 'account_not_eligible' : 'invalid_credentials',
            );
            throw new AuthenticationFailed;
        }

        if (! ClientClass::from($clientClass)->compatibleWith($user->accountType->value)) {
            $this->telemetry->authAttempt(['result' => 'denied', 'method' => 'password', 'actor_class' => $user->accountType->actorClass()]);
            $this->recordPrivilegedAuthenticationFailure($user, 'client_mismatch');
            throw new AuthenticationFailed;
        }

        $result = $this->transactions->run(function (TransactionContext $tx) use ($user, $clientClass, $platform, $deviceLabel) {
            return $this->sessions->issue($tx, $user, $clientClass, $platform, $deviceLabel, $this->clock->now());
        });

        $this->telemetry->authAttempt([
            'result' => ($result['mfa_required'] ?? false) === true ? 'mfa_required' : 'issued',
            'method' => 'password',
            'actor_class' => $user->accountType->actorClass(),
        ]);

        return $result;
    }

    private function recordPrivilegedAuthenticationFailure(UserAccount $user, string $reasonCode): void
    {
        if (! $user->accountType->isPrivilegedStaff()) {
            return;
        }

        $this->privilegedFailures->authenticationFailed(
            $user->id,
            $user->accountType->value,
            $reasonCode,
            'password',
        );
    }
}
