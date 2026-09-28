<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

/**
 * Structural validator for Profile-Claim Policy v1 artifacts.
 * Used against the committed JSON and against in-memory mutations.
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
        if (($artifact['title'] ?? null) !== Phase02ProfileClaimPolicyArtifact::TITLE) {
            $issues[] = 'title mismatch';
        }
        if (($artifact['version'] ?? null) !== Phase02ProfileClaimPolicyArtifact::VERSION) {
            $issues[] = 'version mismatch';
        }
        if (($artifact['release_date'] ?? null) !== Phase02ProfileClaimPolicyArtifact::RELEASE_DATE) {
            $issues[] = 'release_date mismatch';
        }
        if (($artifact['decision'] ?? null) !== Phase02ProfileClaimPolicyArtifact::DECISION) {
            $issues[] = 'model/decision mismatch';
        }
        if (($artifact['decision_state'] ?? null) !== Phase02ProfileClaimPolicyArtifact::DECISION_STATE) {
            $issues[] = 'decision_state mismatch';
        }
        if (($artifact['status'] ?? null) === 'APPROVED_PRODUCTION_POLICY') {
            $issues[] = 'falsely labelled APPROVED_PRODUCTION_POLICY';
        }
        if (($artifact['status'] ?? null) !== Phase02ProfileClaimPolicyArtifact::DECISION_STATE) {
            $issues[] = 'status must be CONTROLLER_POLICY_V1_FROZEN';
        }
        if (($artifact['production_enablement'] ?? null) !== 'NOT_AUTHORIZED') {
            $issues[] = 'production_enablement must be NOT_AUTHORIZED';
        }
        if (($artifact['feature_state'] ?? null) !== 'DISABLED') {
            $issues[] = 'feature_state must be DISABLED';
        }
        if (($artifact['p02_audit_005'] ?? null) !== 'OPEN') {
            $issues[] = 'P02-AUDIT-005 claimed closed';
        }
        if (($artifact['t46'] ?? null) !== 'OPEN') {
            $issues[] = 'T46 claimed closed';
        }

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
            if (($governance[$key] ?? null) !== 'PENDING_EXTERNAL') {
                $issues[] = $key.' is not PENDING_EXTERNAL';
            }
        }
        if (($governance['g_08_04'] ?? null) !== 'OPEN / EXTERNAL_HUMAN') {
            $issues[] = 'G-08-04 is not OPEN / EXTERNAL_HUMAN';
        }
        if (($governance['g_08_04_claimed_approved'] ?? null) !== false) {
            $issues[] = 'G-08-04 claimed approved';
        }
        if (($governance['does_not_reuse_profile_correction_approval'] ?? null) !== true) {
            $issues[] = 'must not reuse profile-correction approval';
        }
        $boundaries = is_array($artifact['external_audit_boundaries'] ?? null) ? $artifact['external_audit_boundaries'] : [];
        $audit005 = is_array($boundaries['P02-AUDIT-005'] ?? null) ? $boundaries['P02-AUDIT-005'] : [];
        $audit006 = is_array($boundaries['P02-AUDIT-006'] ?? null) ? $boundaries['P02-AUDIT-006'] : [];
        $audit007 = is_array($boundaries['P02-AUDIT-007'] ?? null) ? $boundaries['P02-AUDIT-007'] : [];
        if (($audit005['status'] ?? null) !== 'OPEN') {
            $issues[] = 'P02-AUDIT-005 boundary is not OPEN';
        }
        if (($audit006['status'] ?? null) !== 'OPEN / UNCHANGED') {
            $issues[] = 'P02-AUDIT-006 boundary changed';
        }
        if (($audit007['status'] ?? null) !== 'OPEN / EXTERNAL_HUMAN') {
            $issues[] = 'P02-AUDIT-007 boundary is not OPEN / EXTERNAL_HUMAN';
        }
        if (($audit007['g_08_04'] ?? null) !== 'OPEN / EXTERNAL_HUMAN') {
            $issues[] = 'P02-AUDIT-007 G-08-04 is not OPEN / EXTERNAL_HUMAN';
        }
        if (isset($artifact['approved_by'])) {
            $issues[] = 'approved_by identities must not be recorded as if they approved this policy';
        }
        $blob = json_encode($artifact, JSON_THROW_ON_ERROR)."\n".$evidence;
        if (str_contains($blob, 'G-08-04 APPROVED')) {
            $issues[] = 'G-08-04 APPROVED wording';
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
            return ['decisions list missing'];
        }
        $ids = [];
        foreach ($decisions as $decision) {
            if (! is_array($decision) || ! isset($decision['id']) || ! is_string($decision['id'])) {
                $issues[] = 'decision missing id';

                continue;
            }
            $ids[] = $decision['id'];
            foreach (['policy_decision', 'implementation_status', 'implementation_evidence_requirement'] as $field) {
                if (! isset($decision[$field])) {
                    $issues[] = $decision['id'].' missing '.$field;
                }
            }
        }
        if ($ids !== Phase02ProfileClaimPolicyArtifact::DECISION_IDS) {
            $issues[] = 'PC-001 through PC-022 must be present exactly once in order';
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
        if (($credential['option'] ?? null) !== 'B') {
            $issues[] = 'credential option is not B';
        }
        if (($credential['length_characters'] ?? null) !== 16) {
            $issues[] = 'credential length is not 16';
        }
        if (($credential['alphabet'] ?? null) !== Phase02ProfileClaimPolicyArtifact::CROCKFORD_ALPHABET) {
            $issues[] = 'Crockford alphabet altered';
        }
        if (($credential['display_only_format'] ?? null) !== Phase02ProfileClaimPolicyArtifact::DISPLAY_FORMAT) {
            $issues[] = 'display format mismatch';
        }
        if (($credential['ttl_days'] ?? null) !== 30) {
            $issues[] = 'credential TTL is not 30 days';
        }
        if (($credential['successful_uses'] ?? null) !== 1) {
            $issues[] = 'credential successful uses is not 1';
        }
        if (($credential['plaintext_persistence'] ?? null) !== false) {
            $issues[] = 'plaintext persistence is not prohibited';
        }
        $prohibited = $credential['plaintext_prohibited_in'] ?? [];
        if (! is_array($prohibited) || array_values($prohibited) !== Phase02ProfileClaimPolicyArtifact::PLAINTEXT_PROHIBITED_IN) {
            $issues[] = 'plaintext prohibition list altered';
        }
        $comparison = is_array($credential['comparison'] ?? null) ? $credential['comparison'] : [];
        if (($comparison['hyphens_never_part_of_canonical_secret_or_hash_input'] ?? null) !== true) {
            $issues[] = 'hyphens must never enter the canonical hash input';
        }

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
        $expected = [
            'otp_length_digits' => 6,
            'otp_ttl_seconds' => 300,
            'otp_max_verification_attempts' => 5,
            'otp_resend_cooldown_seconds' => 60,
            'otp_requests_per_phone_hmac_per_hour' => 5,
            'otp_requests_per_ip_per_hour' => 20,
            'otp_global_requests_per_hour' => 200,
            'claim_credential_length_characters' => 16,
            'claim_credential_entropy_bits_approximate' => 80,
            'claim_credential_ttl_days' => 30,
            'claim_credential_successful_uses' => 1,
            'credential_failures_per_account_nid_hmac_per_hour' => 5,
            'credential_hourly_budget_cooldown_minutes' => 15,
            'credential_failures_per_account_nid_hmac_per_24h' => 15,
            'claim_ceremony_starts_per_account_per_hour' => 5,
            'claim_ceremony_starts_per_ip_per_hour' => 20,
            'claim_ceremony_starts_per_nid_hmac_per_hour' => 5,
            'profile_claim_otp_recency_at_attach_minutes' => 10,
            'feature_default' => false,
            'production_state' => 'hard-off pending separate PC-020 gate',
        ];
        foreach ($expected as $key => $value) {
            if (($table[$key] ?? null) !== $value) {
                $issues[] = 'numeric_table.'.$key.' drifted';
            }
        }
        $otp = is_array($artifact['otp_step_up'] ?? null) ? $artifact['otp_step_up'] : [];
        if (($otp['recency_at_attach_minutes'] ?? null) !== 10) {
            $issues[] = 'OTP recency is not 10 minutes';
        }
        if (($otp['aal1_session_alone_insufficient'] ?? null) !== true) {
            $issues[] = 'AAL1 session must be insufficient';
        }
        if (($otp['patient_totp_required_in_v1'] ?? null) !== false) {
            $issues[] = 'patient TOTP must not be required in v1';
        }

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
        if (($proof['national_id_plus_otp_alone_insufficient'] ?? null) !== true) {
            $issues[] = 'NID + OTP insufficient flag removed';
        }
        $rejected = $proof['not_independent_additional_proof'] ?? [];
        if (! is_array($rejected) || array_values($rejected) !== Phase02ProfileClaimPolicyArtifact::REJECTED_INDEPENDENT_PROOF) {
            $issues[] = 'rejected independent proof list altered';
        }
        $bundle = $proof['high_confidence_requires_all'] ?? [];
        if (! is_array($bundle) || count($bundle) !== 4) {
            $issues[] = 'high-confidence bundle must have four required factors';
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
        $prohibited = $enumeration['prohibited_client_visible_states'] ?? [];
        if (! is_array($prohibited) || array_values($prohibited) !== Phase02ProfileClaimPolicyArtifact::PROHIBITED_CLIENT_STATES) {
            $issues[] = 'non-enumeration prohibited-response list altered';
        }
        if (($enumeration['generic_pending'] ?? null) !== 'manual_review_required') {
            $issues[] = 'generic pending contract drifted';
        }

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
        if (($legacy['unlinked_without_issued_credential'] ?? null) !== 'MANUAL_REVIEW_ONLY') {
            $issues[] = 'legacy manual-review-only removed';
        }
        if (($legacy['retroactive_credential_generation'] ?? null) !== false) {
            $issues[] = 'retroactive credential generation is not forbidden';
        }
        if (($legacy['automatic_credential_generation'] ?? null) !== false) {
            $issues[] = 'automatic credential generation is not forbidden';
        }
        if (($legacy['external_response'] ?? null) !== 'manual_review_required') {
            $issues[] = 'legacy external response drifted';
        }

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
        if (($ownership['one_user_one_patient_profile'] ?? null) !== true) {
            $issues[] = 'one-user/one-profile policy removed';
        }
        if (($ownership['one_authoritative_profile_one_user'] ?? null) !== true) {
            $issues[] = 'one-profile/one-user policy removed';
        }
        $already = is_array($artifact['eligibility']['already_bound'] ?? null) ? $artifact['eligibility']['already_bound'] : [];
        if (($already['overwrite'] ?? null) !== false) {
            $issues[] = 'already-bound no-overwrite policy removed';
        }
        $dispute = is_array($artifact['dispute'] ?? null) ? $artifact['dispute'] : [];
        if (($dispute['automatic_reassignment'] ?? null) !== false) {
            $issues[] = 'dispute no-automatic-reassignment policy removed';
        }
        $notifications = is_array($artifact['notifications'] ?? null) ? $artifact['notifications'] : [];
        $forbidden = $notifications['must_not_contain'] ?? [];
        if (! is_array($forbidden) || ! in_array('claim_credential', $forbidden, true) || ! in_array('national_id', $forbidden, true)) {
            $issues[] = 'notification privacy policy altered';
        }
        $observability = $artifact['observability_prerequisites_before_pc020'] ?? [];
        if (! is_array($observability) || count($observability) < 3) {
            $issues[] = 'observability prerequisite removed';
        }
        $runbook = is_array($artifact['runbook'] ?? null) ? $artifact['runbook'] : [];
        if (($runbook['dedicated_profile_claim_incident_runbook_required_before_production_enablement'] ?? null) !== true) {
            $issues[] = 'runbook prerequisite removed';
        }
        $kill = is_array($artifact['kill_switch'] ?? null) ? $artifact['kill_switch'] : [];
        if (($kill['disabling_stops_new_claims'] ?? null) !== true) {
            $issues[] = 'kill-switch new-claim stop removed';
        }
        if (($kill['disabling_does_not_automatically_unlink_valid_links'] ?? null) !== true) {
            $issues[] = 'kill-switch preserve-links policy removed';
        }

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
        if (($runtime['default'] ?? null) !== false) {
            $issues[] = 'feature default is not false';
        }
        if (($runtime['production_hard_off'] ?? null) !== true) {
            $issues[] = 'production hard-off requirement removed';
        }
        if (($runtime['production_env_flag_cannot_enable'] ?? null) !== true) {
            $issues[] = 'production env override prohibition removed';
        }
        if (($runtime['this_artifact_does_not_change_runtime'] ?? null) !== true) {
            $issues[] = 'runtime-unchanged declaration removed';
        }
        $table = is_array($artifact['numeric_table'] ?? null) ? $artifact['numeric_table'] : [];
        if (($table['feature_default'] ?? null) !== false) {
            $issues[] = 'numeric feature default is not false';
        }

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
        if (($t46['status'] ?? null) !== 'OPEN') {
            $issues[] = 'T46 OPEN requirement removed';
        }
        if (($t46['this_artifact_does_not_close_t46'] ?? null) !== true) {
            $issues[] = 'T46 non-closure declaration removed';
        }

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
            $issues[] = 'evidence missing decision state';
        }
        if (! str_contains($evidence, 'does not reuse') && ! str_contains($evidence, 'does **not** reuse')) {
            $issues[] = 'evidence must say profile-correction approval is not reused';
        }
        if (! str_contains($evidence, '**P02-AUDIT-005**') || ! str_contains($evidence, '**`OPEN`**')) {
            $issues[] = 'evidence must keep P02-AUDIT-005 OPEN';
        }
        if (preg_match('/P02-AUDIT-005[^\n]{0,80}\bCLOSED\b/', $evidence) === 1
            && preg_match('/does \*\*not\*\* close P02-AUDIT-005/', $evidence) !== 1) {
            $issues[] = 'evidence claims P02-AUDIT-005 CLOSED';
        }
        if (! str_contains($evidence, '**T46**') || ! str_contains($evidence, '**`OPEN`**')) {
            $issues[] = 'evidence must keep T46 OPEN';
        }
        if (! str_contains($evidence, '**G-08-04**') || ! str_contains($evidence, 'EXTERNAL_HUMAN')) {
            $issues[] = 'evidence must keep G-08-04 OPEN / EXTERNAL_HUMAN';
        }

        return $issues;
    }
}
