<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Services\CreatePatientProfile;
use Modules\Patients\Support\OnboardingOutcome;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;
use RuntimeException;

/**
 * Synthetic Patient Flutter Core fixture. Disabled outside local/testing.
 *
 * Writes credentials to a caller-supplied /tmp path. Does not print National
 * IDs or passwords.
 */
final class SeedPatientFlutterFixtureCommand extends Command
{
    protected $signature = 'e2e:seed-patient-flutter {--write= : Absolute path for the fixture JSON}';

    protected $description = 'Seed synthetic Patient Flutter onboarding fixtures (local/testing only).';

    public function handle(
        IdentityGenerator $ids,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        Clock $clock,
        UserDirectory $identities,
        CreatePatientProfile $createProfile,
    ): int {
        $env = (string) config('app.env');
        if (! in_array($env, ['local', 'testing'], true)) {
            $this->error('This command is disabled outside local/testing.');

            return self::FAILURE;
        }

        $write = (string) $this->option('write');
        if ($write === '' || ! str_starts_with($write, '/tmp/')) {
            $this->error('Pass --write=/tmp/... so credentials never enter the repository.');

            return self::FAILURE;
        }

        $password = 'correct-horse-battery';

        $fresh = $this->insertPatient($ids, $protector, $hasher, $clock, $identities, $password, 'fresh');
        $switch = $this->insertPatient($ids, $protector, $hasher, $clock, $identities, $password, 'switch');
        $owner = $this->insertPatient($ids, $protector, $hasher, $clock, $identities, $password, 'owner');
        $challenger = $this->insertPatient($ids, $protector, $hasher, $clock, $identities, $password, 'challenger');

        $switchName = 'Patient B';
        $this->onboard($createProfile, $ids, $switch['user_id'], $switch['national_id'], $switchName);
        $this->onboard($createProfile, $ids, $owner['user_id'], $owner['national_id'], 'Owner Patient');

        $payload = [
            'password' => $password,
            'fresh' => [
                'phone' => $fresh['phone'],
                'password' => $password,
                'national_id' => $fresh['national_id'],
                'full_name' => 'E2E Patient',
            ],
            'switch' => [
                'phone' => $switch['phone'],
                'password' => $password,
                'full_name' => $switchName,
            ],
            'challenger' => [
                'phone' => $challenger['phone'],
                'password' => $password,
            ],
            'owner_national_id' => $owner['national_id'],
        ];

        if (file_put_contents($write, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
            $this->error('Fixture file could not be written.');

            return self::FAILURE;
        }

        @chmod($write, 0600);
        $this->info('Seeded patient Flutter fixture.');

        return self::SUCCESS;
    }

    /**
     * @return array{user_id: string, phone: string, national_id: string}
     */
    private function insertPatient(
        IdentityGenerator $ids,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        Clock $clock,
        UserDirectory $identities,
        string $password,
        string $key,
    ): array {
        $synthetic = new SyntheticEgyptianData;
        $phone = $synthetic->mobileNumber();
        $nationalId = $synthetic->nationalId();
        $parsedPhone = $protector->phone($phone);
        $parsedNid = $protector->nationalId($nationalId);
        $now = $clock->now();
        $userId = $ids->next();

        DB::table('users')->insert([
            'id' => $userId->value,
            'name' => 'Synthetic Patient '.$key,
            'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsedPhone)),
            'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsedPhone)),
            'phone_key_version' => 1,
            'password_hash' => $hasher->hash($password),
            'account_type' => AccountType::Patient->value,
            'status' => AccountStatus::Active->value,
            'language' => LanguagePreference::English->value,
            'credential_version' => 1,
            'phone_verified_at' => $now,
            'last_authenticated_at' => null,
            'bootstrap_exempt' => false,
            'password_must_change' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $identities->insertNationalId(
            $ids->next(),
            $userId,
            $protector->encryptNationalId($parsedNid),
            $protector->nationalIdHmac($parsedNid),
            $protector->encryptionVersion(),
            $protector->hmacVersion(),
            $now,
        );

        return [
            'user_id' => $userId->value,
            'phone' => $phone,
            'national_id' => $nationalId,
        ];
    }

    private function onboard(
        CreatePatientProfile $createProfile,
        IdentityGenerator $ids,
        string $userId,
        string $nationalId,
        string $fullName,
    ): void {
        $actor = new ActorContext(
            Identifier::fromTrusted($userId),
            AccountType::Patient,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal1Password,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );

        $outcome = $createProfile->handle($actor, [
            'national_id' => $nationalId,
            'full_name' => $fullName,
            'gender' => 'female',
            'date_of_birth' => '1990-01-15',
            'height_cm' => 165.5,
            'weight_kg' => 62.3,
            'marital_status' => 'single',
            'blood_type' => 'A+',
        ], $ids->next());

        if ($outcome->status !== OnboardingOutcome::PROFILE_READY) {
            throw new RuntimeException('Expected profile_ready for the seeded actor.');
        }
    }
}
