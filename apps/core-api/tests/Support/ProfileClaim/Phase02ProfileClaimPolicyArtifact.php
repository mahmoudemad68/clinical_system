<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

use JsonException;
use RuntimeException;

/**
 * Loader and independently frozen expectations for Profile-Claim Policy v1.0.0.
 * This is an evidence oracle, not a live ceremony implementation.
 */
final class Phase02ProfileClaimPolicyArtifact
{
    public const VERSION = 'v1.0.0-phase02';

    public const TITLE = 'Phase 02 Profile Claim Policy';

    public const RELEASE_DATE = '2026-09-28';

    public const DECISION = 'HYBRID_PROFILE_CLAIM';

    public const DECISION_STATE = 'CONTROLLER_POLICY_V1_FROZEN';

    public const CLASSIFICATION = 'POLICY_DECISIONS_RECORDED_AWAITING_EXTERNAL_GOVERNANCE_AND_IMPLEMENTATION';

    public const RELATIVE_JSON = 'docs/evidence/phase-02/reference-data/phase02-profile-claim-policy.v1.0.0-phase02.json';

    public const RELATIVE_SHA256 = 'docs/evidence/phase-02/reference-data/phase02-profile-claim-policy.v1.0.0-phase02.sha256';

    public const RELATIVE_EVIDENCE = 'docs/evidence/phase-02/p02-audit-005-profile-claim-policy.md';

    public const RELATIVE_THREAT_MODEL = 'docs/threat-models/phase-02-onboarding.md';

    public const RELATIVE_SF001 = 'infra/security/exceptions/SF-001.json';

    public const RELATIVE_CORRECTION_POLICY = 'docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.2-phase02.json';

    public const CROCKFORD_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const CROCKFORD_ENCODING = 'Crockford Base32';

    public const EXCLUDED_CHARACTERS = 'ILOU';

    public const DISPLAY_FORMAT = 'XXXX-XXXX-XXXX-XXXX';

    public const GENERATION = 'cryptographically_secure_random';

    public const STORAGE = 'peppered_hash_only';

    public const CANONICAL_FORM = '16 characters with no separators';

    public const SHOW_PRINT_SEND = 'once_at_issuance';

    public const PUBLISHED_SHA256 = '7a13fbbd1a36bb789f53d22f30335a926ced3160d720962c3d52405972ca55e5';

    public const SUPERSEDED_UNMERGED_SHA256 = '3f704817002c0b169bb80cf0026dad0a78e0da950919d8fb52c64de5b42e4396';

    /** @var list<string> */
    public const DECISION_IDS = [
        'PC-001', 'PC-002', 'PC-003', 'PC-004', 'PC-005', 'PC-006',
        'PC-007', 'PC-008', 'PC-009', 'PC-010', 'PC-011', 'PC-012',
        'PC-013', 'PC-014', 'PC-015', 'PC-016', 'PC-017', 'PC-018',
        'PC-019', 'PC-020', 'PC-021', 'PC-022',
    ];

    /** @var list<string> */
    public const HIGH_CONFIDENCE_BUNDLE = [
        'canonical_national_id_hmac_matches_exactly_one_active_unlinked_patient_profile',
        'claimant_account_has_non_empty_bound_national_id_identity_that_matches_the_target_profile',
        'fresh_purpose_bound_profile_claim_otp_consumed_on_already_verified_phone',
        'valid_clinic_issued_claim_credential_for_that_exact_patient_profile',
    ];

    /** @var list<string> */
    public const PROHIBITED_CLIENT_STATES = [
        'wrong_claim_code',
        'profile_exists',
        'profile_already_linked',
        'claim_code_expired',
        'national_id_not_found',
    ];

    /** @var list<string> */
    public const CLIENT_MUST_NOT_LEARN = [
        'nid_exists',
        'nid_does_not_exist',
        'profile_is_linked',
        'profile_is_unlinked',
        'credential_exists',
        'credential_does_not_exist',
        'credential_is_wrong',
        'credential_is_expired',
        'credential_is_reused',
        'profile_is_disputed',
        'profile_is_restricted',
        'profile_is_archived',
        'rate_limit_or_risk_rule_caused_review',
    ];

    /** @var list<string> */
    public const REJECTED_INDEPENDENT_PROOF = [
        'date_of_birth',
        'full_name',
        'gender',
        'blood_type',
        'other_demographic_data_printed_on_or_derivable_from_national_id',
        'national_id_plus_otp_alone',
        'patient_verification_document_upload_as_v1_high_confidence_path',
    ];

    /** @var list<string> */
    public const PLAINTEXT_PROHIBITED_IN = [
        'persistence',
        'logs',
        'urls',
        'audit_metadata',
        'events',
        'metrics',
        'analytics',
        'telemetry',
    ];

    /**
     * JSON prerequisite order is part of the QA-008 oracle. Markdown copies of
     * this collection are compared as sets.
     *
     * @var list<string>
     */
    public const PC020_PREREQUISITES = [
        'policy_recorded',
        'ceremony_implementation_complete',
        'tests_complete',
        'observability_complete',
        'monitored_rollout_or_cohort_controls_ready',
        'verified_kill_switch',
        'external_governance_approvals',
        'independent_engineering_qa',
        'applicable_independent_human_gates',
    ];

    /** @var list<string> */
    public const OBSERVABILITY_PREREQUISITES = [
        'metrics',
        'alerts',
        'dashboard_visibility',
        'monitored_rollout_or_cohorts',
    ];

    /**
     * Independently frozen PC-001..PC-022 expectations. Not derived from the artifact under test.
     *
     * @var array<string, array{title: string, implementation_status: string, blocks_production_enablement: bool, policy_decision: string}>
     */
    public const DECISION_EXPECTATIONS = [
        'PC-001' => [
            'title' => 'claimant_eligibility',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Only AccountType Patient with AccountStatus Active may start a Profile Claim ceremony.',
        ],
        'PC-002' => [
            'title' => 'high_confidence_proof_bundle',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'High-confidence self-service requires all four factors: (1) canonical National-ID HMAC resolves exactly one Active unlinked target profile; (2) the claimant account has a non-empty bound National-ID identity representation and that bound identity matches the target profile identity; (3) a fresh consumed profile_claim OTP exists on the claimant\'s already-verified phone; (4) a valid clinic-issued, profile-bound, single-use claim credential exists for that exact profile. A missing account-bound National ID is not high-confidence and routes to internal Manual Review with the generic client contract. matchesBoundIdentity treating a null stored HMAC as a match is not the approved high-confidence rule. DOB, full name, gender, blood type, other NID-derived demographics, National ID plus OTP alone, and patient verification-document upload are not independent additional proof.',
        ],
        'PC-003' => [
            'title' => 'assurance_states',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Successful high-confidence self-service bind records ial2_verified_link. Manual-review pending records ial2_proof_pending. Operator bind after Separation-of-Duties review records ial3_operator.',
        ],
        'PC-004' => [
            'title' => 'fresh_otp_required',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'A new profile_claim OTP on the already-verified phone is required. A pre-existing AAL1 session alone is insufficient.',
        ],
        'PC-005' => [
            'title' => 'account_status',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Only AccountStatus Active. No self-service claim for pending_phone, suspended, locked, or closed. Do not expose sensitive eligibility information.',
        ],
        'PC-006' => [
            'title' => 'self_service_profile_eligibility',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Self-service requires profile status Active and user_id IS NULL. Anything else routes to internal Manual Review with the generic external pending contract.',
        ],
        'PC-007' => [
            'title' => 'already_bound_profile',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'No self-reclaim, no overwrite, no automatic reassignment. Existing user_id remains unchanged. Route internally to Manual Review where appropriate. Do not disclose that the profile is already bound.',
        ],
        'PC-008' => [
            'title' => 'one_user_one_profile',
            'implementation_status' => 'ALREADY_ENFORCED',
            'blocks_production_enablement' => false,
            'policy_decision' => 'One user maps to one patient profile. Preserve patient_profiles_user_id_unique. No v1 change to 1:1 ownership.',
        ],
        'PC-009' => [
            'title' => 'one_profile_one_user',
            'implementation_status' => 'ALREADY_ENFORCED',
            'blocks_production_enablement' => false,
            'policy_decision' => 'One authoritative patient profile maps to one user. Preserve authoritative National-ID-HMAC uniqueness where status <> merged. No proxy or shared-profile model in Policy v1.',
        ],
        'PC-010' => [
            'title' => 'credential_attempt_budget',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Failed credential attempts are budgeted by account plus NID-HMAC: 5 per hour then 15-minute cooldown; 15 per 24 hours then internal Manual Review lock. External response remains generic. Do not expose attempt count or credential existence, correctness, expiry, or reuse.',
        ],
        'PC-011' => [
            'title' => 'rate_limits',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Ceremony starts: 5 per account per hour, 20 per IP per hour, 5 per NID-HMAC per hour. OTP: 6 digits, 300 second TTL, 5 verification attempts, 60 second resend, 5 per phone-HMAC per hour, 20 per IP per hour, 200 global per hour.',
        ],
        'PC-012' => [
            'title' => 'non_enumeration',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'The client must not learn NID existence, link state, credential state, disputed/restricted/archived state, or that a rate-limit or risk rule caused review. Do not introduce wrong_claim_code, profile_exists, profile_already_linked, claim_code_expired, or national_id_not_found. Use manual_review_required or existing hidden NOT_FOUND.',
        ],
        'PC-013' => [
            'title' => 'otp_is_part_of_attach_ceremony',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'profile_claim OTP is part of the future attach ceremony: issue, verify, consume, then attach only when the remaining PC-002 bundle is valid. There must be no successful profile_claim OTP path that means a completed claim without the credential and bind.',
        ],
        'PC-014' => [
            'title' => 'hybrid_routing',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'All high-confidence requirements satisfied: eligible for future self-service attach. Anything missing, invalid, expired, reused, ambiguous, rate-limited, risk-flagged, or ineligible: Manual Review. Client response remains generic.',
        ],
        'PC-015' => [
            'title' => 'dispute_and_wrong_bind',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Wrong bind or ownership dispute freezes the affected profile using status disputed, which already exists on patient_profiles. Authorized operator review is required. No automatic reassignment or transfer.',
        ],
        'PC-016' => [
            'title' => 'notifications',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Notify on successful profile bind, failed-proof lockout, and dispute freeze. Notification content must not contain National ID, phone number, claim credential, or other secret proof material.',
        ],
        'PC-017' => [
            'title' => 'observability_before_enablement',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'PC-017 is a production-enablement blocker. Metrics, alerts, dashboard visibility, and monitored rollout or cohorts are prerequisites before PC-020.',
        ],
        'PC-018' => [
            'title' => 'incident_runbook',
            'implementation_status' => 'EVIDENCE_REQUIRED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'A dedicated Profile Claim incident/runbook is required before production enablement. The stale statement that the patient identity registry is unavailable must eventually be corrected. This task does not edit the runbook.',
        ],
        'PC-019' => [
            'title' => 'kill_switch_preserves_valid_links',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'PC-019 is a production-enablement blocker. A verified kill switch that stops new claims without unlinking valid existing links is required before production enablement. Dispute and recovery are separate.',
        ],
        'PC-020' => [
            'title' => 'production_remains_hard_off',
            'implementation_status' => 'ALREADY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'Production remains hard-off. This artifact is not permission to enable production, set FEATURE_IDENTITY_PROFILE_CLAIM=true, or remove APP_ENV=production hard-off. The env flag alone cannot enable production. Enablement requires a later separately authorized PC-020 change after all of: policy recorded; ceremony implementation complete; tests complete; observability complete; monitored rollout/cohort controls ready; verified kill switch; external governance approvals; independent engineering QA; and any applicable independent-human gates.',
        ],
        'PC-021' => [
            'title' => 'otp_recency_step_up',
            'implementation_status' => 'NOT_IMPLEMENTED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'The profile_claim OTP must have been successfully consumed within 10 minutes of attach. AAL1 session alone is insufficient. Patient TOTP is not required by Policy v1.',
        ],
        'PC-022' => [
            'title' => 'post_enablement_env_kill_switch',
            'implementation_status' => 'PARTIALLY_ENFORCED',
            'blocks_production_enablement' => true,
            'policy_decision' => 'PC-022 is a production-enablement blocker until the future post-hard-off env/config kill-switch behavior is demonstrated. After the separately authorized PC-020 change, setting FEATURE_IDENTITY_PROFILE_CLAIM=false must prevent new claims without a code deployment; a config or process reload may still be required. Current production is already hard-off. Do not alter current behavior in this task.',
        ],
    ];

    /** @var array<string, int|string|bool> */
    public const NUMERIC_TABLE = [
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
        'credential_24h_lock_action' => 'internal_manual_review_lock',
        'claim_ceremony_starts_per_account_per_hour' => 5,
        'claim_ceremony_starts_per_ip_per_hour' => 20,
        'claim_ceremony_starts_per_nid_hmac_per_hour' => 5,
        'profile_claim_otp_recency_at_attach_minutes' => 10,
        'feature_default' => false,
        'production_state' => 'hard-off pending separate PC-020 gate',
    ];

    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 5);
    }

    public static function jsonPath(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_JSON;
    }

    public static function sha256Path(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_SHA256;
    }

    public static function evidencePath(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_EVIDENCE;
    }

    public static function threatModelPath(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_THREAT_MODEL;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decoded(): array
    {
        return self::decodeObject(self::rawJson(), 'Profile-claim policy artifact');
    }

    public static function rawJson(): string
    {
        return self::readFile(self::jsonPath(), 'Missing profile-claim policy artifact: ');
    }

    public static function evidenceRaw(): string
    {
        return self::readFile(self::evidencePath(), 'Missing profile-claim evidence document: ');
    }

    public static function threatModelRaw(): string
    {
        return self::readFile(self::threatModelPath(), 'Missing Phase 02 threat model: ');
    }

    public static function recordedSha256(): string
    {
        return trim(self::readFile(self::sha256Path(), 'Missing profile-claim policy SHA-256 companion: '));
    }

    public static function computedSha256(): string
    {
        return hash('sha256', self::rawJson());
    }

    public static function t46ThreatSection(?string $markdown = null): string
    {
        $markdown ??= self::threatModelRaw();
        if (preg_match('/^### P02-T46\b[^\n]*\n.*?(?=^### |\z)/ms', $markdown, $matches) !== 1) {
            return '';
        }

        return $matches[0];
    }

    public static function t46ThreatStatus(?string $section = null): ?string
    {
        $section ??= self::t46ThreatSection();
        if (preg_match('/^\| Status \| \*\*([A-Z_]+)\*\* \|/m', $section, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, array<string, mixed>>
     */
    public static function decisionsById(array $artifact): array
    {
        $decisions = $artifact['decisions'] ?? null;
        if (! is_array($decisions)) {
            throw new RuntimeException('Policy decisions list is missing.');
        }

        $byId = [];
        foreach ($decisions as $decision) {
            if (! is_array($decision) || ! isset($decision['id']) || ! is_string($decision['id'])) {
                throw new RuntimeException('Policy decision is missing an id.');
            }
            if (isset($byId[$decision['id']])) {
                throw new RuntimeException('Duplicate policy decision id: '.$decision['id']);
            }
            $byId[$decision['id']] = $decision;
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    public static function stringList(array $artifact, string $path): array
    {
        $cursor = $artifact;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                throw new RuntimeException('Missing policy path: '.$path);
            }
            $cursor = $cursor[$segment];
        }

        if (! is_array($cursor)) {
            throw new RuntimeException('Policy path is not a list: '.$path);
        }

        $values = [];
        foreach ($cursor as $item) {
            if (! is_string($item)) {
                throw new RuntimeException('Policy path contains a non-string: '.$path);
            }
            $values[] = $item;
        }

        return array_values($values);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label.' is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must decode to an object.');
        }

        return $decoded;
    }

    private static function readFile(string $path, string $missingPrefix): string
    {
        if (! is_file($path)) {
            throw new RuntimeException($missingPrefix.$path);
        }

        return (string) file_get_contents($path);
    }
}
