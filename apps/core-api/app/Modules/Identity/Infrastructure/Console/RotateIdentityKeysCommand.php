<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Dual-read/new-write key rotation is Phase 23 for production KMS (ADR 0013).
 *
 * This command reports how many identity rows still sit on a prior key version.
 * It never decrypts values into logs or stdout.
 */
final class RotateIdentityKeysCommand extends Command
{
    protected $signature = 'identity:rotate-keys {--dry-run : Count only; never rewrite ciphertext}';

    protected $description = 'Report identity rows pending envelope/HMAC re-key. Ciphertext is never printed.';

    public function handle(ConnectionInterface $connection): int
    {
        $currentHmac = (int) config('identity.hmac.current_version', 1);
        $currentEnc = (int) config('identity.encryption.current_version', 1);

        $phones = $connection->table('users')->where('phone_key_version', '<', $currentEnc)->count();
        $nids = $connection->table('identity_national_ids')->where('key_version', '<', $currentEnc)->count();
        $otps = $connection->table('otp_requests')->where('key_version', '<', $currentEnc)->count();
        $factors = $connection->table('mfa_factors')->where('key_version', '<', $currentEnc)->count();

        $this->info(sprintf(
            'Pending re-key counts (hmac_current=%d enc_current=%d): phones=%d national_ids=%d otps=%d totp_factors=%d.',
            $currentHmac,
            $currentEnc,
            $phones,
            $nids,
            $otps,
            $factors,
        ));

        if (! $this->option('dry-run')) {
            $this->warn('Rewrite is not enabled in Phase 01. Re-run with --dry-run; production backfill is Phase 23.');
        }

        return self::SUCCESS;
    }
}
