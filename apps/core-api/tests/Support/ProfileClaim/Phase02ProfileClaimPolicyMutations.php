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
            self::case('evidence_t46_closed', 'evidence_t46_explicitly_closed', $baseline, self::evidenceCloseT46($evidence)),
            self::case('evidence_audit_005_closed', 'evidence_p02_audit_005_explicitly_closed', $baseline, self::evidenceCloseAudit005($evidence)),
            self::case('evidence_governance_approved', 'evidence_governance_approved', $baseline, self::evidenceApproveGovernance($evidence)),
            self::case('evidence_credential_length_10', 'evidence_credential_length_not_16', $baseline, self::evidenceCredentialLength10($evidence)),
            self::case('evidence_plain_hash', 'evidence_storage_not_peppered_hash_only', $baseline, self::evidencePlainHash($evidence)),
            self::case('evidence_legacy_auto_generation', 'evidence_legacy_automatic_credential_generation', $baseline, self::evidenceLegacyAutoGeneration($evidence)),
            self::case('evidence_production_authorized', 'evidence_production_enablement_authorized', $baseline, self::evidenceProductionAuthorized($evidence)),
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
    public static function dropDecision(array $artifact, string $id): array
    {
        $artifact = self::copy($artifact);
        $artifact['decisions'] = array_values(array_filter(
            $artifact['decisions'],
            static fn (mixed $decision): bool => ! is_array($decision) || ($decision['id'] ?? null) !== $id,
        ));

        return $artifact;
    }

    public static function evidenceCloseT46(string $evidence): string
    {
        $replaced = preg_replace('/(\*\*T46\*\*\s*\|\s*\*\*`)OPEN(`\*\*)/', '$1CLOSED$2', $evidence, 1);
        $replaced = is_string($replaced) ? $replaced : $evidence;

        return str_replace('`T46: OPEN`', '`T46: CLOSED`', $replaced);
    }

    public static function evidenceCloseAudit005(string $evidence): string
    {
        $replaced = preg_replace('/(\*\*P02-AUDIT-005\*\*\s*\|\s*\*\*`)OPEN(`\*\*)/', '$1CLOSED$2', $evidence, 1);
        $replaced = is_string($replaced) ? $replaced : $evidence;

        return str_replace('`P02-AUDIT-005: OPEN`', '`P02-AUDIT-005: CLOSED`', $replaced);
    }

    public static function evidenceApproveGovernance(string $evidence): string
    {
        return (string) preg_replace(
            '/(\| Product approval \| `)PENDING_EXTERNAL(`)/',
            '$1APPROVED$2',
            $evidence,
            1,
        );
    }

    public static function evidenceCredentialLength10(string $evidence): string
    {
        return (string) preg_replace('/(\| Length \| \*\*)16(\*\*)/', '${1}10$2', $evidence, 1);
    }

    public static function evidencePlainHash(string $evidence): string
    {
        return str_replace('peppered-hash-only', 'plain-hash-only', $evidence);
    }

    public static function evidenceLegacyAutoGeneration(string $evidence): string
    {
        $evidence = str_replace('**MANUAL_REVIEW_ONLY**', '**SELF_SERVICE**', $evidence);

        return (string) preg_replace(
            '/Automatic or retroactive credential generation\s+is \*\*not\*\* authorized\./',
            'Automatic credential generation is authorized.',
            $evidence,
            1,
        );
    }

    public static function evidenceProductionAuthorized(string $evidence): string
    {
        return (string) preg_replace(
            '/(\| Production enablement \| `)NOT_AUTHORIZED(`)/',
            '$1AUTHORIZED$2',
            $evidence,
            1,
        );
    }
}
