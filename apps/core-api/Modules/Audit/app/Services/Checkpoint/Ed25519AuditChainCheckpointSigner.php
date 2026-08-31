<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Checkpoint;

use JsonException;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;
use SodiumException;

/**
 * Detached Ed25519 signatures for audit-chain checkpoints.
 *
 * Private key material is read from configuration or a secret file. It is never
 * stored in PostgreSQL, never logged, and never included in exception messages.
 */
final class Ed25519AuditChainCheckpointSigner
{
    public function sign(string $payload): string
    {
        $this->requireSodium();

        $keyId = $this->configuredKeyId();
        $secret = $this->decodeKey(
            $this->readMaterial(
                (string) config('audit.checkpoint.private_key', ''),
                (string) config('audit.checkpoint.private_key_file', ''),
                'private',
            ),
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
        );

        try {
            $signature = sodium_crypto_sign_detached($payload, $secret);
        } catch (SodiumException) {
            throw new AuditChainCheckpointFailed('checkpoint signature could not be created', 'checkpoint_keys_unavailable');
        } finally {
            sodium_memzero($secret);
        }

        $envelope = [
            'key_id' => $keyId,
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
        ksort($envelope);

        try {
            return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new AuditChainCheckpointFailed('checkpoint envelope could not be encoded', 'checkpoint_malformed');
        }
    }

    /**
     * @return array{format: string, sequence: int, row_hash: string, checkpointed_at: string, key_id: string}
     */
    public function verifyEnvelope(string $envelopeJson): array
    {
        $this->requireSodium();

        try {
            $decoded = json_decode($envelopeJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuditChainCheckpointFailed('checkpoint envelope is not JSON', 'checkpoint_malformed');
        }

        if (! is_array($decoded)) {
            throw new AuditChainCheckpointFailed('checkpoint envelope is not an object', 'checkpoint_malformed');
        }

        $payload = $decoded['payload'] ?? null;
        $signatureB64 = $decoded['signature'] ?? null;
        $keyId = $decoded['key_id'] ?? null;

        if (! is_string($payload) || $payload === '') {
            throw new AuditChainCheckpointFailed('checkpoint envelope payload is missing', 'checkpoint_malformed');
        }

        if (! is_string($signatureB64) || $signatureB64 === '') {
            throw new AuditChainCheckpointFailed('checkpoint envelope signature is missing', 'checkpoint_malformed');
        }

        if (! is_string($keyId) || $keyId === '') {
            throw new AuditChainCheckpointFailed('checkpoint envelope key id is missing', 'checkpoint_malformed');
        }

        if ($keyId !== $this->configuredKeyId()) {
            throw new AuditChainCheckpointFailed('checkpoint key id is not configured', 'checkpoint_wrong_key');
        }

        $signature = base64_decode($signatureB64, true);
        if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new AuditChainCheckpointFailed('checkpoint signature encoding is invalid', 'checkpoint_malformed');
        }

        $public = $this->decodeKey(
            $this->readMaterial(
                (string) config('audit.checkpoint.public_key', ''),
                (string) config('audit.checkpoint.public_key_file', ''),
                'public',
            ),
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
        );

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $payload, $public);
        } catch (SodiumException) {
            throw new AuditChainCheckpointFailed('checkpoint signature could not be verified', 'checkpoint_signature_invalid');
        }

        if (! $valid) {
            throw new AuditChainCheckpointFailed('checkpoint signature is invalid', 'checkpoint_signature_invalid');
        }

        $fields = CanonicalAuditCheckpoint::decode($payload);
        if ($fields['key_id'] !== $keyId) {
            throw new AuditChainCheckpointFailed('checkpoint key id does not match payload', 'checkpoint_malformed');
        }

        return $fields;
    }

    public function configuredKeyId(): string
    {
        $keyId = (string) config('audit.checkpoint.key_id', 'v1');
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1) {
            throw new AuditChainCheckpointFailed('checkpoint key id is invalid', 'checkpoint_keys_unavailable');
        }

        return $keyId;
    }

    private function requireSodium(): void
    {
        if (! extension_loaded('sodium')) {
            throw new AuditChainCheckpointFailed('Ed25519 checkpointing requires the sodium extension', 'checkpoint_keys_unavailable');
        }
    }

    private function readMaterial(string $inline, string $file, string $kind): string
    {
        if ($file !== '') {
            if (! is_readable($file)) {
                throw new AuditChainCheckpointFailed('checkpoint '.$kind.' key file is unreadable', 'checkpoint_keys_unavailable');
            }

            $contents = file_get_contents($file);
            if (! is_string($contents) || trim($contents) === '') {
                throw new AuditChainCheckpointFailed('checkpoint '.$kind.' key file is empty', 'checkpoint_keys_unavailable');
            }

            return trim($contents);
        }

        $inline = trim($inline);
        if ($inline === '') {
            throw new AuditChainCheckpointFailed('checkpoint '.$kind.' key is not configured', 'checkpoint_keys_unavailable');
        }

        return $inline;
    }

    private function decodeKey(string $material, int $expectedLength): string
    {
        $binary = null;
        if (ctype_xdigit($material) && strlen($material) === ($expectedLength * 2)) {
            $decoded = hex2bin($material);
            $binary = $decoded === false ? null : $decoded;
        }

        if ($binary === null) {
            $decoded = base64_decode($material, true);
            $binary = $decoded === false ? null : $decoded;
        }

        if (! is_string($binary) || strlen($binary) !== $expectedLength) {
            throw new AuditChainCheckpointFailed('checkpoint key material is invalid', 'checkpoint_keys_unavailable');
        }

        return $binary;
    }
}
