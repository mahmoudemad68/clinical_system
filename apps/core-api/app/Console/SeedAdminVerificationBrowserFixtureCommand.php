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
use Modules\Pharmacies\Services\RegisterPharmacyOrganization;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;
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
        RegisterPharmacyOrganization $registerPharmacy,
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
        $creator = $this->insertStaff($ids, $protector, $hasher, $totp, 'creator', AccountType::Admin);
        $approver = $this->insertStaff($ids, $protector, $hasher, $totp, 'approver', AccountType::Admin);
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

        // Synthetic E2E fixture only — not a production specialty catalogue.
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
        $this->uploadCleanRequirements(
            $uploads,
            $objects,
            $policy,
            $processor,
            $doctorActor,
            $opened->caseId,
            $bytes,
            ['medical_license', 'national_id_or_passport'],
        );

        $verification->submitDoctorCase($doctorActor, [
            'case_version' => $opened->caseVersion,
            'profile_version' => $opened->profileVersion,
        ]);

        $pharmacyPublicName = 'E2E Pharmacy Review';
        $pharmacyCanaries = [
            'legal_name' => 'CANARY-LEGAL-PHARMACY-NAME',
            'registration' => 'CANARY-REG-CR-998877',
            'address' => 'CANARY-BRANCH-ADDRESS-99 Nile St',
            'phone' => '01911112222',
        ];
        $this->seedPendingPharmacyCase(
            $ids,
            $protector,
            $hasher,
            $totp,
            $clock,
            $registerPharmacy,
            $verification,
            $uploads,
            $objects,
            $policy,
            $processor,
            $password,
            $bytes,
            $pharmacyPublicName,
            $pharmacyCanaries,
        );

        $applicantPhone = $synthetic->mobileNumber();
        $applicantNationalId = $synthetic->nationalId();
        $applicantName = 'Dr Admin Created E2E';

        $payload = [
            'reviewer' => [
                'phone' => $reviewer['phone'],
                'password' => $password,
                'totp_secret' => $reviewer['totp_secret'],
            ],
            'creator' => [
                'phone' => $creator['phone'],
                'password' => $password,
                'totp_secret' => $creator['totp_secret'],
            ],
            'approver' => [
                'phone' => $approver['phone'],
                'password' => $password,
                'totp_secret' => $approver['totp_secret'],
            ],
            'unauthorized' => [
                'phone' => $unauthorized['phone'],
                'password' => $password,
                'totp_secret' => $unauthorized['totp_secret'],
            ],
            'case' => [
                'professional_display_name' => 'Dr E2E Review',
            ],
            'applicant' => [
                'phone' => $applicantPhone,
                'national_id' => $applicantNationalId,
                'password' => $password,
                'professional_display_name' => $applicantName,
                'evidence_source' => 'in_person_originals',
            ],
            'pharmacy_case' => [
                'public_name' => $pharmacyPublicName,
            ],
            'canaries' => $pharmacyCanaries,
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
     * @param  array{legal_name: string, registration: string, address: string, phone: string}  $canaries
     */
    private function seedPendingPharmacyCase(
        IdentityGenerator $ids,
        NationalIdProtector $protector,
        PasswordHasher $hasher,
        TotpVerifier $totp,
        Clock $clock,
        RegisterPharmacyOrganization $registerPharmacy,
        VerificationService $verification,
        VerificationUploadService $uploads,
        StoreObject $objects,
        VerificationPolicy $policy,
        VerificationUploadProcessor $processor,
        string $password,
        string $bytes,
        string $publicName,
        array $canaries,
    ): void {
        $synthetic = new SyntheticEgyptianData;
        $phone = $synthetic->mobileNumber();
        $parsedPhone = $protector->phone($phone);
        $now = $clock->now();
        $pharmacyUserId = $ids->next();

        DB::table('users')->insert([
            'id' => $pharmacyUserId->value,
            'name' => 'Synthetic Pharmacy E2E',
            'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($parsedPhone)),
            'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($parsedPhone)),
            'phone_key_version' => 1,
            'password_hash' => $hasher->hash($password),
            'account_type' => AccountType::Pharmacy->value,
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
            'user_id' => $pharmacyUserId->value,
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
        unset($secret);

        $pharmacyActor = new ActorContext(
            $pharmacyUserId,
            AccountType::Pharmacy,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal2Totp,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );

        $onboarded = $registerPharmacy->handle($pharmacyActor, [
            'legal_name' => $canaries['legal_name'],
            'public_name' => $publicName,
            'legal_registration_identifier' => $canaries['registration'],
            'branch_public_name' => 'E2E Main Branch',
            'address' => $canaries['address'],
            'country_code' => 'EG',
            'latitude' => 30.0444,
            'longitude' => 31.2357,
            'phone' => $canaries['phone'],
        ]);
        if ($onboarded->organizationId === null || $onboarded->version === null) {
            throw new RuntimeException('Pharmacy onboarding did not produce an organization.');
        }

        $opened = $verification->openPharmacyCase($pharmacyActor);
        $this->uploadCleanRequirements(
            $uploads,
            $objects,
            $policy,
            $processor,
            $pharmacyActor,
            $opened->caseId,
            $bytes,
            ['pharmacy_facility_license', 'commercial_register', 'responsible_pharmacist_license'],
        );

        $verification->submitPharmacyCase($pharmacyActor, [
            'case_version' => $opened->caseVersion,
            'organization_version' => $opened->organizationVersion,
        ]);
    }

    /**
     * @param  list<string>  $requirementCodes
     */
    private function uploadCleanRequirements(
        VerificationUploadService $uploads,
        StoreObject $objects,
        VerificationPolicy $policy,
        VerificationUploadProcessor $processor,
        ActorContext $actor,
        string $caseId,
        string $bytes,
        array $requirementCodes,
    ): void {
        foreach ($requirementCodes as $code) {
            $created = $uploads->createDoctorUpload($actor, [
                'case_id' => $caseId,
                'requirement_code' => $code,
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
            $uploads->completeDoctorUpload($actor, Identifier::fromString($created['projection']->uploadId));
            $processor->process(Identifier::fromString($created['projection']->uploadId));
        }
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
