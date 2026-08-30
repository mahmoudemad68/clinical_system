<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresAuthStore implements AuthDirectory
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function insertOtp(
        Identifier $id,
        string $purpose,
        string $subjectHmac,
        string $codeHash,
        string $codeCipher,
        int $maxAttempts,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
        ?string $ipPrefix,
        ?string $deviceFingerprintHmac,
        string $locale,
        string $destinationCipher,
        int $keyVersion,
    ): void {
        $this->connection->table('otp_requests')->insert([
            'id' => $id->value,
            'purpose' => $purpose,
            'subject_lookup_hmac' => BinaryColumn::bind($subjectHmac),
            'code_hash' => BinaryColumn::bind($codeHash),
            'code_ciphertext' => BinaryColumn::bind($codeCipher),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            'consumed_at' => null,
            'invalidated_at' => null,
            'requested_ip_prefix' => $ipPrefix,
            'device_fingerprint_hmac' => $deviceFingerprintHmac === null ? null : BinaryColumn::bind($deviceFingerprintHmac),
            'provider_message_reference' => null,
            'locale' => $locale,
            'destination_ciphertext' => BinaryColumn::bind($destinationCipher),
            'key_version' => $keyVersion,
            'delivery_status' => 'pending',
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function invalidateOpenOtps(string $subjectHmac, string $purpose, DateTimeImmutable $now): void
    {
        $this->connection->table('otp_requests')
            ->where('subject_lookup_hmac', BinaryColumn::bind($subjectHmac))
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update([
                'invalidated_at' => $now->format('Y-m-d H:i:s.uP'),
                'code_ciphertext' => null,
                'destination_ciphertext' => null,
            ]);
    }

    public function lockOtp(Identifier $id): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM otp_requests WHERE id = ? FOR UPDATE',
            [$id->value],
        );

        return $row instanceof stdClass ? $this->normalizeOtp($row) : null;
    }

    public function incrementOtpAttempts(Identifier $id, int $attempts): void
    {
        $this->connection->table('otp_requests')->where('id', $id->value)->update([
            'attempts' => $attempts,
        ]);
    }

    public function consumeOtp(Identifier $id, DateTimeImmutable $now): void
    {
        $this->connection->table('otp_requests')->where('id', $id->value)->update([
            'consumed_at' => $now->format('Y-m-d H:i:s.uP'),
            'code_ciphertext' => null,
            'destination_ciphertext' => null,
        ]);
    }

    public function markOtpDelivery(Identifier $id, string $status, ?string $reference): void
    {
        $this->connection->table('otp_requests')->where('id', $id->value)->update([
            'delivery_status' => $status,
            'provider_message_reference' => $reference,
        ]);
    }

    public function otpById(Identifier $id): ?stdClass
    {
        $row = $this->connection->table('otp_requests')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->normalizeOtp($row) : null;
    }

    public function recoveryRequestById(Identifier $id): ?stdClass
    {
        $row = $this->connection->table('recovery_requests')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $row : null;
    }

    public function pushTokenCiphers(Identifier $userId): array
    {
        $ciphers = [];
        $rows = $this->connection->table('user_devices')
            ->where('user_id', $userId->value)
            ->whereNotNull('push_token_ciphertext')
            ->orderBy('id')
            ->get(['push_token_ciphertext']);

        foreach ($rows as $row) {
            $cipher = BinaryColumn::asString($row->push_token_ciphertext);
            if ($cipher !== '') {
                $ciphers[] = $cipher;
            }
        }

        return $ciphers;
    }

    public function insertDevice(array $row): void
    {
        $this->connection->table('user_devices')->insert($this->bindDeviceRow($row));
    }

    public function lockDeviceByRefreshHash(string $hash): ?stdClass
    {
        $bound = BinaryColumn::bind($hash);
        $row = $this->connection->selectOne(
            'SELECT * FROM user_devices
             WHERE id = (
                SELECT id FROM (
                    SELECT id FROM user_devices
                    WHERE refresh_token_hash = ? OR previous_refresh_token_hash = ?
                    UNION
                    SELECT d.id FROM user_devices d
                    INNER JOIN auth_refresh_consumptions c ON c.family_id = d.refresh_family_id
                    WHERE c.token_hash = ?
                ) candidates
                LIMIT 1
             )
             FOR UPDATE',
            [$bound, $bound, $bound],
        );

        return $row instanceof stdClass ? $this->normalizeDevice($row) : null;
    }

    public function lockDevice(Identifier $id): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM user_devices WHERE id = ? FOR UPDATE',
            [$id->value],
        );

        return $row instanceof stdClass ? $this->normalizeDevice($row) : null;
    }

    public function lockSession(Identifier $id): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM auth_sessions WHERE id = ? FOR UPDATE',
            [$id->value],
        );

        return $row instanceof stdClass ? $this->normalizeSession($row) : null;
    }

    public function recordConsumedRefresh(string $familyId, string $tokenHash, int $generation, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_refresh_consumptions')->insertOrIgnore([
            'family_id' => $familyId,
            'token_hash' => BinaryColumn::bind($tokenHash),
            'generation' => $generation,
            'consumed_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function consumedRefreshExists(string $hash): bool
    {
        return $this->connection->table('auth_refresh_consumptions')
            ->where('token_hash', BinaryColumn::bind($hash))
            ->exists();
    }

    public function storeRefreshReplay(
        Identifier $deviceId,
        string $idempotencyHmac,
        string $cipher,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->connection->table('user_devices')->where('id', $deviceId->value)->update([
            'refresh_replay_ciphertext' => BinaryColumn::bind($cipher),
            'refresh_replay_idempotency_hmac' => BinaryColumn::bind($idempotencyHmac),
            'refresh_replay_expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function clearRefreshReplay(Identifier $deviceId): void
    {
        $this->connection->table('user_devices')->where('id', $deviceId->value)->update([
            'refresh_replay_ciphertext' => null,
            'refresh_replay_idempotency_hmac' => null,
            'refresh_replay_expires_at' => null,
        ]);
    }

    public function findDeviceByAccessHash(string $hash): ?stdClass
    {
        $row = $this->connection->table('user_devices')
            ->where('token_hash', BinaryColumn::bind($hash))
            ->whereNull('revoked_at')
            ->first();

        return $row instanceof stdClass ? $this->normalizeDevice($row) : null;
    }

    public function rotateDeviceTokens(
        Identifier $deviceId,
        string $accessHash,
        string $refreshHash,
        string $previousRefreshHash,
        int $generation,
        DateTimeImmutable $accessExpires,
        DateTimeImmutable $refreshExpires,
        DateTimeImmutable $now,
    ): void {
        $this->connection->table('user_devices')->where('id', $deviceId->value)->update([
            'token_hash' => BinaryColumn::bind($accessHash),
            'refresh_token_hash' => BinaryColumn::bind($refreshHash),
            'previous_refresh_token_hash' => BinaryColumn::bind($previousRefreshHash),
            'refresh_generation' => $generation,
            'expires_at' => $accessExpires->format('Y-m-d H:i:s.uP'),
            'refresh_expires_at' => $refreshExpires->format('Y-m-d H:i:s.uP'),
            'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function findActiveSessionByDevice(Identifier $deviceId): ?stdClass
    {
        $row = $this->connection->table('auth_sessions')
            ->where('device_id', $deviceId->value)
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->first();

        return $row instanceof stdClass ? $this->normalizeSession($row) : null;
    }

    public function bindCookieSessionHash(Identifier $sessionId, string $hash, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')->where('id', $sessionId->value)->update([
            'session_hash' => BinaryColumn::bind($hash),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function updateSessionAccessHash(Identifier $deviceId, string $accessHash, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')
            ->where('device_id', $deviceId->value)
            ->whereNull('revoked_at')
            ->update([
                'session_hash' => BinaryColumn::bind($accessHash),
                'last_seen_at' => $now->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function revokeDeviceFamily(string $familyId, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('user_devices')
            ->where('refresh_family_id', $familyId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
                'revoked_reason' => $reason,
                'token_hash' => null,
                'refresh_token_hash' => null,
                'previous_refresh_token_hash' => null,
                'push_token_ciphertext' => null,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function revokeDevice(Identifier $deviceId, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('user_devices')->where('id', $deviceId->value)->update([
            'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
            'revoked_reason' => $reason,
            'token_hash' => null,
            'refresh_token_hash' => null,
            'previous_refresh_token_hash' => null,
            'push_token_ciphertext' => null,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function revokeAllDevices(Identifier $userId, string $reason, DateTimeImmutable $now): int
    {
        $update = [
            'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
            'revoked_reason' => $reason,
            'token_hash' => null,
            'refresh_token_hash' => null,
            'previous_refresh_token_hash' => null,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ];

        if ($reason !== 'recovery') {
            $update['push_token_ciphertext'] = null;
        }

        return $this->connection->table('user_devices')
            ->where('user_id', $userId->value)
            ->whereNull('revoked_at')
            ->update($update);
    }

    public function insertSession(array $row): void
    {
        if (isset($row['session_hash']) && is_string($row['session_hash'])) {
            $row['session_hash'] = BinaryColumn::bind($row['session_hash']);
        }

        $this->connection->table('auth_sessions')->insert($row);
    }

    public function findSession(Identifier $id): ?stdClass
    {
        $row = $this->connection->table('auth_sessions')->where('id', $id->value)->first();

        return $row instanceof stdClass ? $row : null;
    }

    public function findSessionByHash(string $hash): ?stdClass
    {
        $row = $this->connection->table('auth_sessions')
            ->where('session_hash', BinaryColumn::bind($hash))
            ->whereNull('revoked_at')
            ->first();

        return $row instanceof stdClass ? $this->normalizeSession($row) : null;
    }

    public function latestCookieSession(Identifier $userId): ?stdClass
    {
        $row = $this->connection->table('auth_sessions')
            ->where('user_id', $userId->value)
            ->where('session_kind', 'admin_cookie')
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->first();

        return $row instanceof stdClass ? $this->normalizeSession($row) : null;
    }

    public function listSessions(Identifier $userId): array
    {
        return $this->connection->table('auth_sessions')
            ->leftJoin('user_devices', 'user_devices.id', '=', 'auth_sessions.device_id')
            ->where('auth_sessions.user_id', $userId->value)
            ->whereNull('auth_sessions.revoked_at')
            ->orderByDesc('auth_sessions.last_seen_at')
            ->select([
                'auth_sessions.id',
                'auth_sessions.session_kind',
                'auth_sessions.assurance_level',
                'auth_sessions.last_seen_at',
                'auth_sessions.created_at',
                'user_devices.platform as device_platform',
                'user_devices.device_label as device_label',
            ])
            ->get()
            ->all();
    }

    public function countActiveSessions(DateTimeImmutable $now): int
    {
        return $this->connection->table('auth_sessions')
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', $now->format('Y-m-d H:i:s.uP'))
            ->count();
    }

    public function revokeSession(Identifier $id, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')->where('id', $id->value)->update([
            'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
            'revoked_reason' => $reason,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function revokeSessionsForDevice(Identifier $deviceId, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')
            ->where('device_id', $deviceId->value)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
                'revoked_reason' => $reason,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function revokeAllSessions(Identifier $userId, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')
            ->where('user_id', $userId->value)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
                'revoked_reason' => $reason,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function revokeOtherSessions(Identifier $userId, Identifier $keepSessionId, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')
            ->where('user_id', $userId->value)
            ->where('id', '!=', $keepSessionId->value)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
                'revoked_reason' => $reason,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function revokeOtherDevices(Identifier $userId, Identifier $keepDeviceId, string $reason, DateTimeImmutable $now): void
    {
        $this->connection->table('user_devices')
            ->where('user_id', $userId->value)
            ->where('id', '!=', $keepDeviceId->value)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
                'revoked_reason' => $reason,
                'token_hash' => null,
                'refresh_token_hash' => null,
                'previous_refresh_token_hash' => null,
                'push_token_ciphertext' => null,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function updateSessionAssurance(Identifier $sessionId, string $assuranceLevel, DateTimeImmutable $now): void
    {
        $this->connection->table('auth_sessions')->where('id', $sessionId->value)->update([
            'assurance_level' => $assuranceLevel,
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function insertMfaChallenge(array $row): void
    {
        $this->connection->table('mfa_challenges')->insert($row);
    }

    public function lockMfaChallenge(Identifier $id): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM mfa_challenges WHERE id = ? FOR UPDATE',
            [$id->value],
        );

        return $row instanceof stdClass ? $row : null;
    }

    public function consumeMfaChallenge(Identifier $id, DateTimeImmutable $now): void
    {
        $this->connection->table('mfa_challenges')->where('id', $id->value)->update([
            'consumed_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function bumpMfaChallengeAttempts(Identifier $id, int $attempts): void
    {
        $this->connection->table('mfa_challenges')->where('id', $id->value)->update([
            'attempts' => $attempts,
        ]);
    }

    public function activeTotp(Identifier $userId): ?stdClass
    {
        $row = $this->connection->table('mfa_factors')
            ->where('user_id', $userId->value)
            ->where('factor_type', 'totp')
            ->whereNull('disabled_at')
            ->whereNotNull('verified_at')
            ->first();

        return $row instanceof stdClass ? $this->normalizeFactor($row) : null;
    }

    public function updateTotpCounter(Identifier $factorId, int $counter, DateTimeImmutable $now): void
    {
        $this->connection->table('mfa_factors')->where('id', $factorId->value)->update([
            'last_used_counter' => $counter,
            'last_used_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function insertTotpFactor(array $row): void
    {
        if (isset($row['secret_ciphertext']) && is_string($row['secret_ciphertext'])) {
            $row['secret_ciphertext'] = BinaryColumn::bind($row['secret_ciphertext']);
        }

        $this->connection->table('mfa_factors')->insert($row);
    }

    public function pendingTotp(Identifier $userId): ?stdClass
    {
        $row = $this->connection->table('mfa_factors')
            ->where('user_id', $userId->value)
            ->where('factor_type', 'totp')
            ->whereNull('disabled_at')
            ->whereNull('verified_at')
            ->first();

        return $row instanceof stdClass ? $this->normalizeFactor($row) : null;
    }

    public function lockEnabledTotpFactors(Identifier $userId): array
    {
        $rows = $this->connection->table('mfa_factors')
            ->where('user_id', $userId->value)
            ->where('factor_type', 'totp')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $factors = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $factors[] = $this->normalizeFactor($row);
            }
        }

        return $factors;
    }

    public function discardUnverifiedTotp(Identifier $factorId, Identifier $disabledBy, DateTimeImmutable $now): void
    {
        $tombstone = random_bytes(32);
        $this->connection->table('mfa_factors')
            ->where('id', $factorId->value)
            ->whereNull('verified_at')
            ->whereNull('disabled_at')
            ->update([
                'disabled_at' => $now->format('Y-m-d H:i:s.uP'),
                'disabled_by' => $disabledBy->value,
                'secret_ciphertext' => BinaryColumn::bind($tombstone),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function markTotpVerified(Identifier $factorId, DateTimeImmutable $now): void
    {
        $this->connection->table('mfa_factors')->where('id', $factorId->value)->update([
            'verified_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function insertRecoveryCodes(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach ($rows as $i => $row) {
            if (isset($row['code_hash']) && is_string($row['code_hash'])) {
                $rows[$i]['code_hash'] = BinaryColumn::bind($row['code_hash']);
            }
        }

        $this->connection->table('mfa_recovery_codes')->insert($rows);
    }

    public function lockRecoveryCode(Identifier $userId, string $codeHash): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM mfa_recovery_codes WHERE user_id = ? AND code_hash = ? AND consumed_at IS NULL FOR UPDATE',
            [$userId->value, BinaryColumn::bind($codeHash)],
        );

        return $row instanceof stdClass ? $row : null;
    }

    public function consumeRecoveryCode(Identifier $id, DateTimeImmutable $now): bool
    {
        return $this->connection->table('mfa_recovery_codes')
            ->where('id', $id->value)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => $now->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function insertRecoveryRequest(
        Identifier $id,
        Identifier $userId,
        Identifier $otpId,
        string $status,
        string $passwordHash,
        ?DateTimeImmutable $coolingOffUntil,
        ?DateTimeImmutable $appliedAt,
        DateTimeImmutable $now,
    ): void {
        $this->connection->table('recovery_requests')->insert([
            'id' => $id->value,
            'user_id' => $userId->value,
            'otp_id' => $otpId->value,
            'status' => $status,
            'new_password_hash' => $passwordHash,
            'cooling_off_until' => $coolingOffUntil?->format('Y-m-d H:i:s.uP'),
            'applied_at' => $appliedAt?->format('Y-m-d H:i:s.uP'),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function pruneExpiredOtps(DateTimeImmutable $now): int
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $invalidated = $this->connection->table('otp_requests')
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '<', $stamp)
            ->update([
                'invalidated_at' => $stamp,
            ]);

        $this->connection->table('otp_requests')
            ->where(function ($query): void {
                $query->whereNotNull('consumed_at')->orWhereNotNull('invalidated_at');
            })
            ->whereNotNull('code_ciphertext')
            ->update([
                'code_ciphertext' => null,
                'destination_ciphertext' => null,
            ]);

        $otpDays = (int) config('identity.retention.otp_row_days', 30);
        $cutoff = $now->modify(sprintf('-%d days', $otpDays))->format('Y-m-d H:i:s.uP');
        $this->connection->table('otp_requests')
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($inner) use ($cutoff): void {
                    $inner->whereNotNull('invalidated_at')->where('invalidated_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff): void {
                    $inner->whereNotNull('consumed_at')->where('consumed_at', '<', $cutoff);
                });
            })
            ->whereNull('code_ciphertext')
            ->delete();

        return $invalidated;
    }

    public function pruneExpiredSessions(DateTimeImmutable $now): int
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $revoked = $this->connection->table('auth_sessions')
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '<', $stamp)
            ->update([
                'revoked_at' => $stamp,
                'revoked_reason' => 'expired',
                'updated_at' => $stamp,
            ]);

        $sessionDays = (int) config('identity.retention.revoked_session_days', 90);
        $cutoff = $now->modify(sprintf('-%d days', $sessionDays))->format('Y-m-d H:i:s.uP');
        $this->connection->table('auth_sessions')
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->delete();

        $this->connection->table('mfa_challenges')
            ->where('expires_at', '<', $now->modify('-1 day')->format('Y-m-d H:i:s.uP'))
            ->delete();

        $this->connection->table('user_devices')
            ->whereNotNull('refresh_replay_expires_at')
            ->where('refresh_replay_expires_at', '<', $stamp)
            ->update([
                'refresh_replay_ciphertext' => null,
                'refresh_replay_idempotency_hmac' => null,
                'refresh_replay_expires_at' => null,
            ]);

        return $revoked;
    }

    public function lockRecoveryRequest(Identifier $id): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM recovery_requests WHERE id = ? FOR UPDATE',
            [$id->value],
        );

        return $row instanceof stdClass ? $row : null;
    }

    public function markRecoveryApplied(Identifier $id, DateTimeImmutable $now): void
    {
        $this->connection->table('recovery_requests')->where('id', $id->value)->update([
            'status' => 'applied',
            'applied_at' => $now->format('Y-m-d H:i:s.uP'),
            'new_password_hash' => '',
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function dueCoolingOffRecoveryIds(DateTimeImmutable $now): array
    {
        return $this->connection->table('recovery_requests')
            ->where('status', 'cooling_off')
            ->whereNull('applied_at')
            ->whereNotNull('cooling_off_until')
            ->where('cooling_off_until', '<=', $now->format('Y-m-d H:i:s.uP'))
            ->pluck('id')
            ->all();
    }

    public function disableTotpFactor(Identifier $factorId, Identifier $disabledBy, DateTimeImmutable $now): void
    {
        $userId = $this->connection->table('mfa_factors')->where('id', $factorId->value)->value('user_id');
        $tombstone = random_bytes(32);
        $this->connection->table('mfa_factors')->where('id', $factorId->value)->update([
            'disabled_at' => $now->format('Y-m-d H:i:s.uP'),
            'disabled_by' => $disabledBy->value,
            'secret_ciphertext' => BinaryColumn::bind($tombstone),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
        if (is_string($userId) && $userId !== '') {
            $this->deleteUnconsumedRecoveryCodes(Identifier::fromTrusted($userId));
        }
    }

    public function deleteUnconsumedRecoveryCodes(Identifier $userId): void
    {
        $this->connection->table('mfa_recovery_codes')
            ->where('user_id', $userId->value)
            ->whereNull('consumed_at')
            ->delete();
    }

    private function normalizeOtp(stdClass $row): stdClass
    {
        $row->code_hash = BinaryColumn::asString($row->code_hash);
        $row->code_ciphertext = BinaryColumn::asString($row->code_ciphertext ?? '');
        $row->subject_lookup_hmac = BinaryColumn::asString($row->subject_lookup_hmac);
        $row->destination_ciphertext = BinaryColumn::asString($row->destination_ciphertext);

        return $row;
    }

    private function normalizeDevice(stdClass $row): stdClass
    {
        $row->token_hash = $row->token_hash !== null ? BinaryColumn::asString($row->token_hash) : null;
        $row->refresh_token_hash = $row->refresh_token_hash !== null ? BinaryColumn::asString($row->refresh_token_hash) : null;
        $row->previous_refresh_token_hash = $row->previous_refresh_token_hash !== null
            ? BinaryColumn::asString($row->previous_refresh_token_hash)
            : null;
        $row->refresh_replay_ciphertext = isset($row->refresh_replay_ciphertext)
            ? BinaryColumn::asString($row->refresh_replay_ciphertext)
            : null;
        $row->refresh_replay_idempotency_hmac = isset($row->refresh_replay_idempotency_hmac)
            ? BinaryColumn::asString($row->refresh_replay_idempotency_hmac)
            : null;

        return $row;
    }

    private function normalizeSession(stdClass $row): stdClass
    {
        $row->session_hash = BinaryColumn::asString($row->session_hash);

        return $row;
    }

    private function normalizeFactor(stdClass $row): stdClass
    {
        $row->secret_ciphertext = BinaryColumn::asString($row->secret_ciphertext);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function bindDeviceRow(array $row): array
    {
        foreach (['token_hash', 'refresh_token_hash', 'previous_refresh_token_hash', 'push_token_ciphertext'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = BinaryColumn::bind($row[$column]);
            }
        }

        return $row;
    }
}
