<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthTelemetry;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Exceptions\AuthenticationFailed;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final class RefreshDeviceSessionHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly AuthDirectory $auth,
        private readonly UserDirectory $identities,
        private readonly CredentialIssuer $credentials,
        private readonly Clock $clock,
        private readonly IssueAuthenticatedSession $sessions,
        private readonly AuthTelemetry $telemetry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $refreshToken): array
    {
        $hash = $this->credentials->hashToken($refreshToken);

        $result = $this->transactions->run(function (TransactionContext $tx) use ($hash): array {
            $device = $this->auth->lockDeviceByRefreshHash($hash);
            $now = $this->clock->now();

            if ($device === null) {
                return ['denied' => true];
            }

            $presentedPrevious = is_string($device->previous_refresh_token_hash)
                && $device->previous_refresh_token_hash !== ''
                && hash_equals($device->previous_refresh_token_hash, $hash);
            $presentedCurrent = is_string($device->refresh_token_hash)
                && $device->refresh_token_hash !== ''
                && hash_equals($device->refresh_token_hash, $hash);

            if ($device->revoked_at !== null || ($presentedPrevious && ! $presentedCurrent)) {
                $this->sessions->revokeFamily(
                    $tx,
                    (string) $device->refresh_family_id,
                    Identifier::fromTrusted((string) $device->user_id),
                    Identifier::fromTrusted((string) $device->id),
                    'refresh_reuse',
                    $now,
                );
                $this->telemetry->authAttempt(['result' => 'refresh_reuse', 'method' => 'refresh', 'actor_class' => 'unknown']);

                // Must commit the revoke. Throwing here would roll it back and
                // leave the rotated refresh token valid.
                return ['denied' => true];
            }

            if (! $presentedCurrent) {
                return ['denied' => true];
            }

            if ($device->refresh_expires_at !== null && new DateTimeImmutable((string) $device->refresh_expires_at) <= $now) {
                return ['denied' => true];
            }

            $user = $this->identities->findById(Identifier::fromTrusted((string) $device->user_id));

            if ($user === null || ! $user->status->canReceiveDeviceSession() || $user->credentialVersion !== (int) $device->credential_version) {
                return ['denied' => true];
            }

            $access = $this->credentials->randomToken();
            $refresh = $this->credentials->randomToken();
            $accessTtl = (int) config('identity.session.device_access_ttl_seconds', 900);
            $refreshTtl = (int) config('identity.session.device_refresh_ttl_seconds', 2592000);
            $previous = (string) $device->refresh_token_hash;

            $this->auth->rotateDeviceTokens(
                Identifier::fromTrusted((string) $device->id),
                $this->credentials->hashToken($access),
                $this->credentials->hashToken($refresh),
                $previous,
                ((int) $device->refresh_generation) + 1,
                $now->modify(sprintf('+%d seconds', $accessTtl)),
                $now->modify(sprintf('+%d seconds', $refreshTtl)),
                $now,
            );

            return [
                'denied' => false,
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_in' => $accessTtl,
                'session_kind' => 'device',
                'device_id' => $device->id,
            ];
        });

        if (($result['denied'] ?? false) === true) {
            throw new AuthenticationFailed;
        }

        unset($result['denied']);

        return $result;
    }
}
