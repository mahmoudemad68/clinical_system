<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Doctors\Services\RegisterDoctor;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\ScanVerdict;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Services\VerificationUploadProcessor;
use Modules\Verification\Services\VerificationUploadService;
use Modules\Verification\Support\VerificationPolicy;
use RuntimeException;

/**
 * Synthetic browser-admin fixture for Playwright. Disabled outside local/testing.
 *
 * Writes reviewer credentials to a caller-supplied path. Does not print secrets.
 */
final class SeedAdminVerificationBrowserFixtureCommand extends Command
{
    protected $signature = 'e2e:seed-admin-verification {--write= : Absolute path for the fixture JSON}';

    protected $description = 'Seed a synthetic admin verification browser fixture (local/testing only).';

    public function handle(
        IdentityGenerator $ids,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        TotpVerifier $totp,
        Clock $clock,
        UserDirectory $identities,
        RegisterDoctor $registerDoctor,
        VerificationService $verification,
        VerificationUploadService $uploads,
        StoreObject $objects,
        VerificationPolicy $policy,
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

        $this->laravel->instance(ScanObject::class, new E2eCleanScanObject);
        $this->laravel->forgetInstance(VerificationUploadProcessor::class);
        $processor = $this->laravel->make(VerificationUploadProcessor::class);
        if (! $processor instanceof VerificationUploadProcessor) {
            throw new RuntimeException('Upload processor is not bound.');
        }

        $password = 'correct-horse-battery';
        $reviewer = $this->insertStaff($ids, $protector, $hasher, $totp, 'reviewer', AccountType::Admin);
        // Secretary can use admin_web cookies and /me, but Capabilities::forActor
        // never grants verification.case.review. password_must_change admins
        // cannot read /me (DenyPendingBusinessAccess), so they cannot exercise
        // the authenticated-unauthorized workspace.
        $unauthorized = $this->insertStaff($ids, $protector, $hasher, $totp, 'unauthorized', AccountType::Secretary);

        $synthetic = new SyntheticEgyptianData;
        $phone = $synthetic->mobileNumber();
        $nationalId = $synthetic->nationalId();
        $parsedPhone = $protector->phone($phone);
        $parsedNid = $protector->nationalId($nationalId);
        $now = $clock->now();
        $doctorUserId = $ids->next();

        DB::table('users')->insert([
            'id' => $doctorUserId->value,
            'name' => 'Synthetic Doctor E2E',
            'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsedPhone)),
            'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsedPhone)),
            'phone_key_version' => 1,
            'password_hash' => $hasher->hash($password),
            'account_type' => AccountType::Doctor->value,
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
            $doctorUserId,
            $protector->encryptNationalId($parsedNid),
            $protector->nationalIdHmac($parsedNid),
            $protector->encryptionVersion(),
            $protector->hmacVersion(),
            $now,
        );

        $specialtyId = $ids->next();
        DB::table('specialties')->insert([
            'id' => $specialtyId->value,
            'code' => 'gpe2e'.bin2hex(random_bytes(6)),
            'label_ar' => 'طب الأسرة',
            'label_en' => 'General Practice',
            'active' => true,
            'sort_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $doctorActor = new ActorContext(
            $doctorUserId,
            AccountType::Doctor,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal2Totp,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );

        $onboarded = $registerDoctor->handle($doctorActor, [
            'national_id' => $nationalId,
            'professional_display_name' => 'Dr E2E Review',
            'specialty_id' => $specialtyId->value,
        ]);
        if ($onboarded->doctorId === null || $onboarded->version === null) {
            throw new RuntimeException('Doctor onboarding did not produce a profile.');
        }

        $opened = $verification->openDoctorCase($doctorActor);
        if ($opened->caseId === null || $opened->caseVersion === null) {
            throw new RuntimeException('Verification case was not opened.');
        }

        $bytes = "%PDF-1.4\n"
            ."1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
            ."2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n"
            ."3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>endobj\n"
            ."trailer<< /Root 1 0 R >>\n"
            ."%%EOF\n";
        $created = $uploads->createDoctorUpload($doctorActor, [
            'case_id' => $opened->caseId,
            'requirement_code' => 'professional_id',
            'expected_size_bytes' => strlen($bytes),
            'declared_media_type' => 'application/pdf',
        ]);
        if (! isset($created['grant'], $created['projection'])) {
            throw new RuntimeException('Upload intent was not created.');
        }
        $objects->writeAt(
            new StoredObjectRef(
                $policy->objectNamespace(),
                $created['grant']->objectId,
                $created['grant']->storageLocator,
            ),
            'application/pdf',
            $bytes,
        );
        $uploads->completeDoctorUpload($doctorActor, Identifier::fromString($created['projection']->uploadId));
        $processor->process(Identifier::fromString($created['projection']->uploadId));

        $verification->submitDoctorCase($doctorActor, [
            'case_version' => $opened->caseVersion,
            'profile_version' => $opened->profileVersion,
        ]);

        $payload = [
            'reviewer' => [
                'phone' => $reviewer['phone'],
                'password' => $password,
                'totp_secret' => $reviewer['totp_secret'],
            ],
            'unauthorized' => [
                'phone' => $unauthorized['phone'],
                'password' => $password,
                'totp_secret' => $unauthorized['totp_secret'],
            ],
            'case' => [
                'professional_display_name' => 'Dr E2E Review',
            ],
        ];

        if (file_put_contents($write, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
            $this->error('Fixture file could not be written.');

            return self::FAILURE;
        }

        @chmod($write, 0600);
        $this->info('Seeded browser verification fixture.');

        return self::SUCCESS;
    }

    /**
     * @return array{phone: string, totp_secret: string}
     */
    private function insertStaff(
        IdentityGenerator $ids,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        TotpVerifier $totp,
        string $key,
        AccountType $accountType,
    ): array {
        $synthetic = new SyntheticEgyptianData;
        $phone = $synthetic->mobileNumber();
        $parsed = $protector->phone($phone);
        $now = now('UTC');
        $userId = $ids->next();
        $password = 'correct-horse-battery';

        DB::table('users')->insert([
            'id' => $userId->value,
            'name' => 'Synthetic '.$accountType->value.' '.$key,
            'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsed)),
            'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsed)),
            'phone_key_version' => 1,
            'password_hash' => $hasher->hash($password),
            'account_type' => $accountType->value,
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

        $secret = $totp->generateSecret();
        DB::table('mfa_factors')->insert([
            'id' => $ids->next()->value,
            'user_id' => $userId->value,
            'factor_type' => 'totp',
            'secret_ciphertext' => BinaryColumn::bind($protector->encryptSecret('mfa_secret', $secret)),
            'key_version' => 1,
            'last_used_counter' => null,
            'last_used_at' => null,
            'verified_at' => $now,
            'disabled_at' => null,
            'disabled_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'phone' => $phone,
            'totp_secret' => $secret,
        ];
    }
}

/**
 * Opt-in clean scanner for the browser fixture seeder. Not a production adapter.
 */
final class E2eCleanScanObject implements ScanObject
{
    public function scanStream(mixed $stream, int $sizeBytes): ScanVerdict
    {
        if (is_resource($stream)) {
            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
            }
        }

        unset($sizeBytes);

        return ScanVerdict::clean('e2e-fixture', 'test');
    }
}
