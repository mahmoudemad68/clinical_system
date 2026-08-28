<?php

declare(strict_types=1);

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Auth\Rules\PasswordPolicy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\PhoneE164;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

/**
 * One-time audited bootstrap. Disabled after the first admin exists unless
 * IDENTITY_BOOTSTRAP_ENABLED remains true in a non-production environment.
 *
 * Step 1 creates an unverified TOTP factor. Step 2 (`--confirm`) prompts for a
 * hidden TOTP and proves possession before the factor becomes usable.
 */
final class BootstrapAdminCommand extends Command
{
    protected $signature = 'identity:bootstrap-admin {phone} {--name=Bootstrap Admin} {--confirm}';

    protected $description = 'Create the first admin identity. Requires a confirmed TOTP enrollment.';

    public function handle(
        UserDirectory $users,
        AuthDirectory $auth,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        PasswordPolicy $policy,
        TotpVerifier $totp,
        IdentityGenerator $ids,
        Clock $clock,
        TransactionRunner $transactions,
        AppendAuditEvent $audit,
    ): int {
        if (! (bool) config('identity.bootstrap.enabled', false) || (string) config('app.env') === 'production') {
            $this->error('Bootstrap is disabled.');

            return self::FAILURE;
        }

        $phone = $protector->phone((string) $this->argument('phone'));

        if ((bool) $this->option('confirm')) {
            return $this->confirmEnrollment($users, $auth, $protector, $totp, $clock, $transactions, $audit, $phone);
        }

        if ($users->countByAccountType(AccountType::Admin) > 0) {
            $this->error('An admin identity already exists.');

            return self::FAILURE;
        }

        if ($users->findByPhoneHmac($protector->phoneHmac($phone)) !== null) {
            $this->error('An identity already exists for that phone handle.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        if ($password === '') {
            $this->error('A password is required.');

            return self::FAILURE;
        }

        try {
            $policy->assert($password, $phone);
        } catch (InvalidValueObject) {
            $this->error('Password does not meet the required policy.');

            return self::FAILURE;
        }

        $now = $clock->now();
        $userId = $ids->next();
        $secret = $totp->generateSecret();

        $transactions->run(function (TransactionContext $tx) use ($users, $auth, $protector, $hasher, $ids, $phone, $password, $userId, $secret, $now, $audit): void {
            $users->insertUser(
                new UserAccount(
                    $userId,
                    (string) $this->option('name'),
                    AccountType::Admin,
                    AccountStatus::Active,
                    LanguagePreference::English,
                    $hasher->hash($password),
                    1,
                    true,
                    true,
                    true,
                ),
                $protector->encryptPhone($phone),
                $protector->phoneHmac($phone),
                1,
                $now,
            );

            $auth->insertTotpFactor([
                'id' => $ids->next()->value,
                'user_id' => $userId->value,
                'factor_type' => 'totp',
                'secret_ciphertext' => $protector->encryptSecret('mfa_secret', $secret),
                'key_version' => 1,
                'last_used_counter' => null,
                'last_used_at' => null,
                'verified_at' => null,
                'disabled_at' => null,
                'disabled_by' => null,
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);

            $audit->append($tx, 'identity.bootstrap_started', 'user', $userId, ['reason_code' => 'bootstrap'], $userId, 'user');
        });

        $path = storage_path('app/private/bootstrap-totp.uri');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $totp->provisioningUri($secret, 'admin'));
        chmod($path, 0600);
        $this->info('Admin created with an unverified TOTP factor. The provisioning URI was written to a private file; confirm with --confirm, then delete that file and disable bootstrap.');

        return self::SUCCESS;
    }

    private function confirmEnrollment(
        UserDirectory $users,
        AuthDirectory $auth,
        NationalIdProtector $protector,
        TotpVerifier $totp,
        Clock $clock,
        TransactionRunner $transactions,
        AppendAuditEvent $audit,
        PhoneE164 $phone,
    ): int {
        $code = (string) $this->secret('Confirmation TOTP');
        if ($code === '') {
            $this->error('A confirmation TOTP is required.');

            return self::FAILURE;
        }

        $user = $users->findByPhoneHmac($protector->phoneHmac($phone));
        if ($user === null || $user->accountType !== AccountType::Admin) {
            $this->error('No bootstrap admin exists for that phone handle.');

            return self::FAILURE;
        }

        $factor = $auth->pendingTotp($user->id);
        if ($factor === null) {
            $this->error('No pending TOTP enrollment exists.');

            return self::FAILURE;
        }

        $secret = $protector->decryptSecret('mfa_secret', (string) $factor->secret_ciphertext);
        $now = $clock->now();
        $result = $totp->verify($secret, $code, $now, null);
        if (! $result->valid) {
            $this->error('TOTP confirmation failed.');

            return self::FAILURE;
        }

        $transactions->run(function (TransactionContext $tx) use ($auth, $audit, $factor, $user, $now): void {
            $auth->markTotpVerified(Identifier::fromTrusted((string) $factor->id), $now);
            $audit->append($tx, 'identity.bootstrap_confirmed', 'user', $user->id, ['reason_code' => 'bootstrap_totp'], $user->id, 'user');
        });

        $this->info('TOTP enrollment confirmed. Disable IDENTITY_BOOTSTRAP_ENABLED.');

        return self::SUCCESS;
    }
}
