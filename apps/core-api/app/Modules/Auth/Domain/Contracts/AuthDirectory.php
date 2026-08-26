<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;
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

    public function invalidateOpenOtps(string $subjectHmac, string $purpose, DateTimeImmutable $now): void;

    public function lockOtp(Identifier $id): ?stdClass;

    public function incrementOtpAttempts(Identifier $id, int $attempts): void;

    public function consumeOtp(Identifier $id, DateTimeImmutable $now): void;

    public function markOtpDelivery(Identifier $id, string $status, ?string $reference): void;

    public function otpById(Identifier $id): ?stdClass;

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertDevice(array $row): void;

    public function lockDeviceByRefreshHash(string $hash): ?stdClass;

    public function findDeviceByAccessHash(string $hash): ?stdClass;

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

    public function latestCookieSession(Identifier $userId): ?stdClass;

    /**
     * @return list<stdClass>
     */
    public function listSessions(Identifier $userId): array;

    public function countActiveSessions(DateTimeImmutable $now): int;

    public function revokeSession(Identifier $id, string $reason, DateTimeImmutable $now): void;

    public function revokeAllSessions(Identifier $userId, string $reason, DateTimeImmutable $now): void;

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

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertRecoveryCodes(array $rows): void;

    public function pruneExpiredOtps(DateTimeImmutable $now): int;

    public function pruneExpiredSessions(DateTimeImmutable $now): int;
}
