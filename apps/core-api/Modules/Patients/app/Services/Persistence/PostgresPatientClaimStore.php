<?php

declare(strict_types=1);

namespace Modules\Patients\Services\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use stdClass;

final class PostgresPatientClaimStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function insertCredential(
        Identifier $id,
        Identifier $patientId,
        string $credentialHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): void {
        try {
            $this->connection->table('patient_claim_credentials')->insert([
                'id' => $id->value,
                'patient_id' => $patientId->value,
                'credential_lookup_hmac' => BinaryColumn::bind($credentialHash),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
                'consumed_at' => null,
                'issued_at' => $now->format('Y-m-d H:i:s.uP'),
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }
    }

    public function consumeUnexpired(Identifier $patientId, string $credentialHash, DateTimeImmutable $now): int
    {
        return $this->connection->table('patient_claim_credentials')
            ->where('patient_id', $patientId->value)
            ->where('credential_lookup_hmac', BinaryColumn::bind($credentialHash))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now->format('Y-m-d H:i:s.uP'))
            ->update([
                'consumed_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
    }

    public function insertFailure(Identifier $id, Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): void
    {
        $this->connection->table('patient_claim_failures')->insert([
            'id' => $id->value,
            'user_id' => $userId->value,
            'national_id_lookup_hmac' => BinaryColumn::bind($nationalIdHmac),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function countFailuresSince(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $since): int
    {
        return $this->connection->table('patient_claim_failures')
            ->where('user_id', $userId->value)
            ->where('national_id_lookup_hmac', BinaryColumn::bind($nationalIdHmac))
            ->where('created_at', '>=', $since->format('Y-m-d H:i:s.uP'))
            ->count();
    }

    public function lockRow(Identifier $userId, string $nationalIdHmac): ?stdClass
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM patient_claim_locks WHERE user_id = ? AND national_id_lookup_hmac = ? FOR UPDATE',
            [$userId->value, BinaryColumn::bind($nationalIdHmac)],
        );

        return $row instanceof stdClass ? $row : null;
    }

    public function upsertLock(
        Identifier $id,
        Identifier $userId,
        string $nationalIdHmac,
        ?DateTimeImmutable $cooldownUntil,
        ?DateTimeImmutable $lockedAt,
        DateTimeImmutable $now,
    ): void {
        $existing = $this->lockRow($userId, $nationalIdHmac);
        $payload = [
            'cooldown_until' => $cooldownUntil?->format('Y-m-d H:i:s.uP'),
            'locked_at' => $lockedAt?->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ];

        if ($existing instanceof stdClass) {
            if (isset($existing->locked_at) && $existing->locked_at !== null && $lockedAt === null) {
                unset($payload['locked_at']);
            }
            $this->connection->table('patient_claim_locks')
                ->where('id', (string) $existing->id)
                ->update($payload);

            return;
        }

        $this->connection->table('patient_claim_locks')->insert([
            'id' => $id->value,
            'user_id' => $userId->value,
            'national_id_lookup_hmac' => BinaryColumn::bind($nationalIdHmac),
            'cooldown_until' => $cooldownUntil?->format('Y-m-d H:i:s.uP'),
            'locked_at' => $lockedAt?->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
