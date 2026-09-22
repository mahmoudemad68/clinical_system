#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Synthetic Patient actors for Chunk 13 Flutter profile E2E.
 *
 * Writes credentials to CLINIC_PATIENT_E2E_FIXTURES (mode 0600). stdout
 * contains user ids only — never National IDs or phones.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Patients\Support\PatientProfileRowFactory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = getenv('CLINIC_PATIENT_E2E_FIXTURES');
if (! is_string($out) || $out === '') {
    fwrite(STDERR, "CLINIC_PATIENT_E2E_FIXTURES is required.\n");
    exit(1);
}

$password = 'correct-horse-battery';
$ids = $app->make(IdentityGenerator::class);
$clock = $app->make(Clock::class);
$users = $app->make(UserDirectory::class);
$hasher = $app->make(PasswordHasher::class);
$protector = $app->make(NationalIdProtector::class);
$profiles = $app->make(PatientProfileRowFactory::class);
$synthetic = new SyntheticEgyptianData;
$now = $clock->now();
$hash = $hasher->hash($password);

$insertActor = function (string $name) use ($ids, $users, $protector, $synthetic, $now, $hash): array {
    $id = $ids->next();
    $phone = $synthetic->mobileNumber();
    $parsedPhone = $protector->phone($phone);
    $nationalIdRaw = $synthetic->nationalId();
    $nationalId = $protector->nationalId($nationalIdRaw);
    $users->insertUser(
        new UserAccount(
            $id,
            $name,
            AccountType::Patient,
            AccountStatus::Active,
            LanguagePreference::English,
            $hash,
            1,
            true,
            false,
        ),
        $protector->encryptPhone($parsedPhone),
        $protector->phoneHmac($parsedPhone),
        $protector->encryptionVersion(),
        $protector->hmacVersion(),
        $now,
    );
    $users->insertNationalId(
        $ids->next(),
        $id,
        $protector->encryptNationalId($nationalId),
        $protector->nationalIdHmac($nationalId),
        $protector->encryptionVersion(),
        $protector->hmacVersion(),
        $now,
    );

    return [
        'user_id' => $id->value,
        'phone' => $phone,
        'national_id' => $nationalIdRaw,
        'name' => $name,
    ];
};

$insertLinkedProfile = function (array $actor, string $displayName) use ($ids, $profiles, $protector, $now): void {
    $nationalId = $protector->nationalId($actor['national_id']);
    $row = $profiles->attributes(
        $ids->next(),
        Identifier::fromTrusted($actor['user_id']),
        $nationalId,
        [
            'full_name' => $displayName,
            'gender' => 'female',
            'date_of_birth' => '1990-01-15',
            'height_cm' => 165.5,
            'weight_kg' => 62.3,
            'marital_status' => 'single',
            'blood_type' => 'A+',
        ],
        'user',
        Identifier::fromTrusted($actor['user_id']),
        $now,
    );
    DB::table('patient_profiles')->insert($row);
};

$insertUnlinkedProfile = function (string $nationalIdRaw, Identifier $createdBy) use ($ids, $profiles, $protector, $now): void {
    $nationalId = $protector->nationalId($nationalIdRaw);
    $row = $profiles->attributes(
        $ids->next(),
        null,
        $nationalId,
        [
            'full_name' => 'Walk In',
            'gender' => 'male',
            'date_of_birth' => '1988-04-04',
            'height_cm' => 170,
            'weight_kg' => 70,
            'marital_status' => 'single',
            'blood_type' => 'O+',
        ],
        'user',
        $createdBy,
        $now,
    );
    DB::table('patient_profiles')->insert($row);
};

$onboarder = $insertActor('Chunk 13 Onboarder');
$patientA = $insertActor('Patient A Visible');
$patientB = $insertActor('Patient B Visible');
$conflict = $insertActor('Conflict Patient');
$reviewer = $insertActor('Review Patient');

$insertLinkedProfile($patientA, 'Patient A Visible');
$insertLinkedProfile($patientB, 'Patient B Visible');
$insertLinkedProfile($conflict, 'Conflict Patient');
$insertUnlinkedProfile($reviewer['national_id'], Identifier::fromTrusted($reviewer['user_id']));

$payload = [
    'password' => $password,
    'onboarder' => $onboarder,
    'patientA' => $patientA,
    'patientB' => $patientB,
    'conflict' => $conflict,
    'reviewer' => $reviewer,
];

$directory = dirname($out);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
chmod($out, 0600);

fwrite(STDOUT, 'seeded '.$onboarder['user_id'].' '.$patientA['user_id']."\n");
