#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot setup for G-01-18. Boots Laravel to insert two synthetic users
 * into PostgreSQL. Authenticated HTTP itself runs later against live Octane.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\UserAccount;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = getenv('CLINIC_OCTANE_ISOLATION_IDENTITIES');
if (! is_string($out) || $out === '') {
    fwrite(STDERR, "CLINIC_OCTANE_ISOLATION_IDENTITIES is required.\n");
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
$now = $clock->now();

$phoneA = $protector->phone('01900000001');
$phoneB = $protector->phone('01900000002');
$idA = $ids->next();
$idB = $ids->next();
$hash = $hasher->hash($password);

$users->insertUser(
    new UserAccount(
        $idA,
        'Synthetic Isolation Alpha',
        AccountType::Patient,
        AccountStatus::Active,
        LanguagePreference::English,
        $hash,
        1,
        true,
        false,
    ),
    $protector->encryptPhone($phoneA),
    $protector->phoneHmac($phoneA),
    $protector->encryptionVersion(),
    $protector->hmacVersion(),
    $now,
);

$users->insertUser(
    new UserAccount(
        $idB,
        'Synthetic Isolation Beta',
        AccountType::Doctor,
        AccountStatus::Active,
        LanguagePreference::Arabic,
        $hash,
        1,
        true,
        false,
    ),
    $protector->encryptPhone($phoneB),
    $protector->phoneHmac($phoneB),
    $protector->encryptionVersion(),
    $protector->hmacVersion(),
    $now,
);

$secret = $totp->generateSecret();
$auth->insertTotpFactor([
    'id' => $ids->next()->value,
    'user_id' => $idB->value,
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

$grantId = $ids->next();
$resource = $ids->next();
$context = $ids->next();
DB::table('contextual_access_grants')->insert([
    'id' => $grantId->value,
    'actor_user_id' => $idA->value,
    'capability' => Capabilities::CONTEXT_DELEGATE,
    'resource_type' => 'auth_session',
    'resource_id' => $resource->value,
    'context_type' => 'self',
    'context_id' => $context->value,
    'valid_from' => null,
    'valid_until' => null,
    'revoked_at' => null,
    'reason_code' => 'octane_isolation',
    'issued_by_type' => 'system',
    'issued_by_id' => $idA->value,
    'version' => 1,
    'created_at' => $now->format('Y-m-d H:i:s.uP'),
]);

$capsA = array_values(array_unique([...Capabilities::AUTHENTICATED_SELF, Capabilities::CONTEXT_DELEGATE]));
sort($capsA);
$capsB = Capabilities::forActor(AccountType::Doctor->value, true);
sort($capsB);

$payload = [
    'password' => $password,
    'a' => [
        'label' => 'A',
        'phone' => '01900000001',
        'user_id' => $idA->value,
        'account_type' => AccountType::Patient->value,
        'language' => LanguagePreference::English->value,
        'status' => AccountStatus::Active->value,
        'assurance_level' => 'aal1_password',
        'capabilities' => $capsA,
        'unique_capability' => Capabilities::CONTEXT_DELEGATE,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'octane-iso-alpha',
    ],
    'b' => [
        'label' => 'B',
        'phone' => '01900000002',
        'user_id' => $idB->value,
        'account_type' => AccountType::Doctor->value,
        'language' => LanguagePreference::Arabic->value,
        'status' => AccountStatus::Active->value,
        'assurance_level' => 'aal2_totp',
        'capabilities' => $capsB,
        'unique_capability' => null,
        'totp_secret' => $secret,
        'client_class' => 'doctor_desktop',
        'platform' => 'linux',
        'device_label' => 'octane-iso-beta',
    ],
];

$directory = dirname($out);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
chmod($out, 0600);

fwrite(STDOUT, "seeded {$idA->value} and {$idB->value}\n");
