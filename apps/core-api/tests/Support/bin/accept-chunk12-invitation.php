#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Accept a clinic secretary invitation through the existing Core service.
 * This is not a Doctor UI action.
 */

use Illuminate\Contracts\Console\Kernel;
use Modules\Access\Support\Capabilities;
use Modules\Clinics\Services\AcceptClinicStaffInvitation;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Support\Identifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = getenv('CLINIC_PRACTICE_SECRETARY_USER_ID');
$invitationId = getenv('CLINIC_PRACTICE_INVITATION_ID');
if (! is_string($userId) || $userId === '' || ! is_string($invitationId) || $invitationId === '') {
    fwrite(STDERR, "CLINIC_PRACTICE_SECRETARY_USER_ID and CLINIC_PRACTICE_INVITATION_ID are required.\n");
    exit(1);
}

$actor = new ActorContext(
    Identifier::fromTrusted($userId),
    AccountType::Secretary,
    AccountStatus::Active,
    LanguagePreference::English,
    AssuranceLevel::Aal1Password,
    1,
    null,
    Identifier::fromTrusted($userId),
    [],
    Capabilities::AUTHENTICATED_SELF,
);

$accepted = $app->make(AcceptClinicStaffInvitation::class)->handle(
    $actor,
    Identifier::fromTrusted($invitationId),
);

fwrite(STDOUT, json_encode([
    'membership_id' => $accepted->membershipId,
    'status' => 'accepted',
], JSON_THROW_ON_ERROR)."\n");
