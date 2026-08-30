<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Checkpoint;

use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Contracts\AuditChainCheckpointStore;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Throwable;

/**
 * Verifies signed external checkpoints against persisted audit_events rows.
 *
 * A checkpoint at sequence N proves that row N still has the signed row_hash.
 * Later legitimate events N+1… are allowed. This does not compare against the
 * current tip.
 */
final class AuditChainCheckpointVerifier
{
    public const REASON_MISSING = 'checkpoint_missing';

    public const REASON_MALFORMED = 'checkpoint_malformed';

    public const REASON_SIGNATURE_INVALID = 'checkpoint_signature_invalid';

    public const REASON_WRONG_KEY = 'checkpoint_wrong_key';

    public const REASON_ROW_MISSING = 'checkpoint_row_missing';

    public const REASON_HASH_MISMATCH = 'checkpoint_hash_mismatch';

    public const REASON_KEYS_UNAVAILABLE = 'checkpoint_keys_unavailable';

    public const REASON_STORE_UNAVAILABLE = 'checkpoint_store_unavailable';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AuditChainCheckpointStore $store,
        private readonly Ed25519AuditChainCheckpointSigner $signer,
    ) {}

    /**
     * @return array{ok: bool, skipped: bool, reason: string|null}
     */
    public function verify(): array
    {
        if (! (bool) config('audit.checkpoint.enabled', false)) {
            return ['ok' => true, 'skipped' => true, 'reason' => null];
        }

        try {
            $items = $this->store->all();
        } catch (AuditChainCheckpointFailed $e) {
            return ['ok' => false, 'skipped' => false, 'reason' => $e->reason];
        } catch (Throwable) {
            return ['ok' => false, 'skipped' => false, 'reason' => self::REASON_STORE_UNAVAILABLE];
        }

        $rowCount = (int) $this->connection->table('audit_events')->count();

        if ($items === []) {
            if ($rowCount === 0) {
                return ['ok' => true, 'skipped' => false, 'reason' => null];
            }

            return ['ok' => false, 'skipped' => false, 'reason' => self::REASON_MISSING];
        }

        foreach ($items as $item) {
            try {
                $fields = $this->signer->verifyEnvelope($item['contents']);
            } catch (AuditChainCheckpointFailed $e) {
                return ['ok' => false, 'skipped' => false, 'reason' => $e->reason];
            } catch (Throwable) {
                return ['ok' => false, 'skipped' => false, 'reason' => self::REASON_MALFORMED];
            }

            $row = $this->connection->table('audit_events')
                ->where('chain_sequence', $fields['sequence'])
                ->first(['chain_sequence', 'row_hash']);

            if ($row === null) {
                return ['ok' => false, 'skipped' => false, 'reason' => self::REASON_ROW_MISSING];
            }

            $actualHex = bin2hex(BinaryColumn::asString($row->row_hash));
            if (! hash_equals($fields['row_hash'], $actualHex)) {
                return ['ok' => false, 'skipped' => false, 'reason' => self::REASON_HASH_MISMATCH];
            }
        }

        return ['ok' => true, 'skipped' => false, 'reason' => null];
    }
}
