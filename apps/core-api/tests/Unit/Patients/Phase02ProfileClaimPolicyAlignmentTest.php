<?php

declare(strict_types=1);

use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Patients\Enums\PatientStatus;
use Modules\Platform\Services\Features\PlatformFeatures;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyArtifact as Artifact;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyMutations as Mutations;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyValidator;
use Tests\TestCase;

uses(TestCase::class);

describe('P02-AUDIT-005 profile-claim policy artifact', function () {
    it('keeps the SHA-256 companion equal to the hash of the JSON file bytes', function () {
        $computed = Artifact::computedSha256();

        expect($computed)->toHaveLength(64)
            ->and($computed)->toBe(Artifact::PUBLISHED_SHA256)
            ->and(Artifact::recordedSha256())->toBe($computed)
            ->and(Artifact::rawJson())->not->toContain($computed);
    });

    it('records Hybrid Policy v1 identity without claiming production approval', function () {
        $artifact = Artifact::decoded();
        $correction = json_decode(
            (string) file_get_contents(Artifact::repositoryRoot().'/'.Artifact::RELATIVE_CORRECTION_POLICY),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($artifact['title'])->toBe(Artifact::TITLE)
            ->and($artifact['version'])->toBe(Artifact::VERSION)
            ->and($artifact['release_date'])->toBe(Artifact::RELEASE_DATE)
            ->and($artifact['decision'])->toBe(Artifact::DECISION)
            ->and($artifact['decision_state'])->toBe(Artifact::DECISION_STATE)
            ->and($artifact['status'])->toBe(Artifact::DECISION_STATE)
            ->and($artifact['status'])->not->toBe('APPROVED_PRODUCTION_POLICY')
            ->and($artifact['controller'])->toBe('Mahmoud')
            ->and($artifact['policy_namespace'])->toBe('phase02-profile-claim')
            ->and($artifact['distinct_from_profile_correction_policy'])->toBeTrue()
            ->and($correction['policy_namespace'])->toBe('phase02-patient-profile-correction')
            ->and($artifact['policy_namespace'])->not->toBe($correction['policy_namespace'])
            ->and($artifact)->not->toHaveKey('approved_by');
    });

    it('contains PC-001 through PC-022 exactly once', function () {
        $artifact = Artifact::decoded();
        $ids = array_map(
            static fn (array $decision): string => (string) $decision['id'],
            $artifact['decisions'],
        );

        expect($ids)->toBe(Artifact::DECISION_IDS)
            ->and(array_unique($ids))->toHaveCount(22)
            ->and((new Phase02ProfileClaimPolicyValidator)->validate($artifact, Artifact::evidenceRaw()))->toBe([]);
    });

    it('freezes Option B 16-character Crockford credentials and display grouping', function () {
        $artifact = Artifact::decoded();
        $credential = $artifact['claim_credential'];

        expect($credential['option'])->toBe('B')
            ->and($credential['length_characters'])->toBe(16)
            ->and($credential['entropy_bits_approximate'])->toBe(80)
            ->and($credential['alphabet'])->toBe(Artifact::CROCKFORD_ALPHABET)
            ->and($credential['alphabet'])->not->toContain('I')
            ->and($credential['alphabet'])->not->toContain('L')
            ->and($credential['alphabet'])->not->toContain('O')
            ->and($credential['alphabet'])->not->toContain('U')
            ->and($credential['display_only_format'])->toBe(Artifact::DISPLAY_FORMAT)
            ->and($credential['ttl_days'])->toBe(30)
            ->and($credential['successful_uses'])->toBe(1)
            ->and($credential['plaintext_persistence'])->toBeFalse()
            ->and($credential['plaintext_prohibited_in'])->toBe(Artifact::PLAINTEXT_PROHIBITED_IN)
            ->and($credential['comparison']['hyphens_never_part_of_canonical_secret_or_hash_input'])->toBeTrue();
    });

    it('rejects NID+OTP alone and card demographics as independent proof', function () {
        $artifact = Artifact::decoded();

        expect($artifact['proof']['national_id_plus_otp_alone_insufficient'])->toBeTrue()
            ->and($artifact['proof']['not_independent_additional_proof'])->toBe(Artifact::REJECTED_INDEPENDENT_PROOF)
            ->and($artifact['proof']['high_confidence_requires_all'])->toHaveCount(4)
            ->and($artifact['adr_0011']['relationship'])->toBe('extends_rather_than_weakens');
    });

    it('keeps legacy unlinked profiles manual-review-only without retroactive credentials', function () {
        $artifact = Artifact::decoded();

        expect($artifact['legacy_profiles']['unlinked_without_issued_credential'])->toBe('MANUAL_REVIEW_ONLY')
            ->and($artifact['legacy_profiles']['external_response'])->toBe('manual_review_required')
            ->and($artifact['legacy_profiles']['retroactive_credential_generation'])->toBeFalse()
            ->and($artifact['legacy_profiles']['automatic_credential_generation'])->toBeFalse();
    });

    it('freezes the numeric table, non-enumeration list, ownership, and kill-switch semantics', function () {
        $artifact = Artifact::decoded();
        $table = $artifact['numeric_table'];

        expect($table['otp_length_digits'])->toBe(6)
            ->and($table['otp_ttl_seconds'])->toBe(300)
            ->and($table['otp_max_verification_attempts'])->toBe(5)
            ->and($table['otp_resend_cooldown_seconds'])->toBe(60)
            ->and($table['otp_requests_per_phone_hmac_per_hour'])->toBe(5)
            ->and($table['otp_requests_per_ip_per_hour'])->toBe(20)
            ->and($table['otp_global_requests_per_hour'])->toBe(200)
            ->and($table['credential_failures_per_account_nid_hmac_per_hour'])->toBe(5)
            ->and($table['credential_hourly_budget_cooldown_minutes'])->toBe(15)
            ->and($table['credential_failures_per_account_nid_hmac_per_24h'])->toBe(15)
            ->and($table['claim_ceremony_starts_per_account_per_hour'])->toBe(5)
            ->and($table['claim_ceremony_starts_per_ip_per_hour'])->toBe(20)
            ->and($table['claim_ceremony_starts_per_nid_hmac_per_hour'])->toBe(5)
            ->and($table['profile_claim_otp_recency_at_attach_minutes'])->toBe(10)
            ->and($table['feature_default'])->toBeFalse()
            ->and($artifact['non_enumeration']['prohibited_client_visible_states'])->toBe(Artifact::PROHIBITED_CLIENT_STATES)
            ->and($artifact['ownership']['one_user_one_patient_profile'])->toBeTrue()
            ->and($artifact['ownership']['user_id_unique_index'])->toBe('patient_profiles_user_id_unique')
            ->and($artifact['eligibility']['already_bound']['overwrite'])->toBeFalse()
            ->and($artifact['dispute']['automatic_reassignment'])->toBeFalse()
            ->and($artifact['dispute']['freeze_status_target'])->toBe(PatientStatus::Disputed->value)
            ->and($artifact['notifications']['must_not_contain'])->toContain('claim_credential')
            ->and($artifact['observability_prerequisites_before_pc020'])->toContain('metrics')
            ->and($artifact['runbook']['this_task_edits_runbook'])->toBeFalse()
            ->and($artifact['kill_switch']['disabling_stops_new_claims'])->toBeTrue()
            ->and($artifact['kill_switch']['disabling_does_not_automatically_unlink_valid_links'])->toBeTrue();
    });

    it('keeps G-08-04 OPEN and does not invent external approvals', function () {
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        $json = Artifact::rawJson();

        expect($artifact['governance']['product_approval'])->toBe('PENDING_EXTERNAL')
            ->and($artifact['governance']['security_approval'])->toBe('PENDING_EXTERNAL')
            ->and($artifact['governance']['privacy_approval'])->toBe('PENDING_EXTERNAL')
            ->and($artifact['governance']['support_operations_approval'])->toBe('PENDING_EXTERNAL')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-007']['g_08_04'])->toBe('OPEN / EXTERNAL_HUMAN')
            ->and($json)->not->toContain('G-08-04 APPROVED')
            ->and($evidence)->toContain('**G-08-04**')
            ->and($evidence)->toContain('EXTERNAL_HUMAN')
            ->and($evidence)->toContain('does **not** reuse');

        foreach ([$json, $evidence] as $text) {
            preg_match_all('/G-08-04[^\n]{0,240}/i', $text, $matches);
            expect($matches[0])->not->toBeEmpty();
            foreach ($matches[0] as $window) {
                if (preg_match('/\bOPEN\b/', $window) === 1) {
                    continue;
                }
                if (preg_match('/\bnot\b.{0,80}\b(APPROVED|ACCEPTED|CLOSED)\b/i', $window) === 1) {
                    continue;
                }
                expect($window)->not->toMatch('/\b(APPROVED|ACCEPTED|CLOSED)\b/');
            }
        }
    });

    it('keeps SF-001 untouched and P02-AUDIT-005 OPEN', function () {
        $artifact = Artifact::decoded();
        $sf001 = json_decode(
            (string) file_get_contents(Artifact::repositoryRoot().'/'.Artifact::RELATIVE_SF001),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($sf001['exception_id'])->toBe('SF-001')
            ->and($sf001['package'])->toBe('extract-zip')
            ->and($sf001['affected_version'])->toBe('2.0.1')
            ->and($sf001['independent_acceptance_status'])->toBe('PENDING_INDEPENDENT_ACCEPTANCE')
            ->and($sf001['promotion_allowed'])->toBeFalse()
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-006']['status'])->toBe('OPEN / UNCHANGED')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-005']['status'])->toBe('OPEN')
            ->and($artifact['p02_audit_005'])->toBe('OPEN')
            ->and($artifact['t46'])->toBe('OPEN');
    });
});

describe('P02-AUDIT-005 fail-closed runtime boundary', function () {
    it('keeps FEATURE_IDENTITY_PROFILE_CLAIM disabled and production hard-off in source', function () {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse()
            ->and((bool) config('identity.profile_claim_enabled', true))->toBeFalse();

        $features = (string) file_get_contents(base_path('Modules/Platform/app/Services/Features/PlatformFeatures.php'));
        $identity = (string) file_get_contents(base_path('config/identity.php'));
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));
        $dockerfile = (string) file_get_contents(Artifact::repositoryRoot().'/infra/docker/core-api.Dockerfile');
        $link = (string) file_get_contents(base_path('Modules/Identity/app/Services/LinkVerifiedPatientAccount.php'));

        expect($features)
            ->toContain('IDENTITY_PROFILE_CLAIM')
            ->toContain("config('app.env') === 'production'")
            ->and($identity)->toContain("env('FEATURE_IDENTITY_PROFILE_CLAIM', false)")
            ->and($phpunit)->toContain('FEATURE_IDENTITY_PROFILE_CLAIM')
            ->and($phpunit)->toMatch('/FEATURE_IDENTITY_PROFILE_CLAIM"\s+value="false"/')
            ->and($dockerfile)->toContain('APP_ENV=production')
            ->and($link)->not->toContain('attachAccount')
            ->and($link)->toContain('manual_review_required')
            ->and($link)->toContain('FeatureUnavailable');
    });

    it('cannot enable the current production path merely by setting the config flag', function () {
        config(['identity.profile_claim_enabled' => true, 'app.env' => 'production']);
        try {
            expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();
        } finally {
            config(['identity.profile_claim_enabled' => false, 'app.env' => 'testing']);
        }

        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();
    });

    it('keeps T46 OPEN in the live threat register', function () {
        $register = (string) file_get_contents(Artifact::repositoryRoot().'/'.Artifact::RELATIVE_THREAT_MODEL);

        expect($register)
            ->toContain('### P02-T46 — Profile-claim enablement (out of scope)')
            ->toContain('| Status | **OPEN** |')
            ->toContain('P02-AUDIT-005')
            ->and(preg_match(
                '/### P02-T46 — Profile-claim enablement \(out of scope\).*?^\| Status \| \*\*OPEN\*\* \|/ms',
                $register,
            ))->toBe(1);
    });

    it('does not require the future ceremony class surface to exist beyond the dark stub', function () {
        expect(class_exists(LinkVerifiedPatientAccount::class))->toBeTrue()
            ->and(PatientStatus::Disputed->value)->toBe('disputed')
            ->and(PatientStatus::from('disputed')->isClaimEligible())->toBeFalse();
    });
});

describe('P02-AUDIT-005 policy mutation detection', function () {
    it('fails representative Policy v1 mutations', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $baseline = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        expect($validator->validate($baseline, $evidence))->toBe([]);

        $mutations = [
            'credential length 16 to 10' => Mutations::credentialLength($baseline, 10),
            'Crockford alphabet altered' => Mutations::crockfordAlphabet($baseline, '0123456789ABCDEFGHIJKLMNOPQRSTUV'),
            'TTL 30 days changed' => Mutations::credentialTtlDays($baseline, 7),
            'NID + OTP insufficient removed' => Mutations::removeNidOtpInsufficient($baseline),
            'legacy manual-review-only removed' => Mutations::removeLegacyManualReviewOnly($baseline),
            'production hard-off removed' => Mutations::removeProductionHardOff($baseline),
            'feature default false to true' => Mutations::featureDefaultTrue($baseline),
            'T46 OPEN to CLOSED' => Mutations::closeT46($baseline),
            'P02-AUDIT-005 OPEN to CLOSED' => Mutations::closeAudit005($baseline),
            'external governance pending to approved' => Mutations::approveExternalGovernance($baseline),
            'credential plaintext prohibition removed' => Mutations::removePlaintextProhibition($baseline),
            'non-enumeration requirement removed' => Mutations::removeNonEnumeration($baseline),
            'OTP recency 10 minutes changed' => Mutations::otpRecencyMinutes($baseline, 30),
            'attempt limits changed' => Mutations::attemptLimits($baseline, 99, 99),
        ];

        foreach ($mutations as $label => $mutated) {
            $issues = $validator->validate($mutated, $evidence);
            expect($issues === [])->toBeFalse($label);
        }
    });
});
