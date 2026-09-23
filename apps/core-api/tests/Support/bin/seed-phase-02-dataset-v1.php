#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 02 Dataset v1 synthetic load-test seed.
 *
 * Cardinality matches the approved P02-AUDIT-002 benchmark. Identifiers are
 * SyntheticEgyptianData ranges (century 9 / 019 prefix). Tokens are written
 * only to the caller-supplied path (mode 0600). stdout has counts, not secrets.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Services\CredentialIssuer;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Symfony\Component\Uid\UuidV7;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = getenv('CLINIC_P02_DATASET_V1_ACTORS');
if (! is_string($out) || $out === '') {
    fwrite(STDERR, "CLINIC_P02_DATASET_V1_ACTORS is required.\n");
    exit(1);
}

$counts = [
    'patients' => (int) (getenv('CLINIC_P02_PATIENTS') ?: 50000),
    'doctors' => (int) (getenv('CLINIC_P02_DOCTORS') ?: 5000),
    'pharmacy_orgs' => (int) (getenv('CLINIC_P02_PHARMACY_ORGS') ?: 2000),
    'pharmacy_branches' => (int) (getenv('CLINIC_P02_PHARMACY_BRANCHES') ?: 6000),
    'clinic_locations' => (int) (getenv('CLINIC_P02_CLINIC_LOCATIONS') ?: 15000),
    'clinic_memberships' => (int) (getenv('CLINIC_P02_CLINIC_MEMBERSHIPS') ?: 45000),
    'pharmacy_memberships' => (int) (getenv('CLINIC_P02_PHARMACY_MEMBERSHIPS') ?: 24000),
    'admins' => (int) (getenv('CLINIC_P02_ADMINS') ?: 20),
    'actor_patients' => (int) (getenv('CLINIC_P02_ACTOR_PATIENTS') ?: 500),
    'actor_doctors_approved' => (int) (getenv('CLINIC_P02_ACTOR_DOCTORS_APPROVED') ?: 400),
    'actor_doctors_pending' => (int) (getenv('CLINIC_P02_ACTOR_DOCTORS_PENDING') ?: 50),
    'actor_doctors_onboard' => (int) (getenv('CLINIC_P02_ACTOR_DOCTORS_ONBOARD') ?: 50),
    'actor_pharmacies' => (int) (getenv('CLINIC_P02_ACTOR_PHARMACIES') ?: 250),
    'actor_admins' => (int) (getenv('CLINIC_P02_ACTOR_ADMINS') ?: 20),
];

$protector = $app->make(NationalIdProtector::class);
$hasher = $app->make(PasswordHasher::class);
$credentials = $app->make(CredentialIssuer::class);
$hmac = $app->make(HmacHasher::class);
$clock = $app->make(Clock::class);
$now = $clock->now();
$stamp = $now->format('Y-m-d H:i:s.uP');
$passwordHash = $hasher->hash('correct-horse-battery');
$pdo = DB::connection()->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

fwrite(STDOUT, "dataset-v1 seed starting\n");

DB::statement('SET synchronous_commit = off');
DB::statement("SET statement_timeout = '0'");

$uuid = static fn (): string => (new UuidV7)->toRfc4122();

$phoneOf = static function (int $index) use ($protector): array {
    $raw = '019'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
    $parsed = $protector->phone($raw);

    return [
        'raw' => $raw,
        'cipher' => BinaryColumn::bind($protector->encryptPhone($parsed)),
        'hmac' => BinaryColumn::bind($protector->phoneHmac($parsed)),
    ];
};

$nidOf = static function (int $index) use ($protector): array {
    $raw = '9999999'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
    $parsed = $protector->nationalId($raw);

    return [
        'raw' => $raw,
        'cipher' => BinaryColumn::bind($protector->encryptNationalId($parsed)),
        'hmac' => BinaryColumn::bind($protector->nationalIdHmac($parsed)),
    ];
};

$copy = static function (string $table, array $columns, array $rows) use ($pdo): void {
    if ($rows === []) {
        return;
    }
    $ok = $pdo->pgsqlCopyFromArray($table, $rows, "\t", '\\\\N', implode(',', $columns));
    if ($ok !== true) {
        throw new RuntimeException('COPY failed for '.$table);
    }
};

$escape = static function (mixed $value): string {
    if ($value === null) {
        return '\\N';
    }
    if (is_bool($value)) {
        return $value ? 't' : 'f';
    }
    $s = (string) $value;

    return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $s);
};

$row = static function (array $fields) use ($escape): string {
    return implode("\t", array_map($escape, $fields));
};

$governorates = [
    ['Cairo', 30.0444, 31.2357, 0.20, 0.012],
    ['Giza', 30.0131, 31.2089, 0.15, 0.010],
    ['Qalyubia', 30.1792, 31.2056, 0.05, 0.008],
    ['Alexandria', 31.2001, 29.9187, 0.10, 0.010],
    ['Dakahlia', 31.0409, 31.3785, 0.05, 0.040],
    ['Sharqia', 30.5877, 31.5020, 0.05, 0.045],
    ['Beheira', 30.8481, 30.3436, 0.05, 0.050],
    ['Gharbia', 30.8754, 31.0335, 0.04, 0.035],
    ['Monufia', 30.5972, 30.9876, 0.04, 0.030],
    ['KafrElSheikh', 31.1117, 30.9394, 0.04, 0.040],
    ['Damietta', 31.4175, 31.8144, 0.03, 0.025],
    ['PortSaid', 31.2653, 32.3019, 0.03, 0.020],
    ['Ismailia', 30.5965, 32.2715, 0.03, 0.030],
    ['Suez', 29.9668, 32.5498, 0.03, 0.030],
    ['Fayoum', 29.3084, 30.8428, 0.03, 0.035],
    ['BeniSuef', 29.0744, 31.0978, 0.02, 0.040],
    ['Minya', 28.1099, 30.7503, 0.02, 0.050],
    ['Asyut', 27.1783, 31.1859, 0.02, 0.045],
    ['Sohag', 26.5590, 31.6957, 0.01, 0.040],
    ['Qena', 26.1551, 32.7160, 0.01, 0.040],
    ['Luxor', 25.6872, 32.6396, 0.01, 0.030],
    ['Aswan', 24.0889, 32.8998, 0.01, 0.040],
    ['RedSea', 27.2574, 33.8129, 0.01, 0.080],
    ['NewValley', 25.4400, 30.5500, 0.01, 0.120],
    ['Matrouh', 31.3543, 27.2373, 0.01, 0.090],
    ['NorthSinai', 31.1313, 33.8000, 0.005, 0.080],
    ['SouthSinai', 28.2336, 33.6232, 0.005, 0.090],
];

$pickPoint = static function (int $index) use ($governorates): array {
    $slot = ($index * 17) % 10000;
    $acc = 0;
    foreach ($governorates as $gov) {
        $acc += (int) round($gov[3] * 10000);
        if ($slot < $acc) {
            $dense = ($index % 10) < 7;
            $spread = $dense ? $gov[4] * 0.15 : $gov[4];
            $lat = $gov[1] + ((($index * 13) % 1000) / 1000 - 0.5) * $spread;
            $lng = $gov[2] + ((($index * 29) % 1000) / 1000 - 0.5) * $spread;
            $lat = max(22.05, min(31.65, $lat));
            $lng = max(24.75, min(36.85, $lng));

            return [$gov[0], $lat, $lng];
        }
    }

    return ['Cairo', 30.0444, 31.2357];
};

$specialtyId = $uuid();
DB::table('specialties')->insert([
    'id' => $specialtyId,
    'code' => 'general_practice',
    'label_ar' => 'طب الأسرة',
    'label_en' => 'General Practice',
    'active' => true,
    'sort_order' => 10,
    'created_at' => $now,
    'updated_at' => $now,
]);

$approvedDoctors = (int) round($counts['doctors'] * 0.90);
$pendingDoctors = (int) round($counts['doctors'] * 0.05);
$rejectedDoctors = (int) round($counts['doctors'] * 0.01);
$suspendedDoctors = $counts['doctors'] - $approvedDoctors - $pendingDoctors - $rejectedDoctors;
$pharmacyOperators = $counts['pharmacy_memberships'] - $counts['pharmacy_orgs'];
$secretaries = $counts['clinic_memberships'];

$patientUserIds = [];
$doctorUserIds = [];
$doctorProfileIds = [];
$pharmacyOwnerIds = [];
$pharmacyOrgIds = [];
$pharmacyBranchIds = [];
$locationIds = [];
$locationDoctorIds = [];
$adminIds = [];
$onboardDoctorIndexes = [];

$userColumns = [
    'id', 'name', 'phone_e164_encrypted', 'phone_lookup_hmac', 'phone_key_version',
    'phone_hmac_version', 'password_hash', 'account_type', 'status', 'language',
    'credential_version', 'phone_verified_at', 'last_authenticated_at',
    'bootstrap_exempt', 'password_must_change', 'created_at', 'updated_at',
];

$flushUsers = static function (array &$batch) use ($copy, $userColumns): void {
    if ($batch === []) {
        return;
    }
    $copy('users', $userColumns, $batch);
    $batch = [];
};

$userBatch = [];
$phoneIndex = 0;

$addUser = static function (string $id, string $name, string $type) use (&$userBatch, &$phoneIndex, $phoneOf, $passwordHash, $stamp, $row, $flushUsers): void {
    $phone = $phoneOf($phoneIndex);
    $phoneIndex++;
    $userBatch[] = $row([
        $id, $name, $phone['cipher'], $phone['hmac'], 1, 1, $passwordHash,
        $type, 'active', 'en', 1, $stamp, null, false, false, $stamp, $stamp,
    ]);
    if (count($userBatch) >= 1000) {
        $flushUsers($userBatch);
    }
};

fwrite(STDOUT, "users: patients {$counts['patients']}\n");
for ($i = 0; $i < $counts['patients']; $i++) {
    $id = $uuid();
    $patientUserIds[] = $id;
    $addUser($id, 'Synthetic Patient '.$i, 'patient');
}

fwrite(STDOUT, "users: doctors {$counts['doctors']}\n");
for ($i = 0; $i < $counts['doctors']; $i++) {
    $id = $uuid();
    $doctorUserIds[] = $id;
    $addUser($id, 'Synthetic Doctor '.$i, 'doctor');
}

fwrite(STDOUT, "users: pharmacy owners {$counts['pharmacy_orgs']}\n");
for ($i = 0; $i < $counts['pharmacy_orgs']; $i++) {
    $id = $uuid();
    $pharmacyOwnerIds[] = $id;
    $addUser($id, 'Synthetic Pharmacy Owner '.$i, 'pharmacy');
}

fwrite(STDOUT, "users: pharmacy operators {$pharmacyOperators}\n");
$pharmacyOperatorIds = [];
for ($i = 0; $i < $pharmacyOperators; $i++) {
    $id = $uuid();
    $pharmacyOperatorIds[] = $id;
    $addUser($id, 'Synthetic Pharmacy Operator '.$i, 'pharmacy');
}

fwrite(STDOUT, "users: secretaries {$secretaries}\n");
$secretaryIds = [];
for ($i = 0; $i < $secretaries; $i++) {
    $id = $uuid();
    $secretaryIds[] = $id;
    $addUser($id, 'Synthetic Secretary '.$i, 'secretary');
}

fwrite(STDOUT, "users: admins {$counts['admins']}\n");
for ($i = 0; $i < $counts['admins']; $i++) {
    $id = $uuid();
    $adminIds[] = $id;
    $addUser($id, 'Synthetic Admin '.$i, 'admin');
}

$onboardStart = $counts['doctors'] - $counts['actor_doctors_onboard'];
for ($i = $onboardStart; $i < $counts['doctors']; $i++) {
    $onboardDoctorIndexes[] = $i;
}

$flushUsers($userBatch);

$nidColumns = [
    'id', 'user_id', 'national_id_encrypted', 'national_id_lookup_hmac',
    'key_version', 'hmac_key_version', 'created_at', 'updated_at',
];
$nidBatch = [];
$nidIndex = 0;
$addNid = static function (string $userId) use (&$nidBatch, &$nidIndex, $nidOf, $uuid, $stamp, $row, $copy, $nidColumns): array {
    $nid = $nidOf($nidIndex);
    $nidIndex++;
    $nidBatch[] = $row([$uuid(), $userId, $nid['cipher'], $nid['hmac'], 1, 1, $stamp, $stamp]);
    if (count($nidBatch) >= 1000) {
        $copy('identity_national_ids', $nidColumns, $nidBatch);
        $nidBatch = [];
    }

    return $nid;
};

fwrite(STDOUT, "national ids + patient profiles\n");
$patientNids = [];
$patientColumns = [
    'id', 'user_id', 'national_id_ciphertext', 'national_id_lookup_hmac',
    'national_id_key_version', 'full_name_ciphertext', 'gender', 'date_of_birth',
    'height_cm', 'weight_kg', 'marital_status', 'blood_type', 'status',
    'created_by_type', 'created_by_id', 'version', 'created_at', 'updated_at',
];
$patientBatch = [];
for ($i = 0; $i < $counts['patients']; $i++) {
    $nid = $addNid($patientUserIds[$i]);
    $patientNids[$i] = $nid['raw'];
    $nameCipher = BinaryColumn::bind($protector->encryptSecret('patient_full_name', 'Synthetic Patient '.$i));
    $patientBatch[] = $row([
        $uuid(), $patientUserIds[$i], $nid['cipher'], $nid['hmac'], 1, $nameCipher,
        $i % 2 === 0 ? 'male' : 'female', '1990-01-01', '170.00', '70.00',
        'single', 'O+', 'active', 'user', $patientUserIds[$i], 1, $stamp, $stamp,
    ]);
    if (count($patientBatch) >= 1000) {
        $copy('patient_profiles', $patientColumns, $patientBatch);
        $patientBatch = [];
    }
}
$copy('patient_profiles', $patientColumns, $patientBatch);

fwrite(STDOUT, "doctor profiles\n");
$doctorNids = [];
$doctorColumns = [
    'id', 'user_id', 'source_type', 'created_by_user_id', 'national_id_ciphertext',
    'national_id_lookup_hmac', 'national_id_key_version', 'syndicate_number_ciphertext',
    'syndicate_number_lookup_hmac', 'syndicate_number_key_version', 'specialty_id',
    'professional_display_name', 'verification_status', 'public_status', 'version',
    'approved_at', 'suspended_at', 'created_at', 'updated_at',
];
$doctorBatch = [];
$profileCount = $counts['doctors'] - $counts['actor_doctors_onboard'];
for ($i = 0; $i < $profileCount; $i++) {
    $nid = $addNid($doctorUserIds[$i]);
    $doctorNids[$i] = $nid['raw'];
    $id = $uuid();
    $doctorProfileIds[$i] = $id;
    if ($i < $approvedDoctors) {
        $status = 'approved';
        $public = 'listed';
        $approvedAt = $stamp;
        $suspendedAt = null;
    } elseif ($i < $approvedDoctors + $pendingDoctors) {
        $status = 'pending_review';
        $public = 'hidden';
        $approvedAt = null;
        $suspendedAt = null;
    } elseif ($i < $approvedDoctors + $pendingDoctors + $rejectedDoctors) {
        $status = 'rejected';
        $public = 'hidden';
        $approvedAt = null;
        $suspendedAt = null;
    } else {
        $status = 'suspended';
        $public = 'hidden';
        $approvedAt = $stamp;
        $suspendedAt = $stamp;
    }
    $doctorBatch[] = $row([
        $id, $doctorUserIds[$i], 'self_onboarding', $doctorUserIds[$i],
        $nid['cipher'], $nid['hmac'], 1, null, null, null, $specialtyId,
        'Synthetic Doctor '.$i, $status, $public, 1, $approvedAt, $suspendedAt,
        $stamp, $stamp,
    ]);
    if (count($doctorBatch) >= 1000) {
        $copy('doctor_profiles', $doctorColumns, $doctorBatch);
        $doctorBatch = [];
    }
}
$copy('doctor_profiles', $doctorColumns, $doctorBatch);

for ($i = $profileCount; $i < $counts['doctors']; $i++) {
    $nid = $addNid($doctorUserIds[$i]);
    $doctorNids[$i] = $nid['raw'];
}
if ($nidBatch !== []) {
    $copy('identity_national_ids', $nidColumns, $nidBatch);
}

fwrite(STDOUT, "pharmacy organizations + branches + memberships\n");
$orgColumns = [
    'id', 'legal_name_ciphertext', 'legal_name_key_version', 'public_name',
    'registration_ciphertext', 'registration_lookup_hmac', 'registration_key_version',
    'verification_status', 'status', 'version', 'created_at', 'updated_at',
];
$branchColumns = [
    'id', 'organization_id', 'public_name', 'address_ciphertext', 'address_key_version',
    'country_code', 'phone_ciphertext', 'phone_key_version', 'status', 'version',
    'created_at', 'updated_at', 'geography_point',
];
$membershipColumns = [
    'id', 'organization_id', 'user_id', 'branch_id', 'role', 'status',
    'invited_at', 'accepted_at', 'revoked_at', 'inviter_user_id', 'revoker_user_id',
    'version', 'created_at', 'updated_at',
];
$orgBatch = [];
$branchBatch = [];
$membershipBatch = [];
$branchesPerOrg = intdiv($counts['pharmacy_branches'], $counts['pharmacy_orgs']);
$extraBranches = $counts['pharmacy_branches'] % $counts['pharmacy_orgs'];
$operatorCursor = 0;
$branchPhoneBase = 300000;

for ($i = 0; $i < $counts['pharmacy_orgs']; $i++) {
    $orgId = $uuid();
    $pharmacyOrgIds[] = $orgId;
    $registration = 'CR'.str_pad((string) $i, 10, '0', STR_PAD_LEFT);
    $legal = $protector->encryptSecret('legal_name', 'Synthetic Pharmacy LLC '.$i);
    $regCipher = $protector->encryptSecret('legal_registration', $registration);
    $regHmac = $hmac->digest('legal_registration_lookup', $registration);
    $orgBatch[] = $row([
        $orgId, BinaryColumn::bind($legal), 1, 'Synthetic Pharmacy '.$i,
        BinaryColumn::bind($regCipher), BinaryColumn::bind($regHmac), 1,
        'approved', 'active', 1, $stamp, $stamp,
    ]);
    $nBranches = $branchesPerOrg + ($i < $extraBranches ? 1 : 0);
    $orgBranchIds = [];
    for ($b = 0; $b < $nBranches; $b++) {
        $branchId = $uuid();
        $orgBranchIds[] = $branchId;
        $pharmacyBranchIds[] = $branchId;
        [$gov, $lat, $lng] = $pickPoint($i * 10 + $b);
        $addr = BinaryColumn::bind($protector->encryptSecret('physical_address', '1 Test Street, '.$gov));
        $phone = $phoneOf($branchPhoneBase++);
        $branchBatch[] = $row([
            $branchId, $orgId, 'Branch '.$b, $addr, 1, 'EG', $phone['cipher'], 1,
            'active', 1, $stamp, $stamp, sprintf('SRID=4326;POINT(%F %F)', $lng, $lat),
        ]);
    }
    $membershipBatch[] = $row([
        $uuid(), $orgId, $pharmacyOwnerIds[$i], null, 'owner', 'active',
        $stamp, $stamp, null, null, null, 1, $stamp, $stamp,
    ]);
    $opsForOrg = intdiv($pharmacyOperators, $counts['pharmacy_orgs']) + ($i < ($pharmacyOperators % $counts['pharmacy_orgs']) ? 1 : 0);
    for ($o = 0; $o < $opsForOrg; $o++) {
        $opId = $pharmacyOperatorIds[$operatorCursor++];
        $branchId = $orgBranchIds[$o % count($orgBranchIds)];
        $membershipBatch[] = $row([
            $uuid(), $orgId, $opId, $branchId, 'branch_operator', 'active',
            $stamp, $stamp, null, $pharmacyOwnerIds[$i], null, 1, $stamp, $stamp,
        ]);
    }
    if (count($orgBatch) >= 200) {
        $copy('pharmacy_organizations', $orgColumns, $orgBatch);
        $copy('pharmacy_branches', $branchColumns, $branchBatch);
        $copy('pharmacy_memberships', $membershipColumns, $membershipBatch);
        $orgBatch = [];
        $branchBatch = [];
        $membershipBatch = [];
    }
}
$copy('pharmacy_organizations', $orgColumns, $orgBatch);
$copy('pharmacy_branches', $branchColumns, $branchBatch);
$copy('pharmacy_memberships', $membershipColumns, $membershipBatch);

fwrite(STDOUT, "clinic locations + memberships\n");
$locationColumns = [
    'id', 'doctor_id', 'public_name', 'address_ciphertext', 'address_key_version',
    'country_code', 'status', 'version', 'created_at', 'updated_at', 'geography_point',
];
$staffProfileColumns = ['id', 'user_id', 'created_at', 'updated_at'];
$staffMembershipColumns = [
    'id', 'staff_profile_id', 'location_id', 'role', 'status', 'invited_at',
    'accepted_at', 'revoked_at', 'inviter_user_id', 'revoker_user_id', 'version',
    'created_at', 'updated_at',
];
$locationBatch = [];
$staffProfileBatch = [];
$staffMembershipBatch = [];
$perApproved = intdiv($counts['clinic_locations'], $approvedDoctors);
$extraLoc = $counts['clinic_locations'] % $approvedDoctors;
$locIndex = 0;
$secretaryCursor = 0;

for ($d = 0; $d < $approvedDoctors; $d++) {
    $nLoc = $perApproved + ($d < $extraLoc ? 1 : 0);
    for ($l = 0; $l < $nLoc; $l++) {
        $locId = $uuid();
        $locationIds[] = $locId;
        $locationDoctorIds[] = $d;
        [$gov, $lat, $lng] = $pickPoint($locIndex);
        $addr = BinaryColumn::bind($protector->encryptSecret('physical_address', 'Clinic '.$locIndex.', '.$gov));
        $locationBatch[] = $row([
            $locId, $doctorProfileIds[$d], 'Clinic '.$locIndex, $addr, 1, 'EG',
            'active', 1, $stamp, $stamp, sprintf('SRID=4326;POINT(%F %F)', $lng, $lat),
        ]);
        $locIndex++;
    }
    if (count($locationBatch) >= 500) {
        $copy('clinic_locations', $locationColumns, $locationBatch);
        $locationBatch = [];
    }
}
$copy('clinic_locations', $locationColumns, $locationBatch);

$membershipsPerLocation = intdiv($counts['clinic_memberships'], $counts['clinic_locations']);
$extraMem = $counts['clinic_memberships'] % $counts['clinic_locations'];
for ($l = 0; $l < $counts['clinic_locations']; $l++) {
    $nMem = $membershipsPerLocation + ($l < $extraMem ? 1 : 0);
    $doctorIndex = $locationDoctorIds[$l];
    for ($m = 0; $m < $nMem; $m++) {
        $secretaryId = $secretaryIds[$secretaryCursor++];
        $staffProfileId = $uuid();
        $staffProfileBatch[] = $row([$staffProfileId, $secretaryId, $stamp, $stamp]);
        $staffMembershipBatch[] = $row([
            $uuid(), $staffProfileId, $locationIds[$l], 'secretary', 'active',
            $stamp, $stamp, null, $doctorUserIds[$doctorIndex], null, 1, $stamp, $stamp,
        ]);
    }
    if (count($staffProfileBatch) >= 1000) {
        $copy('clinic_staff_profiles', $staffProfileColumns, $staffProfileBatch);
        $copy('clinic_staff_memberships', $staffMembershipColumns, $staffMembershipBatch);
        $staffProfileBatch = [];
        $staffMembershipBatch = [];
    }
}
$copy('clinic_staff_profiles', $staffProfileColumns, $staffProfileBatch);
$copy('clinic_staff_memberships', $staffMembershipColumns, $staffMembershipBatch);

fwrite(STDOUT, "verification cases\n");
$caseColumns = [
    'id', 'applicant_type', 'applicant_id', 'case_type', 'status', 'submitted_at',
    'assigned_reviewer_id', 'decided_at', 'version', 'created_at', 'updated_at',
];
$caseBatch = [];
for ($i = $approvedDoctors; $i < $approvedDoctors + $pendingDoctors; $i++) {
    $caseBatch[] = $row([
        $uuid(), 'doctor', $doctorProfileIds[$i], 'doctor_verification',
        'pending_review', $stamp, null, null, 1, $stamp, $stamp,
    ]);
}
$copy('verification_cases', $caseColumns, $caseBatch);

fwrite(STDOUT, "load-test device sessions\n");
$deviceColumns = [
    'id', 'user_id', 'platform', 'device_label', 'token_hash', 'refresh_token_hash',
    'previous_refresh_token_hash', 'refresh_family_id', 'refresh_generation',
    'credential_version', 'last_seen_at', 'expires_at', 'refresh_expires_at',
    'revoked_at', 'revoked_reason', 'push_token_ciphertext', 'created_ip_prefix',
    'created_at', 'updated_at',
];
$sessionColumns = [
    'id', 'user_id', 'device_id', 'session_kind', 'session_hash', 'assurance_level',
    'csrf_established', 'idle_expires_at', 'absolute_expires_at', 'credential_version',
    'revoked_at', 'revoked_reason', 'last_seen_at', 'created_at', 'updated_at',
];

$expires = $now->modify('+12 hours')->format('Y-m-d H:i:s.uP');
$refreshExpires = $now->modify('+30 days')->format('Y-m-d H:i:s.uP');

$issue = static function (string $userId, string $platform, string $label, string $assurance) use (
    $credentials, $uuid, $stamp, $expires, $refreshExpires, $row
): array {
    $access = $credentials->randomToken();
    $refresh = $credentials->randomToken();
    $accessHash = BinaryColumn::bind($credentials->hashToken($access));
    $refreshHash = BinaryColumn::bind($credentials->hashToken($refresh));
    $deviceId = $uuid();
    $sessionId = $uuid();
    $familyId = $uuid();

    return [
        'token' => $access,
        'user_id' => $userId,
        'device' => $row([
            $deviceId, $userId, $platform, $label, $accessHash, $refreshHash, null,
            $familyId, 1, 1, $stamp, $expires, $refreshExpires, null, null, null, null,
            $stamp, $stamp,
        ]),
        'session' => $row([
            $sessionId, $userId, $deviceId, 'device', $accessHash, $assurance,
            false, null, $refreshExpires, 1, null, null, $stamp, $stamp, $stamp,
        ]),
    ];
};

$actors = [
    'specialty_id' => $specialtyId,
    'patients' => [],
    'doctors_approved' => [],
    'doctors_pending' => [],
    'doctors_onboard' => [],
    'pharmacies' => [],
    'admins' => [],
];
$deviceBatch = [];
$sessionBatch = [];

$take = static function (array &$bucket, array $issued, array $extra) use (&$deviceBatch, &$sessionBatch): void {
    $deviceBatch[] = $issued['device'];
    $sessionBatch[] = $issued['session'];
    $bucket[] = array_merge(['token' => $issued['token'], 'user_id' => $issued['user_id']], $extra);
};

for ($i = 0; $i < $counts['actor_patients']; $i++) {
    $issued = $issue($patientUserIds[$i], 'android', 'k6-patient-'.$i, 'aal1_password');
    $take($actors['patients'], $issued, ['version' => 1]);
}

$locationsByDoctor = [];
foreach ($locationDoctorIds as $idx => $doctorIndex) {
    $locationsByDoctor[$doctorIndex][] = $locationIds[$idx];
}

for ($i = 0; $i < $counts['actor_doctors_approved']; $i++) {
    $issued = $issue($doctorUserIds[$i], 'linux', 'k6-doctor-'.$i, 'aal2_totp');
    $locId = $locationsByDoctor[$i][0] ?? $locationIds[0];
    $take($actors['doctors_approved'], $issued, [
        'doctor_id' => $doctorProfileIds[$i],
        'location_id' => $locId,
        'location_version' => 1,
        'national_id' => $doctorNids[$i],
        'specialty_id' => $specialtyId,
    ]);
}

$pendingStart = $approvedDoctors;
for ($i = 0; $i < $counts['actor_doctors_pending']; $i++) {
    $idx = $pendingStart + $i;
    $issued = $issue($doctorUserIds[$idx], 'linux', 'k6-pending-'.$i, 'aal2_totp');
    $take($actors['doctors_pending'], $issued, [
        'doctor_id' => $doctorProfileIds[$idx],
        'national_id' => $doctorNids[$idx],
    ]);
}

for ($i = 0; $i < $counts['actor_doctors_onboard']; $i++) {
    $idx = $onboardDoctorIndexes[$i];
    $issued = $issue($doctorUserIds[$idx], 'linux', 'k6-onboard-'.$i, 'aal2_totp');
    $take($actors['doctors_onboard'], $issued, [
        'national_id' => $doctorNids[$idx],
        'specialty_id' => $specialtyId,
    ]);
}

for ($i = 0; $i < $counts['actor_pharmacies']; $i++) {
    $issued = $issue($pharmacyOwnerIds[$i], 'linux', 'k6-pharmacy-'.$i, 'aal2_totp');
    $firstBranchIndex = 0;
    for ($o = 0; $o < $i; $o++) {
        $firstBranchIndex += $branchesPerOrg + ($o < $extraBranches ? 1 : 0);
    }
    $take($actors['pharmacies'], $issued, [
        'organization_id' => $pharmacyOrgIds[$i],
        'branch_id' => $pharmacyBranchIds[$firstBranchIndex],
    ]);
}

for ($i = 0; $i < min($counts['actor_admins'], count($adminIds)); $i++) {
    $issued = $issue($adminIds[$i], 'linux', 'k6-admin-'.$i, 'aal2_totp');
    $take($actors['admins'], $issued, []);
}

$copy('user_devices', $deviceColumns, $deviceBatch);
$copy('auth_sessions', $sessionColumns, $sessionBatch);

DB::statement('ANALYZE');
DB::statement('SET synchronous_commit = on');

$directory = dirname($out);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

file_put_contents($out, json_encode($actors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
chmod($out, 0600);

fwrite(STDOUT, sprintf(
    "seeded patients=%d doctors=%d orgs=%d branches=%d locations=%d clinic_memberships=%d pharmacy_memberships=%d actors=%s\n",
    $counts['patients'],
    $counts['doctors'],
    $counts['pharmacy_orgs'],
    $counts['pharmacy_branches'],
    $counts['clinic_locations'],
    $counts['clinic_memberships'],
    $counts['pharmacy_memberships'],
    $out,
));
