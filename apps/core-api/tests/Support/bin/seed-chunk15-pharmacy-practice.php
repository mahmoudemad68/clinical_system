#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Synthetic actors for Chunk 15 Pharmacy desktop Forge GUI E2E.
 *
 * Writes credentials to CLINIC_PHARMACY_PRACTICE_E2E_FIXTURES (mode 0600).
 * stdout contains user ids only — never phones or addresses.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Support\Identifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = getenv('CLINIC_PHARMACY_PRACTICE_E2E_FIXTURES');
if (! is_string($out) || $out === '') {
    fwrite(STDERR, "CLINIC_PHARMACY_PRACTICE_E2E_FIXTURES is required.\n");
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
$rows = $app->make(PharmacyOrganizationRowFactory::class);
$store = $app->make(PostgresPharmacyOrganizationStore::class);
$synthetic = new SyntheticEgyptianData;
$now = $clock->now();
$hash = $hasher->hash($password);

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

$insertOrganization = function (
    array $actor,
    bool $approve,
    string $publicName,
) use ($ids, $protector, $rows, $store, $now): array {
    $orgId = $ids->next();
    $branchId = $ids->next();
    $membershipId = $ids->next();
    $phone = $protector->phone($actor['phone']);
    $registration = 'CR15'.substr(str_replace('-', '', $orgId->value), 0, 12);
    $store->insertOrganization($rows->organizationAttributes(
        $orgId,
        $registration,
        [
            'legal_name' => $publicName.' LLC',
            'public_name' => $publicName,
        ],
        $now,
    ));
    $store->insertBranch(
        $rows->branchAttributes(
            $branchId,
            $orgId,
            $phone,
            [
                'branch_public_name' => $publicName.' Initial',
                'address' => '5 Abbas El Akkad, Cairo',
            ],
            $now,
        ),
        31.2357,
        30.0444,
    );
    $store->insertMembership($rows->ownerMembershipAttributes(
        $membershipId,
        $orgId,
        Identifier::fromTrusted($actor['user_id']),
        $now,
    ));

    if ($approve) {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        DB::table('pharmacy_organizations')->where('id', $orgId->value)->update([
            'verification_status' => PharmacyVerificationStatus::Approved->value,
            'status' => PharmacyOrganizationStatus::Active->value,
            'version' => 2,
            'updated_at' => $stamp,
        ]);
        DB::table('pharmacy_branches')->where('id', $branchId->value)->update([
            'status' => PharmacyBranchStatus::Active->value,
            'version' => 2,
            'updated_at' => $stamp,
        ]);
        DB::table('pharmacy_memberships')->where('id', $membershipId->value)->update([
            'status' => PharmacyMembershipStatus::Active->value,
            'version' => 2,
            'updated_at' => $stamp,
        ]);
    }

    return [
        'organization_id' => $orgId->value,
        'branch_id' => $branchId->value,
        'membership_id' => $membershipId->value,
    ];
};

$ownerA = $insertActor(AccountType::Pharmacy, 'Chunk 15 Owner A', true);
$ownerB = $insertActor(AccountType::Pharmacy, 'Chunk 15 Owner B', true);
$pending = $insertActor(AccountType::Pharmacy, 'Chunk 15 Pending Owner', true);
$operator = $insertActor(AccountType::Pharmacy, 'Chunk 15 Operator', true);
$doctor = $insertActor(AccountType::Doctor, 'Chunk 15 Doctor', true);
$patient = $insertActor(AccountType::Patient, 'Chunk 15 Patient', false);

$ownerAOrg = $insertOrganization($ownerA, true, 'Owner A Pharmacy');
$ownerBOrg = $insertOrganization($ownerB, true, 'Owner B Pharmacy');
$pendingOrg = $insertOrganization($pending, false, 'Pending Pharmacy');

$payload = [
    'password' => $password,
    'ownerA' => $ownerA + $ownerAOrg,
    'ownerB' => $ownerB + $ownerBOrg,
    'pendingOwner' => $pending + $pendingOrg,
    'operator' => $operator,
    'doctor' => $doctor,
    'patient' => $patient,
];

$directory = dirname($out);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
chmod($out, 0600);

fwrite(STDOUT, 'seeded '.$ownerA['user_id'].' '.$ownerB['user_id']."\n");
