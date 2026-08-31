<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;
use stdClass;

interface AuthDirectory
{
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
    ): void;

    /**
     * @param  list<string>  $subjectHmacs
     */
    public function invalidateOpenOtps(array $subjectHmacs, string $purpose, DateTimeImmutable $now): void;

    public function rebindOtpSubjectHmac(string $from, string $to): int;

    public function countTotpNeedingRekey(int $encryptionVersion): int;

    /**
     * Enabled TOTP factors still on an older encryption version.
     *
     * @return list<stdClass>
     */
    public function totpFactorsNeedingRekey(int $encryptionVersion, int $limit): array;

    public function rewriteTotpSecret(Identifier $factorId, string $cipher, int $keyVersion, DateTimeImmutable $now): void;

    public function countPushTokensNeedingRekey(int $encryptionVersion): int;

    /**
     * @return list<stdClass>
     */
    public function devicesWithPushTokenNeedingRekey(int $encryptionVersion, int $limit): array;

    public function rewritePushToken(Identifier $deviceId, string $cipher): void;

    public function countLiveOtpEncryptionBelow(int $version): int;

    public function countRefreshReplayBelow(int $version): int;

    public function lockOtp(Identifier $id): ?stdClass;

    public function incrementOtpAttempts(Identifier $id, int $attempts): void;

    public function consumeOtp(Identifier $id, DateTimeImmutable $now): void;

    public function markOtpDelivery(Identifier $id, string $status, ?string $reference): void;

    public function otpById(Identifier $id): ?stdClass;

    public function recoveryRequestById(Identifier $id): ?stdClass;

    /**
     * Encrypted push tokens for devices that existed on the account.
     *
     * @return list<string>
     */
    public function pushTokenCiphers(Identifier $userId): array;

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertDevice(array $row): void;

    public function lockDeviceByRefreshHash(string $hash): ?stdClass;

    public function lockDevice(Identifier $id): ?stdClass;

    public function lockSession(Identifier $id): ?stdClass;

    public function findDeviceByAccessHash(string $hash): ?stdClass;

    public function recordConsumedRefresh(string $familyId, string $tokenHash, int $generation, DateTimeImmutable $now): void;

    public function consumedRefreshExists(string $hash): bool;

    public function storeRefreshReplay(
        Identifier $deviceId,
        string $idempotencyHmac,
        string $cipher,
        DateTimeImmutable $expiresAt,
    ): void;

    public function clearRefreshReplay(Identifier $deviceId): void;

    public function rotateDeviceTokens(
        Identifier $deviceId,
        string $accessHash,
        string $refreshHash,
        string $previousRefreshHash,
        int $generation,
        DateTimeImmutable $accessExpires,
        DateTimeImmutable $refreshExpires,
        DateTimeImmutable $now,
    ): void;

    public function revokeDeviceFamily(string $familyId, string $reason, DateTimeImmutable $now): void;

    public function revokeDevice(Identifier $deviceId, string $reason, DateTimeImmutable $now): void;

    public function revokeAllDevices(Identifier $userId, string $reason, DateTimeImmutable $now): int;

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertSession(array $row): void;

    public function findSession(Identifier $id): ?stdClass;

    public function findSessionByHash(string $hash): ?stdClass;

    public function findActiveSessionByDevice(Identifier $deviceId): ?stdClass;

    public function bindCookieSessionHash(Identifier $sessionId, string $hash, DateTimeImmutable $now): void;

    public function updateSessionAccessHash(Identifier $deviceId, string $accessHash, DateTimeImmutable $now): void;

    public function latestCookieSession(Identifier $userId): ?stdClass;

    /**
     * @return list<stdClass>
     */
    public function listSessions(Identifier $userId): array;

    public function countActiveSessions(DateTimeImmutable $now): int;

    public function revokeSession(Identifier $id, string $reason, DateTimeImmutable $now): void;

    /**
     * @return list<string> normalized auth_sessions.id values that were revoked
     */
    public function revokeSessionsForDevice(Identifier $deviceId, string $reason, DateTimeImmutable $now): array;

    /**
     * @return list<string> normalized auth_sessions.id values that were revoked
     */
    public function revokeAllSessions(Identifier $userId, string $reason, DateTimeImmutable $now): array;

    /**
     * @return list<string> normalized auth_sessions.id values that were revoked
     */
    public function revokeOtherSessions(Identifier $userId, Identifier $keepSessionId, string $reason, DateTimeImmutable $now): array;

    /**
     * @return list<string> normalized auth_sessions.id values that were revoked
     */
    public function revokeSessionsForRefreshFamily(string $familyId, string $reason, DateTimeImmutable $now): array;

    public function revokeOtherDevices(Identifier $userId, Identifier $keepDeviceId, string $reason, DateTimeImmutable $now): void;

    public function updateSessionAssurance(Identifier $sessionId, string $assuranceLevel, DateTimeImmutable $now): void;

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertMfaChallenge(array $row): void;

    public function lockMfaChallenge(Identifier $id): ?stdClass;

    public function consumeMfaChallenge(Identifier $id, DateTimeImmutable $now): void;

    public function bumpMfaChallengeAttempts(Identifier $id, int $attempts): void;

    public function activeTotp(Identifier $userId): ?stdClass;

    public function updateTotpCounter(Identifier $factorId, int $counter, DateTimeImmutable $now): void;

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertTotpFactor(array $row): void;

    public function pendingTotp(Identifier $userId): ?stdClass;

    /**
     * Enabled TOTP rows for the user, locked in stable id order.
     *
     * @return list<stdClass>
     */
    public function lockEnabledTotpFactors(Identifier $userId): array;

    public function discardUnverifiedTotp(Identifier $factorId, Identifier $disabledBy, DateTimeImmutable $now): void;

    public function markTotpVerified(Identifier $factorId, DateTimeImmutable $now): void;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertRecoveryCodes(array $rows): void;

    public function lockRecoveryCode(Identifier $userId, string $codeHash): ?stdClass;

    public function consumeRecoveryCode(Identifier $id, DateTimeImmutable $now): bool;

    public function insertRecoveryRequest(
        Identifier $id,
        Identifier $userId,
        Identifier $otpId,
        string $status,
        string $passwordHash,
        ?DateTimeImmutable $coolingOffUntil,
        ?DateTimeImmutable $appliedAt,
        DateTimeImmutable $now,
    ): void;

    public function lockRecoveryRequest(Identifier $id): ?stdClass;

    public function markRecoveryApplied(Identifier $id, DateTimeImmutable $now): void;

    /**
     * @return list<string>
     */
    public function dueCoolingOffRecoveryIds(DateTimeImmutable $now): array;

    public function disableTotpFactor(Identifier $factorId, Identifier $disabledBy, DateTimeImmutable $now): void;

    public function deleteUnconsumedRecoveryCodes(Identifier $userId): void;

    public function pruneExpiredOtps(DateTimeImmutable $now): int;

    public function pruneExpiredSessions(DateTimeImmutable $now): int;

    /**
     * @return array{
     *     session_ids: list<string>,
     *     refresh_family_ids: list<string>,
     *     mfa_challenge_ids: list<string>,
     *     otp_ids: list<string>
     * }
     */
    public function subjectAuthIdentifiers(Identifier $userId, string $subjectHmac): array;

    /**
     * @return array<string, int>
     */
    public function countSubjectAuthHoldings(Identifier $userId, string $subjectHmac): array;

    /**
     * @return array<string, int>
     */
    public function eraseSubjectAuthState(Identifier $userId, string $subjectHmac, DateTimeImmutable $now): array;

    public function pruneObsoleteRecoveryRequests(DateTimeImmutable $now): int;

    public function pruneObsoleteDevices(DateTimeImmutable $now): int;

    public function pruneObsoleteRefreshConsumptions(DateTimeImmutable $now): int;
}
