<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

use JsonException;
use RuntimeException;

/**
 * Isolated in-memory mutations of the Profile-Claim Policy v1 artifact and evidence.
 */
final class Phase02ProfileClaimPolicyMutations
{
    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function copy(array $artifact): array
    {
        try {
            $copy = json_decode(json_encode($artifact, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to copy profile-claim artifact.', 0, $exception);
        }

        if (! is_array($copy)) {
            throw new RuntimeException('Failed to copy profile-claim artifact.');
        }

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @return list<array{id: string, expected_issue: string, artifact: array<string, mixed>, evidence: string}>
     */
    public static function isolatedCases(array $baseline, string $evidence): array
    {
        return [
            self::case('credential_length_16_to_10', 'credential_length_not_16', self::credentialLength($baseline, 10), $evidence),
            self::case('crockford_alphabet_altered', 'crockford_alphabet_altered', self::crockfordAlphabet($baseline, '0123456789ABCDEFGHIJKLMNOPQRSTUV'), $evidence),
            self::case('credential_ttl_30_to_7', 'credential_ttl_not_30_days', self::credentialTtlDays($baseline, 7), $evidence),
            self::case('nid_otp_insufficient_removed', 'nid_plus_otp_insufficient_flag_removed', self::removeNidOtpInsufficient($baseline), $evidence),
            self::case('legacy_manual_review_only_removed', 'legacy_manual_review_only_removed', self::removeLegacyManualReviewOnly($baseline), $evidence),
            self::case('production_hard_off_removed', 'production_hard_off_requirement_removed', self::removeProductionHardOff($baseline), $evidence),
            self::case('feature_default_true', 'feature_default_not_false', self::featureDefaultTrue($baseline), $evidence),
            self::case('t46_top_level_closed', 't46_not_open', self::closeT46($baseline), $evidence),
            self::case('audit_005_top_level_closed', 'p02_audit_005_not_open', self::closeAudit005($baseline), $evidence),
            self::case('product_approval_approved', 'product_approval_not_pending_external', self::approveProductGovernance($baseline), $evidence),
            self::case('plaintext_prohibition_list_cleared', 'plaintext_prohibition_list_altered', self::removePlaintextProhibitionList($baseline), $evidence),
            self::case('otp_recency_10_to_30', 'otp_recency_not_10_minutes', self::otpRecencyMinutes($baseline, 30), $evidence),
            self::case('hourly_attempt_limit_changed', 'numeric_table_credential_failures_per_account_nid_hmac_per_hour_drifted', self::hourlyAttemptLimit($baseline, 99), $evidence),
            self::case('pc002_blocker_removed', 'PC-002_blocks_production_enablement_mismatch', self::decisionBlocksProduction($baseline, 'PC-002', false), $evidence),
            self::case('peppered_hash_to_plain_hash', 'credential_storage_not_peppered_hash_only', self::plainHashStorage($baseline), $evidence),
            self::case('already_bound_reclaimable', 'already_bound_self_reclaim_allowed', self::alreadyBoundReclaimable($baseline), $evidence),
            self::case('already_bound_reassignable', 'already_bound_automatic_reassignment_allowed', self::alreadyBoundReassignable($baseline), $evidence),
            self::case('credential_factor_replaced_by_dob', 'high_confidence_bundle_mismatch', self::replaceCredentialFactorWithDob($baseline), $evidence),
            self::case('hidden_denial_profile_already_linked', 'hidden_denial_is_not_not_found', self::hiddenDenialProfileAlreadyLinked($baseline), $evidence),
            self::case('pc012_permits_national_id_not_found', 'pc012_permits_national_id_not_found', self::pc012PermitsNationalIdNotFound($baseline), $evidence),
            self::case('pc020_implementation_status_changed', 'PC-020_implementation_status_mismatch', self::decisionImplementationStatus($baseline, 'PC-020', 'NOT_IMPLEMENTED'), $evidence),
            self::case('approver_identity_added', 'approved_by_present', self::addApproverIdentity($baseline), $evidence),
            self::case('generation_sequential', 'credential_generation_not_csprng', self::sequentialGeneration($baseline), $evidence),
            self::case('encoding_not_crockford', 'credential_encoding_not_crockford_base32', self::encodingBase64($baseline), $evidence),
            self::case('excluded_characters_removed', 'credential_excluded_characters_altered', self::clearExcludedCharacters($baseline), $evidence),
            self::case('pc020_env_flag_alone_enables', 'pc020_allows_env_flag_alone_enablement', self::pc020EnvFlagAloneEnables($baseline), $evidence),
            self::case('client_must_not_learn_emptied', 'client_must_not_learn_empty', self::emptyClientMustNotLearn($baseline), $evidence),
            self::case('pc010_attempt_limit_text_wrong', 'PC-010_policy_decision_mismatch', self::pc010IncorrectAttemptLimit($baseline), $evidence),
            self::case('legacy_auto_generation_enabled', 'automatic_credential_generation_not_forbidden', self::enableLegacyAutoGeneration($baseline), $evidence),
            self::case('pc019_blocker_removed', 'PC-019_blocks_production_enablement_mismatch', self::decisionBlocksProduction($baseline, 'PC-019', false), $evidence),
            self::case('pc022_blocker_removed', 'PC-022_blocks_production_enablement_mismatch', self::decisionBlocksProduction($baseline, 'PC-022', false), $evidence),
            self::case('pc017_blocker_removed', 'PC-017_blocks_production_enablement_mismatch', self::decisionBlocksProduction($baseline, 'PC-017', false), $evidence),
            self::case('duplicate_pc_id', 'duplicate_pc_id', self::duplicatePc001($baseline), $evidence),
            self::case('missing_pc_022', 'missing_pc_id:PC-022', self::dropDecision($baseline, 'PC-022'), $evidence),
            self::case('extra_json_enablement_key', 'json_extra:json.enablement', self::extraEnablementKey($baseline), $evidence),
            self::case('evidence_final_t46_closed', 'evidence_final_status_t46_closed', $baseline, self::evidenceFinalStatus($evidence, 'T46', 'OPEN', 'CLOSED')),
            self::case('evidence_final_audit_005_closed', 'evidence_final_status_p02_audit_005_closed', $baseline, self::evidenceFinalStatus($evidence, 'P02-AUDIT-005', 'OPEN', 'CLOSED')),
            self::case('evidence_identity_audit_005_closed', 'evidence_identity_p02_audit_005_closed', $baseline, self::evidenceReplaceOnce($evidence, '| **P02-AUDIT-005** | **`OPEN`** |', '| **P02-AUDIT-005** | **`CLOSED`** |')),
            self::case('evidence_identity_t46_closed', 'evidence_identity_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, '| **T46** | **`OPEN`** |', '| **T46** | **`CLOSED`** |')),
            self::case('evidence_prose_audit_005_closed', 'evidence_prose_p02_audit_005_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: P02-AUDIT-005 is OPEN.', 'Current material state: P02-AUDIT-005 is CLOSED.')),
            self::case('evidence_prose_audit_005_wrapped_closed', 'evidence_prose_p02_audit_005_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: P02-AUDIT-005 is OPEN.', 'Current material state: **`P02-AUDIT-005`** is **`CLOSED`**.')),
            self::case('evidence_prose_audit_005_open_removed', 'evidence_prose_p02_audit_005_not_open', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: P02-AUDIT-005 is OPEN.', 'Current material state no longer assigns P02-AUDIT-005.')),
            self::case('evidence_prose_t46_mitigated', 'evidence_prose_t46_mitigated', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'Current material state: T46 is MITIGATED.')),
            self::case('evidence_controller_freeze_is_product_approval', 'evidence_controller_freeze_claimed_as_governance_approval', $baseline, self::evidenceReplaceOnce($evidence, 'A Controller freeze is not Product approval', 'A Controller freeze is Product approval')),
            self::case('evidence_product_approved', 'evidence_product_governance_approved', $baseline, self::evidenceIdentityApproval($evidence, 'Product approval')),
            self::case('evidence_security_approved', 'evidence_security_governance_approved', $baseline, self::evidenceIdentityApproval($evidence, 'Security approval')),
            self::case('evidence_privacy_approved', 'evidence_privacy_governance_approved', $baseline, self::evidenceIdentityApproval($evidence, 'Privacy approval')),
            self::case('evidence_support_approved', 'evidence_support_operations_governance_approved', $baseline, self::evidenceIdentityApproval($evidence, 'Support/Operations approval')),
            self::case('evidence_numeric_credential_length_10', 'evidence_numeric_claim_credential_length_mismatch', $baseline, self::evidenceReplaceOnce($evidence, '| Claim credential length | 16 Crockford Base32 characters |', '| Claim credential length | 10 Crockford Base32 characters |')),
            self::case('evidence_numeric_ttl_365', 'evidence_numeric_claim_credential_ttl_mismatch', $baseline, self::evidenceReplaceOnce($evidence, '| Claim credential TTL | 30 days |', '| Claim credential TTL | 365 days |')),
            self::case('evidence_numeric_otp_recency_30', 'evidence_numeric_profile_claim_otp_recency_at_attach_mismatch', $baseline, self::evidenceReplaceOnce($evidence, '| `profile_claim` OTP recency at attach | 10 minutes |', '| `profile_claim` OTP recency at attach | 30 minutes |')),
            self::case('evidence_pc019_blocker_false', 'evidence_decision_PC-019_blocker_mismatch', $baseline, self::evidenceDecisionBlocker($evidence, 'PC-019', 'yes', 'no')),
            self::case('evidence_pc022_blocker_false', 'evidence_decision_PC-022_blocker_mismatch', $baseline, self::evidenceDecisionBlocker($evidence, 'PC-022', 'yes', 'no')),
            self::case('evidence_prohibited_state_removed', 'evidence_prohibited_client_states_incomplete', $baseline, self::evidenceReplaceOnce($evidence, '`national_id_not_found`.', '.')),
            self::case('evidence_hidden_denial_linked', 'evidence_hidden_denial_is_not_not_found', $baseline, self::evidenceReplaceOnce($evidence, 'Hidden denial is `NOT_FOUND`.', 'Hidden denial is `profile_already_linked`.')),
            self::case('evidence_client_must_not_learn_item_removed', 'evidence_client_must_not_learn_incomplete', $baseline, self::evidenceReplaceOnce($evidence, "- nid_exists\n", '')),
            self::case('evidence_production_authorized', 'evidence_production_enablement_authorized', $baseline, self::evidenceReplaceOnce($evidence, '| Production enablement | `NOT_AUTHORIZED` |', '| Production enablement | `AUTHORIZED` |')),
            self::case('evidence_plain_hash', 'evidence_storage_not_peppered_hash_only', $baseline, self::evidenceReplaceOnce($evidence, '| Storage | peppered-hash-only |', '| Storage | plain-hash-only |')),
            self::case('evidence_legacy_auto_generation', 'evidence_legacy_automatic_credential_generation', $baseline, self::evidenceLegacyAutoGeneration($evidence)),
            self::case('audit_005_classification_transient', 'p02_audit_005_classification_not_durable', self::transientAudit005Classification($baseline), $evidence),
            self::case('evidence_t46_italic_identity_closed', 'evidence_prose_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'Current material state: _T46_ is CLOSED.')),
            self::case('evidence_t46_italic_state_closed', 'evidence_prose_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'Current material state: T46 is _CLOSED_.')),
            self::case('evidence_t46_has_been_closed', 'evidence_prose_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'Current material state: T46 has been closed.')),
            self::case('evidence_t46_was_closed', 'evidence_prose_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'Current material state: T46 was closed.')),
            self::case('evidence_t46_is_now_closed', 'evidence_prose_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'Current material state: T46 is now CLOSED.')),
            self::case('evidence_t46_unrelated_negation_then_closed', 'evidence_prose_t46_closed', $baseline, self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', 'This policy does not close another audit. T46 is CLOSED.')),
            self::case('evidence_t46_closed_before_open', 'evidence_final_status_t46_conflict', $baseline, self::evidenceInsertBefore($evidence, '`T46: OPEN`', "`T46: CLOSED`\n\n")),
            self::case('evidence_t46_closed_after_open', 'evidence_final_status_t46_conflict', $baseline, self::evidenceInsertAfter($evidence, '`T46: OPEN`', "\n\n`T46: CLOSED`")),
            self::case('evidence_audit_005_closed_before_open', 'evidence_final_status_p02_audit_005_conflict', $baseline, self::evidenceInsertBefore($evidence, '`P02-AUDIT-005: OPEN`', "`P02-AUDIT-005: CLOSED`\n\n")),
            self::case('evidence_audit_005_closed_after_open', 'evidence_final_status_p02_audit_005_conflict', $baseline, self::evidenceInsertAfter($evidence, '`P02-AUDIT-005: OPEN`', "\n\n`P02-AUDIT-005: CLOSED`")),
            self::case('evidence_production_enablement_is_authorized', 'evidence_production_enablement_authorized', $baseline, self::evidenceReplaceOnce($evidence, "Production enablement remains\n`NOT_AUTHORIZED`.", 'Production enablement is AUTHORIZED.')),
            self::case('evidence_production_enablement_has_been_authorized', 'evidence_production_enablement_authorized', $baseline, self::evidenceReplaceOnce($evidence, "Production enablement remains\n`NOT_AUTHORIZED`.", 'Production enablement has been authorized.')),
            self::case('evidence_layer_d_approved', 'evidence_layer_d_not_pending_external', $baseline, self::evidenceReplaceOnce($evidence, 'Product, Security, Privacy, and Support/Operations remain `PENDING_EXTERNAL`.', 'Product, Security, Privacy, and Support/Operations remain `APPROVED`.')),
            self::case('evidence_approver_email', 'evidence_approver_signoff_present', $baseline, self::evidenceInsertAfter($evidence, 'not governance evidence and do not approve this policy.', "\n\nApproved by security-lead@example.com")),
            self::case('evidence_hybrid_national_id_not_found', 'evidence_hybrid_permits_sensitive_client_state', $baseline, self::evidenceReplaceOnce($evidence, 'client contract (`manual_review_required`', 'client contract (`national_id_not_found`')),
            self::case('evidence_legacy_profile_exists', 'evidence_legacy_permits_sensitive_client_state', $baseline, self::evidenceReplaceOnce($evidence, 'The client still sees generic', 'Legacy clients receive `profile_exists`. The client still sees generic')),
            self::case('evidence_pc002_otp_removed', 'evidence_pc002_missing_otp', $baseline, self::evidenceReplaceOnce($evidence, "3. A fresh consumed `profile_claim` OTP exists on the claimant's\n   already-verified phone.\n", '')),
            self::case('evidence_pc002_dob_sufficient', 'evidence_pc002_dob_sufficient', $baseline, self::evidenceReplaceOnce($evidence, 'remain insufficient as independent', 'DOB alone is sufficient as independent')),
            self::case('evidence_already_bound_reclaim', 'evidence_already_bound_reclaim_allowed', $baseline, self::evidenceReplaceOnce($evidence, 'cannot be reclaimed', 'can be reclaimed')),
            self::case('evidence_pc020_kill_switch_removed', 'evidence_pc020_prerequisites_incomplete', $baseline, self::evidenceReplaceOnce($evidence, "- verified kill switch\n", '')),
            self::case('evidence_resolver_true_in_production', 'evidence_runtime_resolver_true_in_production', $baseline, self::evidenceReplaceOnce($evidence, 'the resolver returns false even if the env flag is', 'the resolver returns true in production even if the env flag is')),
            self::case('evidence_status_approved_production', 'evidence_status_approved_production_policy', $baseline, self::evidenceReplaceOnce($evidence, '| Status | **not** `APPROVED_PRODUCTION_POLICY` |', '| Status | `APPROVED_PRODUCTION_POLICY` |')),
            self::case('evidence_pc015_invented_status', 'evidence_pc015_invented_status', $baseline, self::evidenceReplaceOnce($evidence, 'No new status is invented.', 'Freeze uses invented status `claim_frozen`.')),
            self::case('evidence_pc002_rejected_removed', 'evidence_pc002_rejected_proof_incomplete', $baseline, self::evidenceReplaceOnce($evidence, "DOB, name, gender, blood type, NID-derived demographics, NID+OTP alone,\nand patient verification-document upload remain insufficient as independent\nadditional proof.\n", '')),
            self::case('evidence_pc002_nid_otp_sufficient', 'evidence_pc002_nid_otp_sufficient', $baseline, self::evidenceReplaceOnce($evidence, 'NID+OTP alone,', 'NID+OTP alone is sufficient. Ignored,')),
            self::case('evidence_pc020_governance_removed', 'evidence_pc020_prerequisites_incomplete', $baseline, self::evidenceReplaceOnce($evidence, "- external governance approvals\n", '')),
            self::case('evidence_pc020_independent_qa_removed', 'evidence_pc020_prerequisites_incomplete', $baseline, self::evidenceReplaceOnce($evidence, "- independent engineering QA\n", '')),
            self::case('evidence_client_must_not_learn_extra', 'evidence_client_must_not_learn_unexpected_member', $baseline, self::evidenceReplaceOnce($evidence, "- nid_exists\n", "- nid_exists\n- secret_exists\n")),
            self::case('evidence_client_must_not_learn_duplicate', 'evidence_client_must_not_learn_duplicate_member', $baseline, self::evidenceReplaceOnce($evidence, "- nid_exists\n", "- nid_exists\n- nid_exists\n")),
            self::case('evidence_prohibited_state_extra', 'evidence_prohibited_client_states_unexpected_member', $baseline, self::evidenceReplaceOnce($evidence, '`wrong_claim_code`,', '`wrong_claim_code`, `otp_invalid`,')),
            self::case('evidence_prohibited_state_duplicate', 'evidence_prohibited_client_states_duplicate_member', $baseline, self::evidenceReplaceOnce($evidence, '`wrong_claim_code`,', '`wrong_claim_code`, `wrong_claim_code`,')),
            self::case('evidence_plaintext_destination_removed', 'evidence_plaintext_destinations_incomplete', $baseline, self::evidenceReplaceOnce($evidence, ', telemetry.', '.')),
            self::case('evidence_plaintext_destination_extra', 'evidence_plaintext_destinations_unexpected_member', $baseline, self::evidenceReplaceOnce($evidence, 'telemetry.', 'telemetry, screenshots.')),
            self::case('evidence_plaintext_destination_duplicate', 'evidence_plaintext_destinations_duplicate_member', $baseline, self::evidenceReplaceOnce($evidence, 'persistence, logs,', 'persistence, persistence, logs,')),
            self::case('evidence_pc020_prerequisite_extra', 'evidence_pc020_prerequisites_unexpected_member', $baseline, self::evidenceReplaceOnce($evidence, "- tests complete\n", "- tests complete\n- secret dashboard\n")),
            self::case('evidence_pc020_prerequisite_duplicate', 'evidence_pc020_prerequisites_duplicate_member', $baseline, self::evidenceReplaceOnce($evidence, "- tests complete\n", "- tests complete\n- tests complete\n")),
        ];
    }

    /**
     * Semantic Markdown mutations that must emit evidence_markdown_drift.
     *
     * @return list<array{id: string, campaign: string, kind: string, evidence: string}>
     */
    public static function markdownSemanticOracleCases(string $evidence): array
    {
        return array_merge(
            self::previousMarkdownSurvivorCases($evidence),
            self::previousFreshAdversarialSurvivorCases($evidence),
            self::freshMarkdownAdversarialCases($evidence),
            self::markdownIndependenceCases($evidence),
        );
    }

    /**
     * Prior independent-QA Markdown survivors (35). Each is a durable-content
     * change that previously bypassed phrase/regex checks.
     *
     * @return list<array{id: string, campaign: string, kind: string, evidence: string}>
     */
    public static function previousMarkdownSurvivorCases(string $evidence): array
    {
        return [
            self::markdownCase('survivor_historically_open_t46_now_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Historically OPEN, T46 is now CLOSED.')),
            self::markdownCase('survivor_does_not_close_t46_already_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'does not close T46 because T46 is already CLOSED')),
            self::markdownCase('survivor_t46_is_not_open', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'T46 is not OPEN.')),
            self::markdownCase('survivor_closed_across_line_break', 'previous_survivor', self::evidenceAppendParagraph($evidence, "T46 is\nCLOSED.")),
            self::markdownCase('survivor_t46_nbsp_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, "T46 is\u{00A0}CLOSED.")),
            self::markdownCase('survivor_t46_emdash_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'T46 — CLOSED')),
            self::markdownCase('survivor_audit_005_emdash_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'P02-AUDIT-005 — CLOSED')),
            self::markdownCase('survivor_t46_equals_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'T46=CLOSED')),
            self::markdownCase('survivor_t46_status_colon_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'T46 status: CLOSED')),
            self::markdownCase('survivor_heading_t46_closed', 'previous_survivor', self::evidenceAppendParagraph($evidence, '### T46 CLOSED')),
            self::markdownCase('survivor_audit_005_resolved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'P02-AUDIT-005 is resolved.')),
            self::markdownCase('survivor_bare_production_enablement_authorized', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Production enablement: AUTHORIZED')),
            self::markdownCase('survivor_product_alone_approved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Product alone APPROVED')),
            self::markdownCase('survivor_security_alone_approved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Security alone APPROVED')),
            self::markdownCase('survivor_privacy_alone_approved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Privacy alone APPROVED')),
            self::markdownCase('survivor_support_alone_approved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Support/Operations alone APPROVED')),
            self::markdownCase('survivor_product_has_approved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Product has approved')),
            self::markdownCase('survivor_security_approved', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Security approved')),
            self::markdownCase('survivor_controller_freeze_counts_as_product_approval', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Controller freeze counts as Product approval')),
            self::markdownCase('survivor_signed_off', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'signed off')),
            self::markdownCase('survivor_sign_off_complete', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'sign-off complete')),
            self::markdownCase('survivor_ratified', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'ratified')),
            self::markdownCase('survivor_endorsed', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'endorsed')),
            self::markdownCase('survivor_governance_approvals_complete', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Governance approvals: complete')),
            self::markdownCase('survivor_approver_email_signoff', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'Approved by reviewer@example.com; sign-off recorded.')),
            self::markdownCase('survivor_document_upload_sufficient', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'document upload sufficient')),
            self::markdownCase('survivor_missing_bound_nid_high_confidence', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'missing bound NID accepted as high confidence')),
            self::markdownCase('survivor_nid_otp_fallback_sufficient', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'NID+OTP fallback sufficient')),
            self::markdownCase('survivor_dob_alone_sufficient', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'DOB alone sufficient')),
            self::markdownCase(
                'survivor_pc002_factor_removed',
                'previous_survivor',
                self::evidenceReplaceOnce(
                    $evidence,
                    "2. The claimant account has a **non-empty bound National-ID identity**\n   representation, and that bound identity matches the target profile\n   identity.\n",
                    '',
                ),
            ),
            self::markdownCase('survivor_credential_reusable_three_times', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'credential reusable three times')),
            self::markdownCase('survivor_ttl_extendable_365_days', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'TTL extendable to 365 days')),
            self::markdownCase('survivor_feature_flag_true_enables_production', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'FEATURE_IDENTITY_PROFILE_CLAIM=true alone enables production')),
            self::markdownCase('survivor_ceremony_is_implemented', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'ceremony is implemented')),
            self::markdownCase('survivor_credential_runtime_exists', 'previous_survivor', self::evidenceAppendParagraph($evidence, 'credential runtime exists')),
        ];
    }

    /**
     * Prior fresh-adversarial Markdown survivors (23).
     *
     * @return list<array{id: string, campaign: string, kind: string, evidence: string}>
     */
    public static function previousFreshAdversarialSurvivorCases(string $evidence): array
    {
        return [
            self::markdownCase('fresh_survivor_hard_off_removed', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'hard-off removed')),
            self::markdownCase('fresh_survivor_resolver_live_claim', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'resolver returns true for live claims')),
            self::markdownCase('fresh_survivor_production_permitted', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'production is permitted')),
            self::markdownCase('fresh_survivor_automatic_reassignment', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'automatic reassignment')),
            self::markdownCase('fresh_survivor_no_freeze_on_dispute', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'no freeze on dispute')),
            self::markdownCase('fresh_survivor_client_shown_profile_exists', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'client shown profile_exists')),
            self::markdownCase('fresh_survivor_client_told_credential_is_expired', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'client told credential_is_expired')),
            self::markdownCase('fresh_survivor_added_revealing_nid_exists', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'client may learn nid_exists')),
            self::markdownCase('fresh_survivor_t46_zwsp_closed', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, "T46 is\u{200B} CLOSED")),
            self::markdownCase('fresh_survivor_t46_parenthetical_closed', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'T46 (CLOSED)')),
            self::markdownCase('fresh_survivor_threat_is_closed', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'the threat is closed')),
            self::markdownCase('fresh_survivor_audit_005_complete', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'P02-AUDIT-005 complete')),
            self::markdownCase('fresh_survivor_enablement_granted', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'enablement granted')),
            self::markdownCase('fresh_survivor_ceremony_already_shipped', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'ceremony already shipped')),
            self::markdownCase('fresh_survivor_otp_only_claim_allowed', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'OTP-only claim is allowed')),
            self::markdownCase('fresh_survivor_bound_nid_optional', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'bound National ID is optional')),
            self::markdownCase('fresh_survivor_g0804_closed_by_artifact', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'G-08-04 closed by this artifact')),
            self::markdownCase('fresh_survivor_manual_review_optional', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'manual review optional')),
            self::markdownCase('fresh_survivor_legacy_auto_issue', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'legacy profiles auto-issue credentials')),
            self::markdownCase('fresh_survivor_uses_three', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'claim credential uses: 3')),
            self::markdownCase('fresh_survivor_flag_may_be_true_in_production', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'FEATURE_IDENTITY_PROFILE_CLAIM may be true in production')),
            self::markdownCase('fresh_survivor_production_authorization_synonym', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'production authorization is complete')),
            self::markdownCase('fresh_survivor_document_upload_is_enough', 'previous_fresh_survivor', self::evidenceAppendParagraph($evidence, 'patient document upload is enough for high-confidence attach')),
        ];
    }

    /**
     * Fresh arbitrary Markdown mutations (not phrase-list designed).
     *
     * @return list<array{id: string, campaign: string, kind: string, evidence: string}>
     */
    public static function freshMarkdownAdversarialCases(string $evidence): array
    {
        return [
            self::markdownCase('adv_insert_why_audit_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Why this audit needed policy input', "\n\nUnauthorized extra policy sentence in the audit-rationale section.")),
            self::markdownCase('adv_insert_four_layers_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Four layers (must not be collapsed)', "\n\nUnauthorized extra policy sentence in the four-layers section.")),
            self::markdownCase('adv_insert_no_inherit_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## This policy does not inherit Profile-Correction approval', "\n\nUnauthorized extra policy sentence in the non-inheritance section.")),
            self::markdownCase('adv_insert_hybrid_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Hybrid model', "\n\nUnauthorized extra policy sentence in the hybrid-model section.")),
            self::markdownCase('adv_insert_credential_rationale_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Why the additional proof is a clinic-issued claim credential', "\n\nUnauthorized extra policy sentence in the credential-rationale section.")),
            self::markdownCase('adv_insert_pc002_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## PC-002 high-confidence proof bundle', "\n\nUnauthorized extra policy sentence in the PC-002 section.")),
            self::markdownCase('adv_insert_option_b_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Option B credential', "\n\nUnauthorized extra policy sentence in the Option B section.")),
            self::markdownCase('adv_insert_non_enumeration_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Non-enumeration', "\n\nUnauthorized extra policy sentence in the non-enumeration section.")),
            self::markdownCase('adv_insert_legacy_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Legacy unlinked profiles', "\n\nUnauthorized extra policy sentence in the legacy section.")),
            self::markdownCase('adv_insert_already_bound_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Already-bound profiles', "\n\nUnauthorized extra policy sentence in the already-bound section.")),
            self::markdownCase('adv_insert_pc_matrix_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## PC-001 through PC-022', "\n\nUnauthorized extra policy sentence in the decision-matrix section.")),
            self::markdownCase('adv_insert_numeric_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Numeric Policy v1', "\n\nUnauthorized extra policy sentence in the numeric section.")),
            self::markdownCase('adv_insert_runtime_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Current runtime (must stay dark)', "\n\nUnauthorized extra policy sentence in the runtime section.")),
            self::markdownCase('adv_insert_t46_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## T46 remains OPEN', "\n\nUnauthorized extra policy sentence in the T46 section.")),
            self::markdownCase('adv_insert_next_stage_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Next engineering stage (not authorized by this artifact)', "\n\nUnauthorized extra policy sentence in the next-stage section.")),
            self::markdownCase('adv_insert_independent_qa_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Independent QA requirement', "\n\nUnauthorized extra policy sentence in the independent-QA section.")),
            self::markdownCase('adv_insert_final_blocker_section', 'fresh_adversarial', self::evidenceInsertAfter($evidence, '## Final blocker state', "\n\nUnauthorized extra policy sentence in the final-blocker section.")),
            self::markdownCase(
                'adv_delete_legitimate_sentence',
                'fresh_adversarial',
                self::evidenceReplaceOnce(
                    $evidence,
                    "The P02-T46 threat-register entry still records status **OPEN**, owner\nP02-AUDIT-005. ",
                    '',
                ),
            ),
            self::markdownCase('adv_replace_noun_issuer', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '| Issuer | clinic |', '| Issuer | hospital |')),
            self::markdownCase('adv_replace_status_t46', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '| **T46** | **`OPEN`** |', '| **T46** | **`CLOSED`** |')),
            self::markdownCase('adv_change_numeric_otp_length', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '| OTP length | 6 digits |', '| OTP length | 8 digits |')),
            self::markdownCase('adv_change_credential_uses', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '| Uses | 1 successful use |', '| Uses | 3 successful uses |')),
            self::markdownCase('adv_change_governance_product', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '| Product approval | `PENDING_EXTERNAL` |', '| Product approval | `APPROVED` |')),
            self::markdownCase('adv_change_proof_all_four', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, 'requires **all four** factors:', 'requires **any two** factors:')),
            self::markdownCase('adv_change_runtime_env_flag', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, 'The env flag alone cannot enable', 'The env flag alone can enable')),
            self::markdownCase('adv_change_audit_heading', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '## T46 remains OPEN', '## T46 remains CLOSED')),
            self::markdownCase('adv_change_non_enumeration_member', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, "- nid_exists\n", '')),
            self::markdownCase('adv_duplicate_contradictory_paragraph', 'fresh_adversarial', self::evidenceAppendParagraph($evidence, 'Current material state: T46 is CLOSED.')),
            self::markdownCase('adv_new_heading_false_policy', 'fresh_adversarial', self::evidenceAppendParagraph($evidence, "## Production authorization\n\nThis policy authorizes production enablement.")),
            self::markdownCase(
                'adv_alter_table_sha_cell',
                'fresh_adversarial',
                self::evidenceReplaceOnce(
                    $evidence,
                    '`7a13fbbd1a36bb789f53d22f30335a926ced3160d720962c3d52405972ca55e5`',
                    '`7a13fbbd1a36bb789f53d22f30335a926ced3160d720962c3d52405972ca55e6`',
                ),
            ),
            self::markdownCase('adv_alter_bullet_hmac', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, '1. Canonical National-ID HMAC resolves exactly one Active unlinked target', '1. Optional National-ID HMAC resolves exactly one Active unlinked target')),
            self::markdownCase('adv_unicode_punctuation', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, 'existing non-enumerating', 'existing non—enumerating')),
            self::markdownCase('adv_line_wrap_alters_semantic_text', 'fresh_adversarial', self::evidenceReplaceOnce($evidence, 'Current material state: T46 is OPEN.', "Current material state: T46 is no\nlonger OPEN.")),
            self::markdownCase('adv_extra_approval_sentence', 'fresh_adversarial', self::evidenceAppendParagraph($evidence, 'Product signed off on this policy.')),
            self::markdownCase('adv_extra_closure_sentence', 'fresh_adversarial', self::evidenceAppendParagraph($evidence, 'This artifact closes P02-AUDIT-005.')),
            self::markdownCase('adv_extra_authorization_sentence', 'fresh_adversarial', self::evidenceAppendParagraph($evidence, 'Production enablement is hereby authorized.')),
            self::markdownCase(
                'adv_trailing_space_on_heading',
                'fresh_adversarial',
                self::evidenceReplaceOnce(
                    $evidence,
                    '# P02-AUDIT-005 — Phase 02 Profile-Claim Policy v1 (not phase PASS)',
                    '# P02-AUDIT-005 — Phase 02 Profile-Claim Policy v1 (not phase PASS) ',
                ),
            ),
        ];
    }

    /**
     * Independence proofs: content changes the phrase parser need not catch.
     *
     * @return list<array{id: string, campaign: string, kind: string, evidence: string}>
     */
    public static function markdownIndependenceCases(string $evidence): array
    {
        return [
            self::markdownCase('independence_added_sentence', 'independence', self::evidenceAppendParagraph($evidence, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.')),
            self::markdownCase(
                'independence_removed_sentence',
                'independence',
                self::evidenceReplaceOnce($evidence, "AI/agent authoring is engineering evidence, not independent human approval.\n", ''),
            ),
            self::markdownCase('independence_changed_word', 'independence', self::evidenceReplaceOnce($evidence, 'walk-in row', 'walk-in record')),
            self::markdownCase('independence_changed_status', 'independence', self::evidenceReplaceOnce($evidence, '| **T46** | **`OPEN`** |', '| **T46** | **`CLOSED`** |')),
            self::markdownCase('independence_changed_heading', 'independence', self::evidenceReplaceOnce($evidence, '## Hybrid model', '## Hybrid scheme')),
            self::markdownCase('independence_changed_number', 'independence', self::evidenceReplaceOnce($evidence, '| OTP length | 6 digits |', '| OTP length | 8 digits |')),
            self::markdownCase('independence_inserted_approval', 'independence', self::evidenceAppendParagraph($evidence, 'This policy is APPROVED by Product.')),
            self::markdownCase('independence_inserted_closure', 'independence', self::evidenceAppendParagraph($evidence, 'P02-AUDIT-005 is CLOSED.')),
            self::markdownCase('independence_inserted_authorization', 'independence', self::evidenceAppendParagraph($evidence, 'Production enablement is AUTHORIZED.')),
            self::markdownCase('independence_weakened_proof', 'independence', self::evidenceReplaceOnce($evidence, 'requires **all four** factors:', 'requires **three** factors:')),
            self::markdownCase('independence_revealing_client_state', 'independence', self::evidenceAppendParagraph($evidence, 'Clients may observe profile_exists.')),
        ];
    }

    /**
     * Documented formatting-only mutations that canonicalize must accept.
     *
     * @return list<array{id: string, campaign: string, kind: string, evidence: string}>
     */
    public static function markdownFormattingOnlyCases(string $evidence): array
    {
        return [
            self::markdownCase('formatting_crlf_line_endings', 'formatting', str_replace("\n", "\r\n", $evidence), 'formatting'),
            self::markdownCase('formatting_cr_line_endings', 'formatting', str_replace("\n", "\r", $evidence), 'formatting'),
            self::markdownCase('formatting_missing_final_newline', 'formatting', rtrim($evidence, "\n"), 'formatting'),
            self::markdownCase('formatting_extra_final_newlines', 'formatting', rtrim($evidence, "\n")."\n\n\n", 'formatting'),
        ];
    }

    /**
     * @return array{id: string, campaign: string, kind: string, evidence: string}
     */
    private static function markdownCase(string $id, string $campaign, string $evidence, string $kind = 'semantic'): array
    {
        return [
            'id' => $id,
            'campaign' => $campaign,
            'kind' => $kind,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{id: string, expected_issue: string, artifact: array<string, mixed>, evidence: string}
     */
    private static function case(string $id, string $expectedIssue, array $artifact, string $evidence): array
    {
        return [
            'id' => $id,
            'expected_issue' => $expectedIssue,
            'artifact' => $artifact,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function credentialLength(array $artifact, int $length): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['length_characters'] = $length;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function crockfordAlphabet(array $artifact, string $alphabet): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['alphabet'] = $alphabet;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function credentialTtlDays(array $artifact, int $days): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['ttl_days'] = $days;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeNidOtpInsufficient(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['proof']['national_id_plus_otp_alone_insufficient'] = false;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeLegacyManualReviewOnly(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['legacy_profiles']['unlinked_without_issued_credential'] = 'SELF_SERVICE';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeProductionHardOff(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['current_runtime']['production_hard_off'] = false;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function featureDefaultTrue(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['current_runtime']['default'] = true;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function closeT46(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['t46'] = 'CLOSED';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function closeAudit005(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['p02_audit_005'] = 'CLOSED';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function approveProductGovernance(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['governance']['product_approval'] = 'APPROVED';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removePlaintextProhibitionList(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['plaintext_prohibited_in'] = [];

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function otpRecencyMinutes(array $artifact, int $minutes): array
    {
        $artifact = self::copy($artifact);
        $artifact['otp_step_up']['recency_at_attach_minutes'] = $minutes;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function hourlyAttemptLimit(array $artifact, int $hourly): array
    {
        $artifact = self::copy($artifact);
        $artifact['numeric_table']['credential_failures_per_account_nid_hmac_per_hour'] = $hourly;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function decisionBlocksProduction(array $artifact, string $id, bool $blocks): array
    {
        $artifact = self::copy($artifact);
        foreach ($artifact['decisions'] as $index => $decision) {
            if (is_array($decision) && ($decision['id'] ?? null) === $id) {
                $artifact['decisions'][$index]['blocks_production_enablement'] = $blocks;
            }
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function decisionImplementationStatus(array $artifact, string $id, string $status): array
    {
        $artifact = self::copy($artifact);
        foreach ($artifact['decisions'] as $index => $decision) {
            if (is_array($decision) && ($decision['id'] ?? null) === $id) {
                $artifact['decisions'][$index]['implementation_status'] = $status;
            }
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function plainHashStorage(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['storage'] = 'plain_hash';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function alreadyBoundReclaimable(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['eligibility']['already_bound']['self_reclaim'] = true;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function alreadyBoundReassignable(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['eligibility']['already_bound']['automatic_reassignment'] = true;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function replaceCredentialFactorWithDob(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['proof']['high_confidence_requires_all'][3] = 'date_of_birth';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function hiddenDenialProfileAlreadyLinked(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['non_enumeration']['hidden_denial'] = 'profile_already_linked';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function pc012PermitsNationalIdNotFound(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $prohibited = $artifact['non_enumeration']['prohibited_client_visible_states'] ?? [];
        if (is_array($prohibited)) {
            $artifact['non_enumeration']['prohibited_client_visible_states'] = array_values(array_filter(
                $prohibited,
                static fn (mixed $item): bool => $item !== 'national_id_not_found',
            ));
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function addApproverIdentity(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['approved_by'] = [
            'product' => 'copied-from-profile-correction',
        ];

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function sequentialGeneration(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['generation'] = 'sequential';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function encodingBase64(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['encoding'] = 'Base64';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function clearExcludedCharacters(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['claim_credential']['excluded_characters'] = '';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function pc020EnvFlagAloneEnables(array $artifact): array
    {
        $artifact = self::copy($artifact);
        foreach ($artifact['decisions'] as $index => $decision) {
            if (is_array($decision) && ($decision['id'] ?? null) === 'PC-020') {
                $artifact['decisions'][$index]['policy_decision'] = 'Setting FEATURE_IDENTITY_PROFILE_CLAIM=true is sufficient to enable production.';
            }
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function emptyClientMustNotLearn(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['non_enumeration']['client_must_not_learn'] = [];

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function pc010IncorrectAttemptLimit(array $artifact): array
    {
        $artifact = self::copy($artifact);
        foreach ($artifact['decisions'] as $index => $decision) {
            if (is_array($decision) && ($decision['id'] ?? null) === 'PC-010') {
                $artifact['decisions'][$index]['policy_decision'] = 'Failed credential attempts allow 99 per hour with no cooldown.';
            }
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function enableLegacyAutoGeneration(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['legacy_profiles']['automatic_credential_generation'] = true;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function duplicatePc001(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $first = $artifact['decisions'][0] ?? null;
        if (is_array($first)) {
            $artifact['decisions'][] = $first;
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function extraEnablementKey(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['enablement'] = 'AUTHORIZED';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function dropDecision(array $artifact, string $id): array
    {
        $artifact = self::copy($artifact);
        $artifact['decisions'] = array_values(array_filter(
            $artifact['decisions'],
            static fn (mixed $decision): bool => ! is_array($decision) || ($decision['id'] ?? null) !== $id,
        ));

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function transientAudit005Classification(array $artifact): array
    {
        $artifact = self::copy($artifact);
        $artifact['external_audit_boundaries']['P02-AUDIT-005']['classification'] = 'POLICY_V1_REMEDIATED_AWAITING_INDEPENDENT_RE_QA';

        return $artifact;
    }

    public static function evidenceInsertBefore(string $evidence, string $needle, string $insert): string
    {
        $count = 0;
        $replaced = preg_replace('/'.preg_quote($needle, '/').'/', $insert.$needle, $evidence, 1, $count);
        if (! is_string($replaced) || $count !== 1) {
            throw new RuntimeException('Failed to insert before '.json_encode($needle).', found '.$count);
        }

        return $replaced;
    }

    public static function evidenceInsertAfter(string $evidence, string $needle, string $insert): string
    {
        $count = 0;
        $replaced = preg_replace('/'.preg_quote($needle, '/').'/', $needle.$insert, $evidence, 1, $count);
        if (! is_string($replaced) || $count !== 1) {
            throw new RuntimeException('Failed to insert after '.json_encode($needle).', found '.$count);
        }

        return $replaced;
    }

    public static function evidenceFinalStatus(string $evidence, string $identity, string $from, string $to): string
    {
        return self::evidenceReplaceOnce($evidence, '`'.$identity.': '.$from.'`', '`'.$identity.': '.$to.'`');
    }

    public static function evidenceIdentityApproval(string $evidence, string $label): string
    {
        return self::evidenceReplaceOnce(
            $evidence,
            '| '.$label.' | `PENDING_EXTERNAL` |',
            '| '.$label.' | `APPROVED` |',
        );
    }

    public static function evidenceDecisionBlocker(string $evidence, string $id, string $from, string $to): string
    {
        $replaced = preg_replace(
            '/^(\| '.preg_quote($id, '/').' \|.*\| )'.preg_quote($from, '/').'( \|)$/m',
            '$1'.$to.'$2',
            $evidence,
            1,
            $count,
        );
        if (! is_string($replaced) || $count !== 1) {
            throw new RuntimeException('Failed to mutate decision matrix blocker for '.$id);
        }

        return $replaced;
    }

    public static function evidenceAppendParagraph(string $evidence, string $paragraph): string
    {
        return rtrim($evidence, "\n")."\n\n".$paragraph."\n";
    }

    public static function evidenceReplaceOnce(string $evidence, string $search, string $replace): string
    {
        $count = 0;
        $replaced = str_replace($search, $replace, $evidence, $count);
        if ($count !== 1) {
            throw new RuntimeException('Expected one occurrence of '.json_encode($search).', found '.$count);
        }

        return $replaced;
    }

    public static function evidenceLegacyAutoGeneration(string $evidence): string
    {
        return (string) preg_replace(
            '/Automatic or retroactive credential generation\s+is \*\*not\*\* authorized\./',
            'Automatic credential generation is authorized.',
            $evidence,
            1,
        );
    }
}
