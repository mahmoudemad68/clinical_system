<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Checkpoint;

use JsonException;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;

/**
 * Deterministic checkpoint bytes that Ed25519 signs. No secrets, no row metadata.
 */
final class CanonicalAuditCheckpoint
{
    public const FORMAT = 'clinic.audit.checkpoint.v1';

    public static function encode(
        int $sequence,
        string $rowHashHex,
        string $checkpointedAt,
        string $keyId,
    ): string {
        $fields = [
            'checkpointed_at' => $checkpointedAt,
            'format' => self::FORMAT,
            'key_id' => $keyId,
            'row_hash' => strtolower($rowHashHex),
            'sequence' => $sequence,
        ];
        ksort($fields);

        try {
            return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new AuditChainCheckpointFailed('checkpoint payload could not be encoded', 'checkpoint_malformed');
        }
    }

    /**
     * @return array{format: string, sequence: int, row_hash: string, checkpointed_at: string, key_id: string}
     */
    public static function decode(string $canonical): array
    {
        try {
            $decoded = json_decode($canonical, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuditChainCheckpointFailed('checkpoint payload is not JSON', 'checkpoint_malformed');
        }

        if (! is_array($decoded)) {
            throw new AuditChainCheckpointFailed('checkpoint payload is not an object', 'checkpoint_malformed');
        }

        $format = $decoded['format'] ?? null;
        $sequence = $decoded['sequence'] ?? null;
        $rowHash = $decoded['row_hash'] ?? null;
        $checkpointedAt = $decoded['checkpointed_at'] ?? null;
        $keyId = $decoded['key_id'] ?? null;

        if ($format !== self::FORMAT) {
            throw new AuditChainCheckpointFailed('checkpoint format is unsupported', 'checkpoint_malformed');
        }

        if (! is_int($sequence) || $sequence < 1) {
            throw new AuditChainCheckpointFailed('checkpoint sequence is invalid', 'checkpoint_malformed');
        }

        if (! is_string($rowHash) || preg_match('/^[0-9a-f]{64}$/', $rowHash) !== 1) {
            throw new AuditChainCheckpointFailed('checkpoint row hash is invalid', 'checkpoint_malformed');
        }

        if (! is_string($checkpointedAt) || $checkpointedAt === '') {
            throw new AuditChainCheckpointFailed('checkpoint timestamp is invalid', 'checkpoint_malformed');
        }

        if (! is_string($keyId) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1) {
            throw new AuditChainCheckpointFailed('checkpoint key id is invalid', 'checkpoint_malformed');
        }

        return [
            'format' => $format,
            'sequence' => $sequence,
            'row_hash' => $rowHash,
            'checkpointed_at' => $checkpointedAt,
            'key_id' => $keyId,
        ];
    }
}
