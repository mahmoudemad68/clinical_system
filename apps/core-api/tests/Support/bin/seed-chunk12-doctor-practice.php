#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Synthetic actors for Chunk 12 Doctor desktop Forge GUI E2E.
 *
 * Writes credentials to CLINIC_PRACTICE_E2E_FIXTURES (mode 0600). stdout
 * contains user ids only.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Support\DoctorProfileRowFactory;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = getenv('CLINIC_PRACTICE_E2E_FIXTURES');
if (! is_string($out) || $out === '') {
    fwrite(STDERR, "CLINIC_PRACTICE_E2E_FIXTURES is required.\n");
    exit(1);
}

$password = 'correct-horse-battery';
$ids = $app->make(IdentityGenerator::class);
$clock = $app->make(Clock::class);
$users = $app->make(UserDirectory::class);
$auth = $app->make(AuthDirectory::class);
$hasher = $app->make(PasswordHasher::class);
$totp = $app->make(TotpVerifier::class);
$protector = $app->make(NationalIdProtector::class);
$profiles = $app->make(DoctorProfileRowFactory::class);
$synthetic = new SyntheticEgyptianData;
$now = $clock->now();
$hash = $hasher->hash($password);

$existingSpecialty = DB::table('specialties')->where('active', true)->orderBy('sort_order')->first();
if ($existingSpecialty !== null) {
    $specialtyId = (string) $existingSpecialty->id;
} else {
    $specialtyId = $ids->next()->value;
    DB::table('specialties')->insert([
        'id' => $specialtyId,
        'code' => 'gp_chunk12_'.substr($ids->next()->value, 0, 8),
        'label_ar' => 'طب الأسرة',
        'label_en' => 'General Practice',
        'active' => true,
        'sort_order' => 10,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

$insertActor = function (
    AccountType $type,
    string $name,
    bool $withTotp,
) use ($ids, $users, $auth, $protector, $totp, $synthetic, $now, $hash): array {
    $id = $ids->next();
    $phone = $synthetic->mobileNumber();
    $parsed = $protector->phone($phone);
    $users->insertUser(
        new UserAccount(
            $id,
            $name,
            $type,
            AccountStatus::Active,
            LanguagePreference::English,
            $hash,
            1,
            true,
            false,
        ),
        $protector->encryptPhone($parsed),
        $protector->phoneHmac($parsed),
        $protector->encryptionVersion(),
        $protector->hmacVersion(),
        $now,
    );

    $secret = null;
    if ($withTotp) {
        $secret = $totp->generateSecret();
        $auth->insertTotpFactor([
            'id' => $ids->next()->value,
            'user_id' => $id->value,
            'factor_type' => 'totp',
            'secret_ciphertext' => $protector->encryptSecret('mfa_secret', $secret),
            'key_version' => $protector->encryptionVersion(),
            'last_used_counter' => null,
            'last_used_at' => null,
            'verified_at' => $now->format('Y-m-d H:i:s.uP'),
            'disabled_at' => null,
            'disabled_by' => null,
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    return [
        'user_id' => $id->value,
        'phone' => $phone,
        'totp_secret' => $secret,
    ];
};

$insertDoctorProfile = function (
    string $userId,
    string $status,
) use ($ids, $profiles, $protector, $synthetic, $specialtyId, $now): string {
    $doctorId = $ids->next();
    $nationalId = $protector->nationalId($synthetic->nationalId());
    $row = $profiles->attributes(
        $doctorId,
        Identifier::fromTrusted($userId),
        $nationalId,
        Identifier::fromTrusted($specialtyId),
        null,
        ['professional_display_name' => 'Chunk 12 Doctor'],
        $now,
    );
    if ($status === DoctorVerificationStatus::Approved->value) {
        $row['verification_status'] = DoctorVerificationStatus::Approved->value;
        $row['public_status'] = DoctorPublicStatus::Listed->value;
        $row['approved_at'] = $now->format('Y-m-d H:i:s.uP');
    } else {
        $row['verification_status'] = $status;
    }
    DB::table('doctor_profiles')->insert($row);

    return $doctorId->value;
};

$doctorA = $insertActor(AccountType::Doctor, 'Chunk 12 Doctor A', true);
$doctorB = $insertActor(AccountType::Doctor, 'Chunk 12 Doctor B', true);
$pending = $insertActor(AccountType::Doctor, 'Chunk 12 Pending Doctor', true);
$secretary = $insertActor(AccountType::Secretary, 'Chunk 12 Secretary', false);
$patient = $insertActor(AccountType::Patient, 'Chunk 12 Patient', false);
$pharmacy = $insertActor(AccountType::Pharmacy, 'Chunk 12 Pharmacy', false);

$doctorA['doctor_id'] = $insertDoctorProfile($doctorA['user_id'], DoctorVerificationStatus::Approved->value);
$doctorB['doctor_id'] = $insertDoctorProfile($doctorB['user_id'], DoctorVerificationStatus::Approved->value);
$pending['doctor_id'] = $insertDoctorProfile($pending['user_id'], DoctorVerificationStatus::PendingReview->value);

$payload = [
    'password' => $password,
    'doctorA' => $doctorA,
    'doctorB' => $doctorB,
    'pendingDoctor' => $pending,
    'secretary' => $secretary,
    'patient' => $patient,
    'pharmacy' => $pharmacy,
];

$directory = dirname($out);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
chmod($out, 0600);

fwrite(STDOUT, 'seeded '.$doctorA['user_id'].' '.$doctorB['user_id']."\n");
