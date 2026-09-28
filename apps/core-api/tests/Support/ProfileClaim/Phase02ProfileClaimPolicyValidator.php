<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

/**
 * Structural validator for Profile-Claim Policy v1 artifacts.
 * Used against the committed JSON and against in-memory mutations.
 * Expected values are independently frozen in Phase02ProfileClaimPolicyArtifact.
 */
final class Phase02ProfileClaimPolicyValidator
{
    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    public function validate(array $artifact, string $evidence = ''): array
    {
        $issues = [];
        $issues = array_merge($issues, $this->identityIssues($artifact));
        $issues = array_merge($issues, $this->governanceIssues($artifact, $evidence));
        $issues = array_merge($issues, $this->decisionIssues($artifact));
        $issues = array_merge($issues, $this->credentialIssues($artifact));
        $issues = array_merge($issues, $this->numericIssues($artifact));
        $issues = array_merge($issues, $this->proofIssues($artifact));
        $issues = array_merge($issues, $this->enumerationIssues($artifact));
        $issues = array_merge($issues, $this->legacyIssues($artifact));
        $issues = array_merge($issues, $this->ownershipIssues($artifact));
        $issues = array_merge($issues, $this->runtimePolicyIssues($artifact));
        $issues = array_merge($issues, $this->threatIssues($artifact));
        $issues = array_merge($issues, $this->evidenceIssues($evidence));

        return array_values($issues);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function identityIssues(array $artifact): array
    {
        $issues = [];
        $this->expectSame($artifact['title'] ?? null, Phase02ProfileClaimPolicyArtifact::TITLE, 'title_mismatch', $issues);
        $this->expectSame($artifact['version'] ?? null, Phase02ProfileClaimPolicyArtifact::VERSION, 'version_mismatch', $issues);
        $this->expectSame($artifact['release_date'] ?? null, Phase02ProfileClaimPolicyArtifact::RELEASE_DATE, 'release_date_mismatch', $issues);
        $this->expectSame($artifact['decision'] ?? null, Phase02ProfileClaimPolicyArtifact::DECISION, 'decision_mismatch', $issues);
        $this->expectSame($artifact['decision_state'] ?? null, Phase02ProfileClaimPolicyArtifact::DECISION_STATE, 'decision_state_mismatch', $issues);
        $this->expectSame($artifact['classification'] ?? null, Phase02ProfileClaimPolicyArtifact::CLASSIFICATION, 'classification_mismatch', $issues);
        if (($artifact['status'] ?? null) === 'APPROVED_PRODUCTION_POLICY') {
            $issues[] = 'falsely_labelled_approved_production_policy';
        }
        $this->expectSame($artifact['status'] ?? null, Phase02ProfileClaimPolicyArtifact::DECISION_STATE, 'status_not_controller_policy_v1_frozen', $issues);
        $this->expectSame($artifact['production_enablement'] ?? null, 'NOT_AUTHORIZED', 'production_enablement_not_not_authorized', $issues);
        $this->expectSame($artifact['feature_state'] ?? null, 'DISABLED', 'feature_state_not_disabled', $issues);
        $this->expectSame($artifact['p02_audit_005'] ?? null, 'OPEN', 'p02_audit_005_not_open', $issues);
        $this->expectSame($artifact['t46'] ?? null, 'OPEN', 't46_not_open', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function governanceIssues(array $artifact, string $evidence): array
    {
        $issues = [];
        $governance = is_array($artifact['governance'] ?? null) ? $artifact['governance'] : [];
        foreach (['product_approval', 'security_approval', 'privacy_approval', 'support_operations_approval'] as $key) {
            $this->expectSame($governance[$key] ?? null, 'PENDING_EXTERNAL', $key.'_not_pending_external', $issues);
        }
        $this->expectSame($governance['g_08_04'] ?? null, 'OPEN / EXTERNAL_HUMAN', 'g_08_04_not_open_external_human', $issues);
        $this->expectSame($governance['g_08_04_claimed_approved'] ?? null, false, 'g_08_04_claimed_approved', $issues);
        $this->expectSame($governance['does_not_reuse_profile_correction_approval'] ?? null, true, 'reuses_profile_correction_approval', $issues);
        $this->expectSame($governance['does_not_copy_approver_identities_from_other_policies'] ?? null, true, 'copies_approver_identities_from_other_policies', $issues);

        $boundaries = is_array($artifact['external_audit_boundaries'] ?? null) ? $artifact['external_audit_boundaries'] : [];
        $audit005 = is_array($boundaries['P02-AUDIT-005'] ?? null) ? $boundaries['P02-AUDIT-005'] : [];
        $audit006 = is_array($boundaries['P02-AUDIT-006'] ?? null) ? $boundaries['P02-AUDIT-006'] : [];
        $audit007 = is_array($boundaries['P02-AUDIT-007'] ?? null) ? $boundaries['P02-AUDIT-007'] : [];
        $this->expectSame($audit005['status'] ?? null, 'OPEN', 'p02_audit_005_boundary_not_open', $issues);
        $this->expectSame($audit006['status'] ?? null, 'OPEN / UNCHANGED', 'p02_audit_006_boundary_changed', $issues);
        $this->expectSame($audit007['status'] ?? null, 'OPEN / EXTERNAL_HUMAN', 'p02_audit_007_boundary_not_open_external_human', $issues);
        $this->expectSame($audit007['g_08_04'] ?? null, 'OPEN / EXTERNAL_HUMAN', 'p02_audit_007_g_08_04_not_open_external_human', $issues);
        if (array_key_exists('approved_by', $artifact)) {
            $issues[] = 'approved_by_present';
        }
        $blob = json_encode($artifact, JSON_THROW_ON_ERROR)."\n".$evidence;
        if (str_contains($blob, 'G-08-04 APPROVED')) {
            $issues[] = 'g_08_04_approved_wording';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function decisionIssues(array $artifact): array
    {
        $issues = [];
        $decisions = $artifact['decisions'] ?? null;
        if (! is_array($decisions)) {
            return ['decisions_list_missing'];
        }

        $ids = [];
        $seen = [];
        $byId = [];
        foreach ($decisions as $decision) {
            if (! is_array($decision) || ! isset($decision['id']) || ! is_string($decision['id'])) {
                $issues[] = 'decision_missing_id';

                continue;
            }
            $id = $decision['id'];
            $ids[] = $id;
            if (isset($seen[$id])) {
                $issues[] = 'duplicate_pc_id';
            }
            $seen[$id] = true;
            $byId[$id] = $decision;
            foreach (['policy_decision', 'implementation_status', 'implementation_evidence_requirement', 'blocks_production_enablement', 'title'] as $field) {
                if (! array_key_exists($field, $decision)) {
                    $issues[] = $id.'_missing_'.$field;
                }
            }
        }

        foreach (Phase02ProfileClaimPolicyArtifact::DECISION_IDS as $expectedId) {
            if (! isset($byId[$expectedId])) {
                $issues[] = 'missing_pc_id:'.$expectedId;
            }
        }
        if ($ids !== Phase02ProfileClaimPolicyArtifact::DECISION_IDS) {
            $issues[] = 'pc_id_set_or_order_mismatch';
        }

        foreach (Phase02ProfileClaimPolicyArtifact::DECISION_EXPECTATIONS as $id => $expected) {
            $decision = $byId[$id] ?? null;
            if (! is_array($decision)) {
                continue;
            }
            $this->expectSame($decision['title'] ?? null, $expected['title'], $id.'_title_mismatch', $issues);
            $this->expectSame($decision['implementation_status'] ?? null, $expected['implementation_status'], $id.'_implementation_status_mismatch', $issues);
            $this->expectSame($decision['blocks_production_enablement'] ?? null, $expected['blocks_production_enablement'], $id.'_blocks_production_enablement_mismatch', $issues);
            $this->expectSame($decision['policy_decision'] ?? null, $expected['policy_decision'], $id.'_policy_decision_mismatch', $issues);
        }

        $pc012 = is_array($byId['PC-012'] ?? null) ? $byId['PC-012'] : [];
        $pc012Text = is_string($pc012['policy_decision'] ?? null) ? $pc012['policy_decision'] : '';
        if ($pc012Text !== '' && (str_contains($pc012Text, 'may return national_id_not_found')
            || str_contains($pc012Text, 'may learn national_id_not_found')
            || str_contains($pc012Text, 'May return national_id_not_found')
            || str_contains($pc012Text, 'May introduce national_id_not_found')
            || (str_contains($pc012Text, 'national_id_not_found') && ! str_contains($pc012Text, 'Do not introduce')))) {
            $issues[] = 'pc012_permits_national_id_not_found';
        }

        $pc020 = is_array($byId['PC-020'] ?? null) ? $byId['PC-020'] : [];
        $pc020Text = is_string($pc020['policy_decision'] ?? null) ? $pc020['policy_decision'] : '';
        if ($pc020Text !== '' && ! str_contains($pc020Text, 'env flag alone cannot enable production')) {
            if (preg_match('/FEATURE_IDENTITY_PROFILE_CLAIM=true.{0,120}(?:sufficient|can enable production)|env flag alone can enable production/i', $pc020Text) === 1) {
                $issues[] = 'pc020_allows_env_flag_alone_enablement';
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function credentialIssues(array $artifact): array
    {
        $issues = [];
        $credential = is_array($artifact['claim_credential'] ?? null) ? $artifact['claim_credential'] : [];
        $this->expectSame($credential['option'] ?? null, 'B', 'credential_option_not_b', $issues);
        $this->expectSame($credential['encoding'] ?? null, Phase02ProfileClaimPolicyArtifact::CROCKFORD_ENCODING, 'credential_encoding_not_crockford_base32', $issues);
        $this->expectSame($credential['length_characters'] ?? null, 16, 'credential_length_not_16', $issues);
        $this->expectSame($credential['entropy_bits_approximate'] ?? null, 80, 'credential_entropy_not_80', $issues);
        $this->expectSame($credential['alphabet'] ?? null, Phase02ProfileClaimPolicyArtifact::CROCKFORD_ALPHABET, 'crockford_alphabet_altered', $issues);
        $this->expectSame($credential['excluded_characters'] ?? null, Phase02ProfileClaimPolicyArtifact::EXCLUDED_CHARACTERS, 'credential_excluded_characters_altered', $issues);
        $this->expectSame($credential['display_only_format'] ?? null, Phase02ProfileClaimPolicyArtifact::DISPLAY_FORMAT, 'display_format_mismatch', $issues);
        $this->expectSame($credential['canonical_form'] ?? null, Phase02ProfileClaimPolicyArtifact::CANONICAL_FORM, 'credential_canonical_form_mismatch', $issues);
        $this->expectSame($credential['generation'] ?? null, Phase02ProfileClaimPolicyArtifact::GENERATION, 'credential_generation_not_csprng', $issues);
        $this->expectSame($credential['ttl_days'] ?? null, 30, 'credential_ttl_not_30_days', $issues);
        $this->expectSame($credential['successful_uses'] ?? null, 1, 'credential_successful_uses_not_1', $issues);
        $this->expectSame($credential['show_print_send'] ?? null, Phase02ProfileClaimPolicyArtifact::SHOW_PRINT_SEND, 'credential_show_print_send_not_once_at_issuance', $issues);
        $this->expectSame($credential['storage'] ?? null, Phase02ProfileClaimPolicyArtifact::STORAGE, 'credential_storage_not_peppered_hash_only', $issues);
        $this->expectSame($credential['plaintext_persistence'] ?? null, false, 'plaintext_persistence_not_prohibited', $issues);
        $prohibited = $credential['plaintext_prohibited_in'] ?? [];
        if (! is_array($prohibited) || array_values($prohibited) !== Phase02ProfileClaimPolicyArtifact::PLAINTEXT_PROHIBITED_IN) {
            $issues[] = 'plaintext_prohibition_list_altered';
        }
        $comparison = is_array($credential['comparison'] ?? null) ? $credential['comparison'] : [];
        $this->expectSame($comparison['strip_display_separators'] ?? null, true, 'credential_strip_display_separators_not_true', $issues);
        $this->expectSame($comparison['canonicalize_case'] ?? null, true, 'credential_case_canonicalize_not_true', $issues);
        $this->expectSame($comparison['hyphens_never_part_of_canonical_secret_or_hash_input'] ?? null, true, 'hyphens_must_never_enter_canonical_hash_input', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function numericIssues(array $artifact): array
    {
        $issues = [];
        $table = is_array($artifact['numeric_table'] ?? null) ? $artifact['numeric_table'] : [];
        foreach (Phase02ProfileClaimPolicyArtifact::NUMERIC_TABLE as $key => $value) {
            $this->expectSame($table[$key] ?? null, $value, 'numeric_table_'.$key.'_drifted', $issues);
        }
        $otp = is_array($artifact['otp_step_up'] ?? null) ? $artifact['otp_step_up'] : [];
        $this->expectSame($otp['recency_at_attach_minutes'] ?? null, 10, 'otp_recency_not_10_minutes', $issues);
        $this->expectSame($otp['aal1_session_alone_insufficient'] ?? null, true, 'aal1_session_must_be_insufficient', $issues);
        $this->expectSame($otp['patient_totp_required_in_v1'] ?? null, false, 'patient_totp_must_not_be_required_in_v1', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function proofIssues(array $artifact): array
    {
        $issues = [];
        $proof = is_array($artifact['proof'] ?? null) ? $artifact['proof'] : [];
        $this->expectSame($proof['national_id_plus_otp_alone_insufficient'] ?? null, true, 'nid_plus_otp_insufficient_flag_removed', $issues);
        $this->expectSame($proof['account_bound_national_id_required'] ?? null, true, 'account_bound_national_id_required_missing', $issues);
        $this->expectSame($proof['missing_account_bound_national_id_is_not_high_confidence'] ?? null, true, 'missing_account_bound_national_id_treated_as_high_confidence', $issues);
        $this->expectSame($proof['matches_bound_identity_null_stored_hmac_is_not_high_confidence'] ?? null, true, 'matches_bound_identity_null_hmac_treated_as_high_confidence', $issues);
        $this->expectSame($proof['missing_account_bound_national_id_routes_to'] ?? null, 'internal_manual_review_generic_client_contract', 'missing_bound_nid_route_drifted', $issues);
        $rejected = $proof['not_independent_additional_proof'] ?? [];
        if (! is_array($rejected) || array_values($rejected) !== Phase02ProfileClaimPolicyArtifact::REJECTED_INDEPENDENT_PROOF) {
            $issues[] = 'rejected_independent_proof_list_altered';
        }
        $bundle = $proof['high_confidence_requires_all'] ?? [];
        if (! is_array($bundle) || array_values($bundle) !== Phase02ProfileClaimPolicyArtifact::HIGH_CONFIDENCE_BUNDLE) {
            $issues[] = 'high_confidence_bundle_mismatch';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function enumerationIssues(array $artifact): array
    {
        $issues = [];
        $enumeration = is_array($artifact['non_enumeration'] ?? null) ? $artifact['non_enumeration'] : [];
        $mustNotLearn = $enumeration['client_must_not_learn'] ?? [];
        if (! is_array($mustNotLearn) || array_values($mustNotLearn) !== Phase02ProfileClaimPolicyArtifact::CLIENT_MUST_NOT_LEARN) {
            $issues[] = 'client_must_not_learn_altered';
        }
        if (is_array($mustNotLearn) && $mustNotLearn === []) {
            $issues[] = 'client_must_not_learn_empty';
        }
        $prohibited = $enumeration['prohibited_client_visible_states'] ?? [];
        if (! is_array($prohibited) || array_values($prohibited) !== Phase02ProfileClaimPolicyArtifact::PROHIBITED_CLIENT_STATES) {
            $issues[] = 'non_enumeration_prohibited_client_states_altered';
        }
        if (! is_array($prohibited) || ! in_array('national_id_not_found', $prohibited, true)) {
            $issues[] = 'pc012_permits_national_id_not_found';
        }
        $this->expectSame($enumeration['generic_pending'] ?? null, 'manual_review_required', 'generic_pending_contract_drifted', $issues);
        $this->expectSame($enumeration['hidden_denial'] ?? null, 'NOT_FOUND', 'hidden_denial_is_not_not_found', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function legacyIssues(array $artifact): array
    {
        $issues = [];
        $legacy = is_array($artifact['legacy_profiles'] ?? null) ? $artifact['legacy_profiles'] : [];
        $this->expectSame($legacy['unlinked_without_issued_credential'] ?? null, 'MANUAL_REVIEW_ONLY', 'legacy_manual_review_only_removed', $issues);
        $this->expectSame($legacy['retroactive_credential_generation'] ?? null, false, 'retroactive_credential_generation_not_forbidden', $issues);
        $this->expectSame($legacy['automatic_credential_generation'] ?? null, false, 'automatic_credential_generation_not_forbidden', $issues);
        $this->expectSame($legacy['external_response'] ?? null, 'manual_review_required', 'legacy_external_response_drifted', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function ownershipIssues(array $artifact): array
    {
        $issues = [];
        $ownership = is_array($artifact['ownership'] ?? null) ? $artifact['ownership'] : [];
        $this->expectSame($ownership['one_user_one_patient_profile'] ?? null, true, 'one_user_one_profile_policy_removed', $issues);
        $this->expectSame($ownership['one_authoritative_profile_one_user'] ?? null, true, 'one_profile_one_user_policy_removed', $issues);
        $already = is_array($artifact['eligibility']['already_bound'] ?? null) ? $artifact['eligibility']['already_bound'] : [];
        $this->expectSame($already['self_reclaim'] ?? null, false, 'already_bound_self_reclaim_allowed', $issues);
        $this->expectSame($already['overwrite'] ?? null, false, 'already_bound_overwrite_allowed', $issues);
        $this->expectSame($already['automatic_reassignment'] ?? null, false, 'already_bound_automatic_reassignment_allowed', $issues);
        $this->expectSame($already['automatic_transfer_to_another_user'] ?? null, false, 'already_bound_automatic_transfer_allowed', $issues);
        $this->expectSame($already['existing_user_id_unchanged'] ?? null, true, 'already_bound_user_id_may_change', $issues);
        $this->expectSame($already['disclose_already_bound'] ?? null, false, 'already_bound_disclose_allowed', $issues);
        $dispute = is_array($artifact['dispute'] ?? null) ? $artifact['dispute'] : [];
        $this->expectSame($dispute['automatic_reassignment'] ?? null, false, 'dispute_automatic_reassignment_allowed', $issues);
        $this->expectSame($dispute['automatic_transfer_to_another_user'] ?? null, false, 'dispute_automatic_transfer_allowed', $issues);
        $notifications = is_array($artifact['notifications'] ?? null) ? $artifact['notifications'] : [];
        $forbidden = $notifications['must_not_contain'] ?? [];
        if (! is_array($forbidden) || ! in_array('claim_credential', $forbidden, true) || ! in_array('national_id', $forbidden, true)) {
            $issues[] = 'notification_privacy_policy_altered';
        }
        $observability = $artifact['observability_prerequisites_before_pc020'] ?? [];
        if (! is_array($observability) || array_values($observability) !== Phase02ProfileClaimPolicyArtifact::OBSERVABILITY_PREREQUISITES) {
            $issues[] = 'observability_prerequisites_altered';
        }
        $pc020 = $artifact['pc020_prerequisites'] ?? [];
        if (! is_array($pc020) || array_values($pc020) !== Phase02ProfileClaimPolicyArtifact::PC020_PREREQUISITES) {
            $issues[] = 'pc020_prerequisites_altered';
        }
        $runbook = is_array($artifact['runbook'] ?? null) ? $artifact['runbook'] : [];
        $this->expectSame($runbook['dedicated_profile_claim_incident_runbook_required_before_production_enablement'] ?? null, true, 'runbook_prerequisite_removed', $issues);
        $kill = is_array($artifact['kill_switch'] ?? null) ? $artifact['kill_switch'] : [];
        $this->expectSame($kill['disabling_stops_new_claims'] ?? null, true, 'kill_switch_new_claim_stop_removed', $issues);
        $this->expectSame($kill['disabling_does_not_automatically_unlink_valid_links'] ?? null, true, 'kill_switch_preserve_links_removed', $issues);
        $this->expectSame($kill['verified_kill_switch_required_before_pc020'] ?? null, true, 'verified_kill_switch_not_required_before_pc020', $issues);
        $this->expectSame($kill['env_flag_true_alone_cannot_enable_production'] ?? null, true, 'env_flag_true_alone_cannot_enable_production_removed', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function runtimePolicyIssues(array $artifact): array
    {
        $issues = [];
        $runtime = is_array($artifact['current_runtime'] ?? null) ? $artifact['current_runtime'] : [];
        $this->expectSame($runtime['default'] ?? null, false, 'feature_default_not_false', $issues);
        $this->expectSame($runtime['production_hard_off'] ?? null, true, 'production_hard_off_requirement_removed', $issues);
        $this->expectSame($runtime['production_env_flag_cannot_enable'] ?? null, true, 'production_env_flag_cannot_enable_removed', $issues);
        $this->expectSame($runtime['this_artifact_does_not_change_runtime'] ?? null, true, 'runtime_unchanged_declaration_removed', $issues);
        $table = is_array($artifact['numeric_table'] ?? null) ? $artifact['numeric_table'] : [];
        $this->expectSame($table['feature_default'] ?? null, false, 'numeric_feature_default_not_false', $issues);

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function threatIssues(array $artifact): array
    {
        $issues = [];
        $t46 = is_array($artifact['threat_register']['T46'] ?? null) ? $artifact['threat_register']['T46'] : [];
        $this->expectSame($t46['id'] ?? null, 'P02-T46', 't46_id_mismatch', $issues);
        $this->expectSame($t46['status'] ?? null, 'OPEN', 't46_register_not_open', $issues);
        $this->expectSame($t46['this_artifact_does_not_close_t46'] ?? null, true, 't46_non_closure_declaration_removed', $issues);
        $this->expectSame($t46['owner'] ?? null, 'P02-AUDIT-005', 't46_owner_mismatch', $issues);

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function evidenceIssues(string $evidence): array
    {
        if ($evidence === '') {
            return [];
        }

        $issues = [];
        if (! str_contains($evidence, 'CONTROLLER_POLICY_V1_FROZEN')) {
            $issues[] = 'evidence_missing_decision_state';
        }
        if (! str_contains($evidence, 'does not reuse') && ! str_contains($evidence, 'does **not** reuse')) {
            $issues[] = 'evidence_reuses_profile_correction_approval';
        }
        if (! $this->markdownHasIdentityState($evidence, 'P02-AUDIT-005', 'OPEN')) {
            $issues[] = 'evidence_p02_audit_005_not_explicitly_open';
        }
        if ($this->markdownHasIdentityState($evidence, 'P02-AUDIT-005', 'CLOSED')) {
            $issues[] = 'evidence_p02_audit_005_explicitly_closed';
        }
        if (! $this->markdownHasIdentityState($evidence, 'T46', 'OPEN')) {
            $issues[] = 'evidence_t46_not_explicitly_open';
        }
        if ($this->markdownHasIdentityState($evidence, 'T46', 'CLOSED')) {
            $issues[] = 'evidence_t46_explicitly_closed';
        }
        if (! str_contains($evidence, '**G-08-04**') || ! str_contains($evidence, 'EXTERNAL_HUMAN')) {
            $issues[] = 'evidence_g_08_04_not_open_external_human';
        }
        if (! str_contains($evidence, Phase02ProfileClaimPolicyArtifact::PUBLISHED_SHA256)) {
            $issues[] = 'evidence_sha256_mismatch';
        }
        if (preg_match('/SHA-256 \| `'.Phase02ProfileClaimPolicyArtifact::SUPERSEDED_UNMERGED_SHA256.'`/', $evidence) === 1) {
            $issues[] = 'evidence_authoritative_sha_is_superseded_digest';
        }
        if (! $this->markdownHasIdentityState($evidence, 'Production enablement', 'NOT_AUTHORIZED')) {
            $issues[] = 'evidence_production_enablement_not_not_authorized';
        }
        if ($this->markdownHasIdentityState($evidence, 'Production enablement', 'AUTHORIZED')) {
            $issues[] = 'evidence_production_enablement_authorized';
        }
        if (! $this->markdownHasIdentityState($evidence, 'Feature state', 'DISABLED')) {
            $issues[] = 'evidence_feature_state_not_disabled';
        }
        if (! preg_match('/Length \| \*\*16\*\*/', $evidence) && ! str_contains($evidence, '16 Crockford Base32 characters')) {
            $issues[] = 'evidence_credential_length_not_16';
        }
        if (preg_match('/Length \| \*\*10\*\*/', $evidence) === 1) {
            $issues[] = 'evidence_credential_length_not_16';
        }
        if (! str_contains($evidence, 'peppered-hash-only') && ! str_contains($evidence, 'peppered hash only')) {
            $issues[] = 'evidence_storage_not_peppered_hash_only';
        }
        if (preg_match('/\bplain(?:text)?[- ]hash\b/i', $evidence) === 1 && ! str_contains($evidence, 'peppered-hash-only')) {
            $issues[] = 'evidence_storage_not_peppered_hash_only';
        }
        if (str_contains($evidence, 'plain-hash-only') || str_contains($evidence, 'Storage | plain hash')) {
            $issues[] = 'evidence_storage_not_peppered_hash_only';
        }
        if (! str_contains($evidence, 'MANUAL_REVIEW_ONLY')) {
            $issues[] = 'evidence_legacy_not_manual_review_only';
        }
        $normalizedEvidence = preg_replace('/\s+/', ' ', $evidence) ?? $evidence;
        if (! str_contains($normalizedEvidence, 'Automatic or retroactive credential generation is **not** authorized')
            && ! str_contains($normalizedEvidence, 'automatic or retroactive credential generation is **not** authorized')) {
            $issues[] = 'evidence_legacy_automatic_credential_generation';
        }
        foreach (['Product approval', 'Security approval', 'Privacy approval'] as $label) {
            if ($this->markdownHasIdentityState($evidence, $label, 'APPROVED')) {
                $issues[] = 'evidence_governance_approved';
            }
            if (! $this->markdownHasIdentityState($evidence, $label, 'PENDING_EXTERNAL')) {
                $issues[] = 'evidence_governance_not_pending_external';
            }
        }
        if (! str_contains($evidence, 'cryptographically secure random')) {
            $issues[] = 'evidence_missing_csprng';
        }
        if (! str_contains($evidence, 'case canonicalization')) {
            $issues[] = 'evidence_missing_case_canonicalization';
        }
        if (! str_contains($evidence, 'display-separator stripping')) {
            $issues[] = 'evidence_missing_separator_stripping';
        }
        if (! str_contains($evidence, 'show/print/send-once') && ! str_contains($evidence, 'Show/print/send-once')) {
            $issues[] = 'evidence_missing_show_once';
        }
        foreach (Phase02ProfileClaimPolicyArtifact::PLAINTEXT_PROHIBITED_IN as $destination) {
            if (! str_contains($evidence, $destination)) {
                $issues[] = 'evidence_missing_plaintext_boundary';
                break;
            }
        }
        foreach (Phase02ProfileClaimPolicyArtifact::CLIENT_MUST_NOT_LEARN as $item) {
            if (! str_contains($evidence, $item)) {
                $issues[] = 'evidence_missing_client_must_not_learn';
                break;
            }
        }
        if (! str_contains($evidence, 'non-empty bound National-ID') && ! str_contains($evidence, 'non-empty matching account-bound National-ID')) {
            $issues[] = 'evidence_missing_bound_nid_requirement';
        }
        if (! str_contains($evidence, 'missing account-bound National ID is **not** high-confidence')
            && ! str_contains($evidence, 'A missing account-bound National ID is **not** high-confidence')) {
            $issues[] = 'evidence_missing_bound_nid_requirement';
        }
        if (! str_contains($evidence, 'PC-017 is a production-enablement blocker')) {
            $issues[] = 'evidence_missing_pc017_blocker';
        }
        if (! str_contains($evidence, 'PC-019 is a production-enablement blocker')) {
            $issues[] = 'evidence_missing_pc019_blocker';
        }
        if (! str_contains($evidence, 'PC-022 is a production-enablement blocker')) {
            $issues[] = 'evidence_missing_pc022_blocker';
        }
        if (! str_contains($evidence, 'verified kill switch')) {
            $issues[] = 'evidence_missing_verified_kill_switch';
        }
        if (! str_contains($evidence, 'env flag alone cannot enable production')) {
            $issues[] = 'evidence_missing_pc020_env_flag_limitation';
        }
        foreach (['policy recorded', 'ceremony implementation complete', 'tests complete', 'observability complete', 'verified kill switch'] as $prerequisite) {
            if (! str_contains($evidence, $prerequisite)) {
                $issues[] = 'evidence_missing_pc020_prerequisites';
                break;
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * Detects `IDENTITY: STATE` or table `| IDENTITY | STATE |` markers.
     * Does not treat "does not close IDENTITY" as a state assignment.
     */
    private function markdownHasIdentityState(string $text, string $identity, string $state): bool
    {
        $id = $identity === 'T46'
            ? '(?<![A-Za-z0-9-])T46(?![A-Za-z0-9-])'
            : preg_quote($identity, '/');
        $st = preg_quote($state, '/');

        return preg_match(
            '/(?:^|\n)\s*(?:\|\s*)?(?:\*\*)?'.$id.'(?:\*\*)?\s*(?:\||:)\s*(?:\*\*)?`?'.$st.'`?/u',
            $text,
        ) === 1;
    }

    /**
     * @param  list<string>  $issues
     */
    private function expectSame(mixed $actual, mixed $expected, string $issue, array &$issues): void
    {
        if ($actual !== $expected) {
            $issues[] = $issue;
        }
    }
}
