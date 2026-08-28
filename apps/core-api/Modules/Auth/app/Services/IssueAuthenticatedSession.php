<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use DateTimeImmutable;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Enums\ClientClass;
use Modules\Auth\Enums\DevicePlatform;
use Modules\Auth\Events\SessionRevoked;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Exceptions\AuthenticationFailed;
use Modules\Platform\Support\Identifier;

final class IssueAuthenticatedSession
{
    public function __construct(
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly CredentialIssuer $credentials,
        private readonly IdentityGenerator $ids,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function issue(
        TransactionContext $tx,
        UserAccount $user,
        string $clientClass,
        string $platform,
        string $deviceLabel,
        DateTimeImmutable $now,
        ?AssuranceLevel $assurance = null,
    ): array {
        $class = ClientClass::from($clientClass);
        $devicePlatform = DevicePlatform::from($platform);
        $needsTotp = $user->accountType->requiresTotpForPrivilegedSession();
        $assurance ??= AssuranceLevel::Aal1Password;

        if (! $class->compatibleWith($user->accountType->value)) {
            throw new AuthenticationFailed;
        }

        if ($needsTotp && ! $assurance->satisfiesPrivilegedSession()) {
            $challengeId = $this->ids->next();
            $this->auth->insertMfaChallenge([
                'id' => $challengeId->value,
                'user_id' => $user->id->value,
                'client_class' => $class->value,
                'platform' => $devicePlatform->value,
                'device_label' => $deviceLabel,
                'expires_at' => $now->modify('+'.((int) config('identity.mfa.challenge_ttl_seconds', 300)).' seconds')->format('Y-m-d H:i:s.uP'),
                'consumed_at' => null,
                'attempts' => 0,
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);

            return [
                'mfa_required' => true,
                'challenge_id' => $challengeId->value,
                'status' => 'mfa_required',
            ];
        }

        $this->identities->touchAuthenticated($user->id, $now);

        $sessionId = $this->ids->next();
        $deviceId = $this->ids->next();
        $issuedAssurance = $needsTotp ? AssuranceLevel::Aal2Totp : AssuranceLevel::Aal1Password;
        $capabilities = Capabilities::forActor(
            $user->accountType->value,
            $issuedAssurance->satisfiesPrivilegedSession(),
            $user->passwordMustChange,
        );

        if ($class->usesCookieSession()) {
            $sessionHash = $this->credentials->hashToken('cookie:'.$sessionId->value);
            $idle = (int) config('identity.session.admin_idle_seconds', 1800);
            $absolute = (int) config('identity.session.admin_absolute_seconds', 28800);
            $this->auth->insertSession([
                'id' => $sessionId->value,
                'user_id' => $user->id->value,
                'device_id' => null,
                'session_kind' => 'admin_cookie',
                'session_hash' => $sessionHash,
                'assurance_level' => $issuedAssurance->value,
                'csrf_established' => true,
                'idle_expires_at' => $now->modify(sprintf('+%d seconds', $idle))->format('Y-m-d H:i:s.uP'),
                'absolute_expires_at' => $now->modify(sprintf('+%d seconds', $absolute))->format('Y-m-d H:i:s.uP'),
                'credential_version' => $user->credentialVersion,
                'revoked_at' => null,
                'revoked_reason' => null,
                'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);

            $this->audit->append($tx, 'auth.session_issued', 'auth_session', $sessionId, [
                'reason_code' => 'issued',
                'session_kind' => 'admin_cookie',
            ], $user->id, 'user');

            return [
                'mfa_required' => false,
                'session_kind' => 'admin_cookie',
                'session_id' => $sessionId->value,
                'user_id' => $user->id->value,
                'status' => $user->status->value,
                'capabilities' => $capabilities,
                'assurance_level' => $issuedAssurance->value,
                'password_must_change' => $user->passwordMustChange,
            ];
        }

        $access = $this->credentials->randomToken();
        $refresh = $this->credentials->randomToken();
        $family = $this->ids->next();
        $accessTtl = (int) config('identity.session.device_access_ttl_seconds', 900);
        $refreshTtl = (int) config('identity.session.device_refresh_ttl_seconds', 2592000);

        $this->auth->insertDevice([
            'id' => $deviceId->value,
            'user_id' => $user->id->value,
            'platform' => $devicePlatform->value,
            'device_label' => $deviceLabel,
            'token_hash' => $this->credentials->hashToken($access),
            'refresh_token_hash' => $this->credentials->hashToken($refresh),
            'previous_refresh_token_hash' => null,
            'refresh_family_id' => $family->value,
            'refresh_generation' => 1,
            'credential_version' => $user->credentialVersion,
            'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->modify(sprintf('+%d seconds', $accessTtl))->format('Y-m-d H:i:s.uP'),
            'refresh_expires_at' => $now->modify(sprintf('+%d seconds', $refreshTtl))->format('Y-m-d H:i:s.uP'),
            'revoked_at' => null,
            'revoked_reason' => null,
            'push_token_ciphertext' => null,
            'created_ip_prefix' => null,
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);

        $this->auth->insertSession([
            'id' => $sessionId->value,
            'user_id' => $user->id->value,
            'device_id' => $deviceId->value,
            'session_kind' => 'device',
            'session_hash' => $this->credentials->hashToken($access),
            'assurance_level' => $issuedAssurance->value,
            'csrf_established' => false,
            'idle_expires_at' => null,
            'absolute_expires_at' => $now->modify(sprintf('+%d seconds', $refreshTtl))->format('Y-m-d H:i:s.uP'),
            'credential_version' => $user->credentialVersion,
            'revoked_at' => null,
            'revoked_reason' => null,
            'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);

        $this->audit->append($tx, 'auth.session_issued', 'auth_session', $sessionId, [
            'reason_code' => 'issued',
            'session_kind' => 'device',
        ], $user->id, 'user');

        return [
            'mfa_required' => false,
            'session_kind' => 'device',
            'session_id' => $sessionId->value,
            'device_id' => $deviceId->value,
            'user_id' => $user->id->value,
            'status' => $user->status->value,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $accessTtl,
            'capabilities' => $capabilities,
            'assurance_level' => $issuedAssurance->value,
            'password_must_change' => $user->passwordMustChange,
        ];
    }

    public function revokeFamily(TransactionContext $tx, string $familyId, Identifier $userId, Identifier $sessionId, string $reason, DateTimeImmutable $now): void
    {
        $this->auth->revokeDeviceFamily($familyId, $reason, $now);
        $this->auth->revokeSession($sessionId, $reason, $now);
        $tx->recordEvent(new SessionRevoked($userId, $sessionId, $reason, $now));
    }
}
