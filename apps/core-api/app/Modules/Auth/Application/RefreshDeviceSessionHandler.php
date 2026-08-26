<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Audit\Domain\Contracts\AppendAuditEvent;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\AuthenticationRateLimiter;
use App\Modules\Auth\Domain\Contracts\AuthTelemetry;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\FieldEncryptor;
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
        private readonly FieldEncryptor $encryptor,
        private readonly AuthenticationRateLimiter $rates,
        private readonly AppendAuditEvent $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $refreshToken, ?string $idempotencyKey, string $ipPrefix): array
    {
        $hash = $this->credentials->hashToken($refreshToken);
        $this->rates->hitRefresh('unbound', $ipPrefix);

        $result = $this->transactions->run(function (TransactionContext $tx) use ($hash, $idempotencyKey, $ipPrefix): array {
            $device = $this->auth->lockDeviceByRefreshHash($hash);
            $now = $this->clock->now();

            if ($device === null) {
                return ['denied' => true];
            }

            $this->rates->hitRefresh((string) $device->refresh_family_id, $ipPrefix);

            $presentedPrevious = is_string($device->previous_refresh_token_hash)
                && $device->previous_refresh_token_hash !== ''
                && hash_equals($device->previous_refresh_token_hash, $hash);
            $presentedCurrent = is_string($device->refresh_token_hash)
                && $device->refresh_token_hash !== ''
                && hash_equals($device->refresh_token_hash, $hash);
            $presentedOlder = $this->auth->consumedRefreshExists($hash) && ! $presentedCurrent && ! $presentedPrevious;

            if ($presentedCurrent || $presentedPrevious) {
                $replay = $this->replayIfGrace($device, $idempotencyKey, $now);
                if ($replay !== null) {
                    return $replay;
                }
            }

            if ($device->revoked_at !== null || $presentedOlder) {
                return $this->reuse($tx, $device, $now);
            }

            if ($presentedPrevious) {
                return $this->reuse($tx, $device, $now);
            }

            if (! $presentedCurrent) {
                return ['denied' => true];
            }

            $session = $this->auth->findActiveSessionByDevice(Identifier::fromTrusted((string) $device->id));
            if ($session === null) {
                return ['denied' => true];
            }

            $absolute = new DateTimeImmutable((string) $session->absolute_expires_at);
            if ($absolute <= $now) {
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
            $deviceId = Identifier::fromTrusted((string) $device->id);
            $refreshExpires = $now->modify(sprintf('+%d seconds', $refreshTtl));
            if ($refreshExpires > $absolute) {
                $refreshExpires = $absolute;
            }

            $this->auth->recordConsumedRefresh(
                (string) $device->refresh_family_id,
                $previous,
                (int) $device->refresh_generation,
                $now,
            );

            $this->auth->rotateDeviceTokens(
                $deviceId,
                $this->credentials->hashToken($access),
                $this->credentials->hashToken($refresh),
                $previous,
                ((int) $device->refresh_generation) + 1,
                $now->modify(sprintf('+%d seconds', $accessTtl)),
                $refreshExpires,
                $now,
            );
            $this->auth->updateSessionAccessHash($deviceId, $this->credentials->hashToken($access), $now);

            $envelope = $this->encryptor->encrypt('refresh_replay', json_encode([
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_in' => $accessTtl,
            ], JSON_THROW_ON_ERROR));
            $idemHmac = $this->credentials->hashToken('idem:'.($idempotencyKey ?? ''));
            $grace = (int) config('identity.refresh.replay_grace_seconds', 60);
            $this->auth->storeRefreshReplay(
                $deviceId,
                $idemHmac,
                $envelope,
                $now->modify(sprintf('+%d seconds', $grace)),
            );

            $this->audit->append($tx, 'auth.refresh_rotated', 'user_device', $deviceId, ['reason_code' => 'rotate'], $user->id, 'user');

            return [
                'denied' => false,
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_in' => $accessTtl,
                'session_kind' => 'device',
                'session_id' => $session->id,
                'device_id' => $device->id,
            ];
        });

        if (($result['denied'] ?? false) === true) {
            throw new AuthenticationFailed;
        }

        unset($result['denied']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replayIfGrace(object $device, ?string $idempotencyKey, DateTimeImmutable $now): ?array
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        $storedHmac = is_string($device->refresh_replay_idempotency_hmac ?? null)
            ? (string) $device->refresh_replay_idempotency_hmac
            : '';
        $cipher = is_string($device->refresh_replay_ciphertext ?? null)
            ? (string) $device->refresh_replay_ciphertext
            : '';
        $expires = isset($device->refresh_replay_expires_at)
            ? new DateTimeImmutable((string) $device->refresh_replay_expires_at)
            : null;

        if ($storedHmac === '' || $cipher === '' || $expires === null || $expires <= $now) {
            return null;
        }

        $presented = $this->credentials->hashToken('idem:'.$idempotencyKey);
        if (! hash_equals($storedHmac, $presented)) {
            return null;
        }

        $decoded = json_decode($this->encryptor->decrypt('refresh_replay', $cipher), true);
        if (! is_array($decoded) || ! isset($decoded['access_token'], $decoded['refresh_token'])) {
            return null;
        }

        return [
            'denied' => false,
            'access_token' => $decoded['access_token'],
            'refresh_token' => $decoded['refresh_token'],
            'expires_in' => (int) ($decoded['expires_in'] ?? 900),
            'session_kind' => 'device',
            'device_id' => $device->id,
            'idempotent_replay' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reuse(TransactionContext $tx, object $device, DateTimeImmutable $now): array
    {
        $session = $this->auth->findActiveSessionByDevice(Identifier::fromTrusted((string) $device->id));
        $sessionId = $session !== null
            ? Identifier::fromTrusted((string) $session->id)
            : Identifier::fromTrusted((string) $device->id);

        $this->sessions->revokeFamily(
            $tx,
            (string) $device->refresh_family_id,
            Identifier::fromTrusted((string) $device->user_id),
            $sessionId,
            'refresh_reuse',
            $now,
        );
        $this->auth->revokeSessionsForDevice(Identifier::fromTrusted((string) $device->id), 'refresh_reuse', $now);
        $this->telemetry->authAttempt(['result' => 'refresh_reuse', 'method' => 'refresh', 'actor_class' => 'unknown']);
        $this->audit->append(
            $tx,
            'auth.refresh_reuse',
            'user_device',
            Identifier::fromTrusted((string) $device->id),
            ['reason_code' => 'refresh_reuse'],
            Identifier::fromTrusted((string) $device->user_id),
            'user',
        );

        return ['denied' => true];
    }
}
