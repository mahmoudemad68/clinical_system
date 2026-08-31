<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Modules\Identity\Services\RotateIdentityKeysService;
use Throwable;

/**
 * Dual-read / new-write identity re-key. Default is inspect/dry-run.
 *
 * Production mutation requires --apply --confirm. Ciphertext and keys are
 * never printed. Actual KMS binding remains Phase 23.
 */
final class RotateIdentityKeysCommand extends Command
{
    protected $signature = 'identity:rotate-keys
        {--dry-run : Inspect counts only; never rewrite (default)}
        {--apply : Rewrite a batch onto the current key versions}
        {--confirm : Required with --apply when APP_ENV=production}
        {--status : Report whether an old version may be retired}
        {--batch=100 : Maximum rows rewritten per holding per invocation}';

    protected $description = 'Inspect or rewrite identity envelopes onto the current key versions. Never prints secrets.';

    public function handle(RotateIdentityKeysService $rotation): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && (string) config('app.env') === 'production' && ! (bool) $this->option('confirm')) {
            $this->error('Production rewrite requires --apply --confirm.');

            return self::FAILURE;
        }

        try {
            $report = $apply
                ? $rotation->apply(max(1, (int) $this->option('batch')))
                : $rotation->inspect();
        } catch (Throwable) {
            $this->error('Identity key rotation failed closed. Ciphertext was not printed.');

            return self::FAILURE;
        }

        $safe = $report->toSafeArray();
        $this->info('pending_phone='.$safe['pending_phone']);
        $this->info('pending_national_id='.$safe['pending_national_id']);
        $this->info('pending_totp='.$safe['pending_totp']);
        $this->info('pending_push_token='.$safe['pending_push_token']);
        $this->info('live_otp_old_encryption='.$safe['live_otp_old_encryption']);
        $this->info('live_refresh_replay='.$safe['live_refresh_replay']);
        $this->info('rewritten_phone='.$safe['rewritten_phone']);
        $this->info('rewritten_national_id='.$safe['rewritten_national_id']);
        $this->info('rewritten_totp='.$safe['rewritten_totp']);
        $this->info('rewritten_push_token='.$safe['rewritten_push_token']);
        $this->info('hmac_current='.$safe['hmac_current']);
        $this->info('enc_current='.$safe['encryption_current']);
        $this->info('retirement_safe='.($safe['retirement_safe'] ? 'yes' : 'no'));
        $this->info('otp_ciphertext_policy='.$safe['otp_ciphertext_policy']);
        $this->info('refresh_replay_policy='.$safe['refresh_replay_policy']);

        if ((bool) $this->option('status') || ! $apply) {
            $this->info($report->retirementSafe
                ? 'Old encryption/HMAC version retirement is eligible. Environment keys are not removed automatically.'
                : 'Old encryption/HMAC version retirement is not safe. Live ciphertext or lookup rows still depend on the previous version.');
        }

        return self::SUCCESS;
    }
}
