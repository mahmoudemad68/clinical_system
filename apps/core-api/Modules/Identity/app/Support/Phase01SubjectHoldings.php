<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Identity\Enums\SubjectHoldingAction;

/**
 * Explicit Phase-01 subject-linked holdings enumerator.
 *
 * Shared by erasure and export. Does not include Phase 02+ clinical tables.
 * Legal retention and lawful basis remain OPEN_LEGAL_DECISION.
 */
final class Phase01SubjectHoldings
{
    /**
     * @return list<SubjectHoldingPlan>
     */
    public static function plan(): array
    {
        return [
            new SubjectHoldingPlan(
                'users',
                SubjectHoldingAction::IrreversibleTombstone,
                'Retain the row for referential integrity. Overwrite name, phone ciphertext, phone lookup HMAC, and credential digest with non-PII random tombstones. Status closed. Direct identifiers are not recoverable from active identity state. Not a fake phone or National ID.',
            ),
            new SubjectHoldingPlan(
                'users.phone_lookup_hmac',
                SubjectHoldingAction::HmacLookupTombstone,
                'Replace the lookup HMAC with random bytes so the previous phone HMAC no longer resolves this row. Closed status is already excluded from findByPhoneHmac.',
            ),
            new SubjectHoldingPlan(
                'identity_national_ids',
                SubjectHoldingAction::Delete,
                'Delete encrypted National ID and lookup HMAC so the identifier cannot resolve to the erased subject.',
            ),
            new SubjectHoldingPlan(
                'identity_profile_links',
                SubjectHoldingAction::Delete,
                'Phase-01 identity-to-profile bindings only. No Phase 02+ clinical profiles.',
            ),
            new SubjectHoldingPlan(
                'patient_profiles',
                SubjectHoldingAction::IrreversibleTombstone,
                'Unlink user_id, tombstone National ID ciphertext/HMAC and name ciphertext, set status archived. Unlinked walk-in profiles are not selected by user_id. Not a clinical record.',
            ),
            new SubjectHoldingPlan(
                'patient_demographic_revisions',
                SubjectHoldingAction::PreserveSecurityAudit,
                'Append-only demographic field history. Name ciphertext in historical revision rows is a documented residual and is not rewritten.',
            ),
            new SubjectHoldingPlan(
                'user_devices',
                SubjectHoldingAction::Delete,
                'Bearer, refresh, previous-refresh, push_token_ciphertext, and refresh-replay material are removed with the row. Remote FCM copies are OPERATIONAL_FOLLOW_THROUGH.',
            ),
            new SubjectHoldingPlan(
                'auth_sessions',
                SubjectHoldingAction::Delete,
                'Server auth_sessions rows. Distinct from Laravel sessions.',
            ),
            new SubjectHoldingPlan(
                'auth_refresh_consumptions',
                SubjectHoldingAction::Delete,
                'Consumed refresh hashes for the subject device families.',
            ),
            new SubjectHoldingPlan(
                'otp_requests',
                SubjectHoldingAction::Delete,
                'NULL code_ciphertext and destination_ciphertext, then DELETE rows matching the captured phone HMAC.',
            ),
            new SubjectHoldingPlan(
                'mfa_factors',
                SubjectHoldingAction::Delete,
                'TOTP secret_ciphertext is removed by deleting the row.',
            ),
            new SubjectHoldingPlan(
                'mfa_recovery_codes',
                SubjectHoldingAction::Delete,
                'Recovery-code hashes are removed by deleting the row.',
            ),
            new SubjectHoldingPlan(
                'mfa_challenges',
                SubjectHoldingAction::Delete,
                'Open MFA challenges for the subject.',
            ),
            new SubjectHoldingPlan(
                'recovery_requests',
                SubjectHoldingAction::Delete,
                'Blank the pending password digest, then DELETE cooling-off, manual-review, and terminal rows.',
            ),
            new SubjectHoldingPlan(
                'contextual_access_grants',
                SubjectHoldingAction::Delete,
                'DELETE where actor_user_id or resource_id is the subject. issued_by_id alone is not a delete predicate.',
            ),
            new SubjectHoldingPlan(
                'idempotency_keys',
                SubjectHoldingAction::NotSubjectLinked,
                'Hashed composite key; no user_id column. Expired rows are removed by platform:prune. ENGINEERING_DEFAULT, not statutory.',
            ),
            new SubjectHoldingPlan(
                'notifications',
                SubjectHoldingAction::Delete,
                'Laravel inbox rows for notifiable_id = subject.',
            ),
            new SubjectHoldingPlan(
                'outbox_events',
                SubjectHoldingAction::Delete,
                'PENDING and FAILED rows for the User aggregate and the subject AuthSession aggregates. PROCESSED, DEAD_LETTER, and CLAIMED rows are not rewritten; payloads are identifiers only. Legal duration OPEN_LEGAL_DECISION.',
            ),
            new SubjectHoldingPlan(
                'sessions',
                SubjectHoldingAction::Delete,
                'Laravel framework sessions rows for this user_id. Distinct from auth_sessions. Offline client vaults are OPERATIONAL_FOLLOW_THROUGH.',
            ),
            new SubjectHoldingPlan(
                'auth_rate_limit_keys',
                SubjectHoldingAction::Delete,
                'Identifiable subject, refresh-family, MFA-challenge, and OTP-challenge limiter keys. Shared IP keys are not cleared.',
            ),
            new SubjectHoldingPlan(
                'jobs',
                SubjectHoldingAction::NotSubjectLinked,
                'Laravel queue lifecycle deletes successful jobs. phpunit uses sync. Not a subject-erasure path.',
            ),
            new SubjectHoldingPlan(
                'failed_jobs',
                SubjectHoldingAction::NotSubjectLinked,
                'Framework failed-job log. Engineering prune via scheduled queue:prune-failed. Payloads may contain identifiers; no clinic redaction job. Legal retention OPEN_LEGAL_DECISION.',
            ),
            new SubjectHoldingPlan(
                'job_batches',
                SubjectHoldingAction::NotSubjectLinked,
                'Laravel batch metadata. No clinic subject linkage column.',
            ),
            new SubjectHoldingPlan(
                'cache',
                SubjectHoldingAction::NotSubjectLinked,
                'Framework cache lottery or expiry. Subject rate-limit keys are cleared explicitly.',
            ),
            new SubjectHoldingPlan(
                'cache_locks',
                SubjectHoldingAction::NotSubjectLinked,
                'Framework lock lifecycle. Not a subject identity store.',
            ),
            new SubjectHoldingPlan(
                'audit_events',
                SubjectHoldingAction::PreserveSecurityAudit,
                'Append-only. Erasure appends identity.subject_erased. Existing object_id references are not updated or deleted. Whether audit may legally be erased is OPEN_LEGAL_DECISION / EXTERNAL_HUMAN.',
            ),
            new SubjectHoldingPlan(
                'audit_checkpoints',
                SubjectHoldingAction::PreserveSecurityAudit,
                'External chain-tip files: format, sequence, row_hash, timestamp, key_id, signature. No direct personal data. Not rewritten by subject erasure.',
            ),
            new SubjectHoldingPlan(
                'firebase_fcm',
                SubjectHoldingAction::NotSubjectLinked,
                'Third-party processor. Server nulls local push_token_ciphertext on device delete. Remote FCM token copies are OPERATIONAL_FOLLOW_THROUGH. Current send: device token, generic lock-screen Clinic / You have a new notice, type, and scalar data keys.',
            ),
            new SubjectHoldingPlan(
                'backup_artefacts',
                SubjectHoldingAction::PreserveSecurityAudit,
                'Subject erasure does not mutate immutable historical backups. Legal backup retention OPEN_LEGAL_DECISION. Production expiry/rotation OPERATIONAL_FOLLOW_THROUGH.',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function holdingNames(): array
    {
        return array_map(
            static fn (SubjectHoldingPlan $plan): string => $plan->holding,
            self::plan(),
        );
    }
}
