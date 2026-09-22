#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Accept a pending Pharmacy branch_operator invitation through the existing
 * Core service. This is not a Pharmacy Electron renderer action.
 *
 * Looks up the pending invitation bound to CLINIC_PRACTICE_OPERATOR_USER_ID.
 */

use Illuminate\Contracts\Console\Kernel;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\AcceptPharmacyStaffInvitation;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Platform\Support\Identifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = getenv('CLINIC_PRACTICE_OPERATOR_USER_ID');
if (! is_string($userId) || $userId === '') {
    fwrite(STDERR, "CLINIC_PRACTICE_OPERATOR_USER_ID is required.\n");
    exit(1);
}

$recipients = $app->make(InvitationRecipientService::class);
$store = $app->make(PostgresPharmacyOrganizationStore::class);
$actorId = Identifier::fromTrusted($userId);
$invitations = $store->listPendingInvitationsByHmacs($recipients->subjectPhoneLookupHmacs($actorId), false);
if ($invitations === []) {
    fwrite(STDERR, "No pending pharmacy invitation matched the intended operator.\n");
    exit(1);
}

$invitation = $invitations[0];
$actor = new ActorContext(
    $actorId,
    AccountType::Pharmacy,
    AccountStatus::Active,
    LanguagePreference::English,
    AssuranceLevel::Aal1Password,
    1,
    null,
    Identifier::fromTrusted($userId),
    [],
    [Capabilities::PHARMACIES_STAFF_ACCEPT],
);

$accepted = $app->make(AcceptPharmacyStaffInvitation::class)->handle($actor, $invitation->id);

fwrite(STDOUT, json_encode([
    'membership_id' => $accepted->membershipId,
    'status' => 'accepted',
], JSON_THROW_ON_ERROR)."\n");
