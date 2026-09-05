<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Identity\Contracts\PatientSubjectPrivacy;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Enums\SubjectHoldingAction;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\Phase01SubjectHoldings;
use Modules\Identity\Support\SubjectDataExport;
use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Services\Privacy\DiscardSubjectTransientCopies;
use Modules\Platform\Support\Identifier;

/**
 * Phase-01 machine-readable subject enumeration.
 *
 * Reuses {@see Phase01SubjectHoldings}. Never includes password hashes,
 * bearer tokens, OTP secrets, TOTP seeds, recovery codes, encryption keys,
 * or HMAC material.
 */
final class ExportSubjectDataService
{
    public function __construct(
        private readonly UserDirectory $identities,
        private readonly AuthDirectory $auth,
        private readonly GrantStore $grants,
        private readonly DiscardSubjectTransientCopies $transients,
        private readonly Authorize $authorizer,
        private readonly RecordPrivilegedFailure $privilegedFailures,
        private readonly PatientSubjectPrivacy $patientPrivacy,
    ) {}

    public function handle(ActorContext $initiator, Identifier $userId): SubjectDataExport
    {
        $decision = $this->authorizer->decide($initiator, Capabilities::IDENTITY_EXPORT, 'user', $userId);
        if (! $decision->allowed) {
            $this->privilegedFailures->authorizationDenied(
                $initiator->userId,
                $initiator->accountType->value,
                $initiator->assuranceLevel->value,
                Capabilities::IDENTITY_EXPORT,
                $decision->reasonCode,
                $userId,
                'user',
            );
            throw new AuthorizationDenied;
        }

        $user = $this->identities->findById($userId);
        if ($user === null) {
            throw new AuthorizationDenied;
        }

        $hmac = $this->identities->phoneLookupHmac($userId) ?? '';
        $identifiers = $this->auth->subjectAuthIdentifiers($userId, $hmac);
        $authCounts = $this->auth->countSubjectAuthHoldings($userId, $hmac);
        $transients = $this->transients->snapshot($userId->value, $identifiers['session_ids']);

        $counts = [
            'users' => 1,
            'users.phone_lookup_hmac' => 1,
            'identity_national_ids' => $this->identities->countNationalIds($userId),
            'identity_profile_links' => $this->identities->countProfileLinks($userId),
            'user_devices' => $authCounts['user_devices'],
            'auth_sessions' => $authCounts['auth_sessions'],
            'auth_refresh_consumptions' => $authCounts['auth_refresh_consumptions'],
            'otp_requests' => $authCounts['otp_requests'],
            'mfa_factors' => $authCounts['mfa_factors'],
            'mfa_recovery_codes' => $authCounts['mfa_recovery_codes'],
            'mfa_challenges' => $authCounts['mfa_challenges'],
            'recovery_requests' => $authCounts['recovery_requests'],
            'contextual_access_grants' => $this->grants->countSubjectGrants($userId),
            'notifications' => $transients['notifications'],
            'outbox_events' => $transients['pending_or_failed_outbox'],
            'sessions' => $transients['laravel_sessions'],
            'auth_rate_limit_keys' => null,
            'idempotency_keys' => null,
            'jobs' => null,
            'failed_jobs' => null,
            'job_batches' => null,
            'cache' => null,
            'cache_locks' => null,
            'audit_events' => null,
            'audit_checkpoints' => null,
            'firebase_fcm' => null,
            'backup_artefacts' => null,
        ];

        $counts = array_merge($counts, $this->patientPrivacy->exportCounts($userId));

        $holdings = array_map(
            static function (SubjectHoldingPlan $plan) use ($counts): array {
                $count = $counts[$plan->holding] ?? null;

                return [
                    'holding' => $plan->holding,
                    'action' => $plan->action->value,
                    'notes' => $plan->notes,
                    'count' => in_array($plan->action, [
                        SubjectHoldingAction::NotSubjectLinked,
                        SubjectHoldingAction::PreserveSecurityAudit,
                    ], true) ? null : $count,
                ];
            },
            [...Phase01SubjectHoldings::plan(), ...$this->patientPrivacy->holdings()],
        );

        return new SubjectDataExport(
            $userId->value,
            $user->status->value,
            $user->accountType->value,
            $holdings,
            [
                'lawful_basis' => 'OPEN_LEGAL_DECISION',
                'statutory_retention' => 'OPEN_LEGAL_DECISION',
                'audit_erasure_policy' => 'OPEN_LEGAL_DECISION',
                'backup_retention' => 'OPEN_LEGAL_DECISION',
            ],
            [
                'offline_client_vault_wipe',
                'fcm_token_invalidation_at_provider',
                'immutable_backup_lifecycle',
            ],
        );
    }
}
