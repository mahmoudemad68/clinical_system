<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use App\Modules\Auth\Domain\Contracts\TotpVerifier;
use App\Modules\Identity\Domain\Contracts\UserDirectory;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\AccountType;
use App\Modules\Identity\Domain\ValueObjects\LanguagePreference;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use Illuminate\Console\Command;

/**
 * One-time audited bootstrap. Disabled after the first admin exists unless
 * IDENTITY_BOOTSTRAP_ENABLED remains true in a non-production environment.
 */
final class BootstrapAdminCommand extends Command
{
    protected $signature = 'identity:bootstrap-admin {phone} {password} {--name=Bootstrap Admin}';

    protected $description = 'Create the first admin identity. Requires immediate TOTP enrollment.';

    public function handle(
        UserDirectory $users,
        AuthDirectory $auth,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        TotpVerifier $totp,
        IdentityGenerator $ids,
        Clock $clock,
    ): int {
        if (! (bool) config('identity.bootstrap.enabled', false) || (string) config('app.env') === 'production') {
            $this->error('Bootstrap is disabled.');

            return self::FAILURE;
        }

        $phone = $protector->phone((string) $this->argument('phone'));
        if ($users->findByPhoneHmac($protector->phoneHmac($phone)) !== null) {
            $this->error('An identity already exists for that phone handle.');

            return self::FAILURE;
        }

        $now = $clock->now();
        $userId = $ids->next();
        $users->insertUser(
            new UserAccount(
                $userId,
                (string) $this->option('name'),
                AccountType::Admin,
                AccountStatus::Active,
                LanguagePreference::English,
                $hasher->hash((string) $this->argument('password')),
                1,
                true,
                true,
            ),
            $protector->encryptPhone($phone),
            $protector->phoneHmac($phone),
            1,
            $now,
        );

        $secret = $totp->generateSecret();
        $auth->insertTotpFactor([
            'id' => $ids->next()->value,
            'user_id' => $userId->value,
            'factor_type' => 'totp',
            'secret_ciphertext' => $protector->encryptSecret('mfa_secret', $secret),
            'key_version' => 1,
            'last_used_counter' => null,
            'last_used_at' => null,
            'verified_at' => $now->format('Y-m-d H:i:s.uP'),
            'disabled_at' => null,
            'disabled_by' => null,
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);

        $this->line($totp->provisioningUri($secret, 'admin'));
        $this->info('Admin created. Enroll the TOTP URI immediately and disable bootstrap.');

        return self::SUCCESS;
    }
}
