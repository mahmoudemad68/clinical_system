<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

/**
 * In-memory mutations of the Profile-Claim Policy v1 artifact.
 */
final class Phase02ProfileClaimPolicyMutations
{
    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function credentialLength(array $artifact, int $length): array
    {
        $artifact['claim_credential']['length_characters'] = $length;
        $artifact['numeric_table']['claim_credential_length_characters'] = $length;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function crockfordAlphabet(array $artifact, string $alphabet): array
    {
        $artifact['claim_credential']['alphabet'] = $alphabet;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function credentialTtlDays(array $artifact, int $days): array
    {
        $artifact['claim_credential']['ttl_days'] = $days;
        $artifact['numeric_table']['claim_credential_ttl_days'] = $days;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeNidOtpInsufficient(array $artifact): array
    {
        $artifact['proof']['national_id_plus_otp_alone_insufficient'] = false;
        $rejected = $artifact['proof']['not_independent_additional_proof'] ?? [];
        if (is_array($rejected)) {
            $artifact['proof']['not_independent_additional_proof'] = array_values(array_filter(
                $rejected,
                static fn (mixed $item): bool => $item !== 'national_id_plus_otp_alone',
            ));
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeLegacyManualReviewOnly(array $artifact): array
    {
        $artifact['legacy_profiles']['unlinked_without_issued_credential'] = 'SELF_SERVICE';
        $artifact['legacy_profiles']['retroactive_credential_generation'] = true;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeProductionHardOff(array $artifact): array
    {
        $artifact['current_runtime']['production_hard_off'] = false;
        $artifact['current_runtime']['production_env_flag_cannot_enable'] = false;
        $artifact['numeric_table']['production_state'] = 'env-flag-sufficient';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function featureDefaultTrue(array $artifact): array
    {
        $artifact['current_runtime']['default'] = true;
        $artifact['numeric_table']['feature_default'] = true;
        $artifact['feature_state'] = 'ENABLED';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function closeT46(array $artifact): array
    {
        $artifact['t46'] = 'CLOSED';
        $artifact['threat_register']['T46']['status'] = 'CLOSED';
        $artifact['threat_register']['T46']['this_artifact_does_not_close_t46'] = false;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function closeAudit005(array $artifact): array
    {
        $artifact['p02_audit_005'] = 'CLOSED';
        $artifact['external_audit_boundaries']['P02-AUDIT-005']['status'] = 'CLOSED';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function approveExternalGovernance(array $artifact): array
    {
        $artifact['governance']['product_approval'] = 'APPROVED';
        $artifact['governance']['security_approval'] = 'APPROVED';
        $artifact['governance']['privacy_approval'] = 'APPROVED';
        $artifact['governance']['g_08_04'] = 'APPROVED';
        $artifact['governance']['g_08_04_claimed_approved'] = true;
        $artifact['status'] = 'APPROVED_PRODUCTION_POLICY';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removePlaintextProhibition(array $artifact): array
    {
        $artifact['claim_credential']['plaintext_persistence'] = true;
        $artifact['claim_credential']['plaintext_prohibited_in'] = [];

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function removeNonEnumeration(array $artifact): array
    {
        $artifact['non_enumeration']['prohibited_client_visible_states'] = [];
        $artifact['non_enumeration']['generic_pending'] = 'wrong_claim_code';

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function otpRecencyMinutes(array $artifact, int $minutes): array
    {
        $artifact['otp_step_up']['recency_at_attach_minutes'] = $minutes;
        $artifact['numeric_table']['profile_claim_otp_recency_at_attach_minutes'] = $minutes;

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function attemptLimits(array $artifact, int $hourly, int $daily): array
    {
        $artifact['numeric_table']['credential_failures_per_account_nid_hmac_per_hour'] = $hourly;
        $artifact['numeric_table']['credential_failures_per_account_nid_hmac_per_24h'] = $daily;

        return $artifact;
    }
}
