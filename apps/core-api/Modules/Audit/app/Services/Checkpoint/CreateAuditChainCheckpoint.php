<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Checkpoint;

use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Contracts\AuditChainCheckpointStore;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Services\Persistence\BinaryColumn;

/**
 * Signs the current audit-chain tip and persists the envelope outside PostgreSQL.
 *
 * Refuses to sign a chain that already fails in-database verification.
 * Signing and storage happen after the advisory lock is released so appenders
 * are not blocked on I/O. A concurrent append after the tip snapshot is a
 * legitimate later event, not a fork.
 */
final class CreateAuditChainCheckpoint
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly VerifyAuditChain $verifier,
        private readonly Ed25519AuditChainCheckpointSigner $signer,
        private readonly AuditChainCheckpointStore $store,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{created: bool, sequence: int|null, skipped: string|null}
     */
    public function create(): array
    {
        if (! (bool) config('audit.checkpoint.enabled', false)) {
            return ['created' => false, 'sequence' => null, 'skipped' => 'disabled'];
        }

        $database = $this->verifier->verifyDatabaseChain();
        if (! $database['ok']) {
            throw new AuditChainCheckpointFailed(
                'refusing to checkpoint an invalid audit chain',
                'checkpoint_invalid_chain',
            );
        }

        $tip = $this->lockedTip();
        if ($tip === null) {
            return ['created' => false, 'sequence' => null, 'skipped' => 'empty_chain'];
        }

        /** @var object{chain_sequence: int|numeric-string, row_hash: mixed} $tip */
        $sequence = (int) $tip->chain_sequence;
        $rowHashHex = bin2hex(BinaryColumn::asString($tip->row_hash));
        $keyId = $this->signer->configuredKeyId();
        $checkpointedAt = $this->clock->now()->format('Y-m-d\TH:i:s.u\Z');
        $payload = CanonicalAuditCheckpoint::encode($sequence, $rowHashHex, $checkpointedAt, $keyId);
        $envelope = $this->signer->sign($payload);
        $name = sprintf(
            '%020d.%s.%s.json',
            $sequence,
            $keyId,
            $this->clock->now()->format('YmdHisu'),
        );

        if ($this->store->exists($name)) {
            $name = sprintf(
                '%020d.%s.%s.%s.json',
                $sequence,
                $keyId,
                $this->clock->now()->format('YmdHisu'),
                bin2hex(random_bytes(4)),
            );
        }

        $this->store->put($name, $envelope);

        return ['created' => true, 'sequence' => $sequence, 'skipped' => null];
    }

    private function lockedTip(): ?object
    {
        return $this->connection->transaction(function (): ?object {
            $this->connection->statement(
                "SELECT pg_advisory_xact_lock(pg_catalog.hashtext('audit_events_chain'))",
            );

            $tip = $this->connection->table('audit_events')
                ->orderByDesc('chain_sequence')
                ->lockForUpdate()
                ->first(['chain_sequence', 'row_hash']);

            return is_object($tip) ? $tip : null;
        });
    }
}
