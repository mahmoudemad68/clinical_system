<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\NationalId;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Throwable;

/**
 * Dual-read/new-write local re-key. Production KMS binding remains Phase 23 (ADR 0013).
 *
 * Ciphertext is never printed.
 */
final class RotateIdentityKeysCommand extends Command
{
    protected $signature = 'identity:rotate-keys {--dry-run : Count only; never rewrite ciphertext}';

    protected $description = 'Re-encrypt identity rows onto the current key version. Ciphertext is never printed.';

    public function handle(ConnectionInterface $connection, NationalIdProtector $protector): int
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

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if ((string) config('app.env') === 'production') {
            $this->error('Production rewrite is Phase 23.');

            return self::FAILURE;
        }

        $rewritten = 0;
        $connection->table('users')->where('phone_key_version', '<', $currentEnc)->orderBy('id')->each(function (object $row) use ($connection, $protector, $currentEnc, &$rewritten): void {
            try {
                $plain = $protector->decryptPhone(BinaryColumn::asString($row->phone_e164_encrypted));
                $phone = $protector->phone($plain);
                $connection->table('users')->where('id', $row->id)->update([
                    'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($phone)),
                    'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
                    'phone_key_version' => $currentEnc,
                ]);
                $rewritten++;
            } catch (Throwable) {
                $this->error('A phone row could not be re-keyed.');
            }
        });

        $connection->table('identity_national_ids')->where('key_version', '<', $currentEnc)->orderBy('id')->each(function (object $row) use ($connection, $protector, $currentEnc, &$rewritten): void {
            try {
                $plain = $protector->decryptSecret('national_id', BinaryColumn::asString($row->national_id_encrypted));
                $nationalId = NationalId::fromUntrusted($plain, (bool) config('identity.allow_synthetic_national_ids', false));
                $connection->table('identity_national_ids')->where('id', $row->id)->update([
                    'national_id_encrypted' => BinaryColumn::bind($protector->encryptNationalId($nationalId)),
                    'national_id_lookup_hmac' => BinaryColumn::bind($protector->nationalIdHmac($nationalId)),
                    'key_version' => $currentEnc,
                ]);
                $rewritten++;
            } catch (Throwable) {
                $this->error('A national-id row could not be re-keyed.');
            }
        });

        $this->info(sprintf('Rewrote %d identity rows onto the current key version.', $rewritten));

        return self::SUCCESS;
    }
}
