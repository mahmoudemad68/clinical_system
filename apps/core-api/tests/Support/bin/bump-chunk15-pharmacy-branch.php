#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Second authorized owner-side client for Chunk 15 VERSION_CONFLICT E2E.
 * Updates a pharmacy branch through Core UpdatePharmacyBranch. This is not
 * an Electron renderer action.
 */

use Illuminate\Contracts\Console\Kernel;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\UpdatePharmacyBranch;
use Modules\Platform\Support\Identifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = getenv('CLINIC_PRACTICE_OWNER_USER_ID');
$organizationId = getenv('CLINIC_PRACTICE_ORGANIZATION_ID');
$branchId = getenv('CLINIC_PRACTICE_BRANCH_ID');
$expectedVersion = getenv('CLINIC_PRACTICE_EXPECTED_VERSION');
$publicName = getenv('CLINIC_PRACTICE_PUBLIC_NAME');
if (
    ! is_string($userId) || $userId === ''
    || ! is_string($organizationId) || $organizationId === ''
    || ! is_string($branchId) || $branchId === ''
    || ! is_string($expectedVersion) || $expectedVersion === ''
    || ! is_string($publicName) || $publicName === ''
) {
    fwrite(STDERR, "Owner, organization, branch, expected version, and public name are required.\n");
    exit(1);
}

$actor = new ActorContext(
    Identifier::fromTrusted($userId),
    AccountType::Pharmacy,
    AccountStatus::Active,
    LanguagePreference::English,
    AssuranceLevel::Aal2Totp,
    1,
    null,
    Identifier::fromTrusted($userId),
    [],
    [Capabilities::PHARMACIES_BRANCH_WRITE],
);

$updated = $app->make(UpdatePharmacyBranch::class)->handle(
    $actor,
    Identifier::fromTrusted($organizationId),
    Identifier::fromTrusted($branchId),
    [
        'expected_version' => (int) $expectedVersion,
        'public_name' => $publicName,
    ],
);

fwrite(STDOUT, json_encode([
    'branch_id' => $updated->branchId,
    'version' => $updated->version,
    'public_name' => $updated->publicName,
], JSON_THROW_ON_ERROR)."\n");
