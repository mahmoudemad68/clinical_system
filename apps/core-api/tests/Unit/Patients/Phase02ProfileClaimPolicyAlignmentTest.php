<?php

declare(strict_types=1);

use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Patients\Enums\PatientStatus;
use Modules\Platform\Services\Features\PlatformFeatures;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyArtifact as Artifact;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyExpected;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyExpectedMarkdown;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyMutations as Mutations;
use Tests\Support\ProfileClaim\Phase02ProfileClaimPolicyValidator;
use Tests\TestCase;

uses(TestCase::class);

describe('P02-AUDIT-005 profile-claim policy artifact', function () {
    it('keeps the SHA-256 companion equal to the hash of the JSON file bytes', function () {
        $computed = Artifact::computedSha256();

        expect($computed)->toHaveLength(64)
            ->and($computed)->toBe(Artifact::PUBLISHED_SHA256)
            ->and($computed)->not->toBe(Artifact::SUPERSEDED_UNMERGED_SHA256)
            ->and(Artifact::recordedSha256())->toBe($computed)
            ->and(Artifact::rawJson())->not->toContain($computed)
            ->and(Artifact::evidenceRaw())->toContain($computed)
            ->and(Artifact::evidenceRaw())->toContain(Artifact::SUPERSEDED_UNMERGED_SHA256);
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
            ->and($artifact['classification'])->toBe(Artifact::CLASSIFICATION)
            ->and($artifact['controller'])->toBe('Mahmoud')
            ->and($artifact['policy_namespace'])->toBe('phase02-profile-claim')
            ->and($artifact['production_enablement'])->toBe('NOT_AUTHORIZED')
            ->and($artifact['feature_state'])->toBe('DISABLED')
            ->and($artifact['distinct_from_profile_correction_policy'])->toBeTrue()
            ->and($correction['policy_namespace'])->toBe('phase02-patient-profile-correction')
            ->and($artifact['policy_namespace'])->not->toBe($correction['policy_namespace'])
            ->and($artifact)->not->toHaveKey('approved_by');
    });

    it('contains PC-001 through PC-022 exactly once and matches independently frozen expectations', function () {
        $artifact = Artifact::decoded();
        $ids = array_map(
            static fn (array $decision): string => (string) $decision['id'],
            $artifact['decisions'],
        );

        expect($ids)->toBe(Artifact::DECISION_IDS)
            ->and(array_unique($ids))->toHaveCount(22)
            ->and($artifact)->toEqual(Phase02ProfileClaimPolicyExpected::artifact())
            ->and((new Phase02ProfileClaimPolicyValidator)->validate($artifact, Artifact::evidenceRaw()))->toBe([]);
    });

    it('does not generate the expected semantic map from the artifact under test', function () {
        $path = (new ReflectionClass(Phase02ProfileClaimPolicyExpected::class))->getFileName();

        expect($path)->toBeString();

        $source = (string) file_get_contents((string) $path);

        expect($source)->toContain('Pest test fixture, not governance evidence')
            ->and($source)->not->toContain('RELATIVE_JSON')
            ->and($source)->not->toContain('jsonPath()')
            ->and($source)->not->toContain('file_get_contents')
            ->and($source)->not->toContain(Artifact::RELATIVE_JSON);
    });

    it('does not generate expected markdown from the evidence file at runtime', function () {
        $path = (new ReflectionClass(Phase02ProfileClaimPolicyExpectedMarkdown::class))->getFileName();

        expect($path)->toBeString();

        $source = (string) file_get_contents((string) $path);

        expect($source)->toContain('TEST ORACLE — NOT GOVERNANCE EVIDENCE')
            ->and($source)->not->toContain('RELATIVE_EVIDENCE')
            ->and($source)->not->toContain('evidencePath')
            ->and($source)->not->toContain('evidenceRaw')
            ->and($source)->not->toContain('file_get_contents')
            ->and($source)->not->toContain(Artifact::RELATIVE_EVIDENCE)
            ->and($source)->not->toContain('jsonPath()')
            ->and(Phase02ProfileClaimPolicyExpectedMarkdown::frozen())
            ->toBe(Phase02ProfileClaimPolicyExpectedMarkdown::canonicalize(Artifact::evidenceRaw()));
    });

    it('compares canonical markdown deterministically without JSON SHA', function () {
        $crlf = str_replace("\n", "\r\n", Artifact::evidenceRaw());
        $again = Phase02ProfileClaimPolicyExpectedMarkdown::canonicalize($crlf);

        expect(Phase02ProfileClaimPolicyExpectedMarkdown::canonicalize($crlf))
            ->toBe(Phase02ProfileClaimPolicyExpectedMarkdown::frozen())
            ->and($again)->toBe(Phase02ProfileClaimPolicyExpectedMarkdown::canonicalize($again))
            ->and((new Phase02ProfileClaimPolicyValidator)->validate(Artifact::decoded(), $crlf))->toBe([]);
    });

    it('validates each PC decision against independently frozen identity, status, blocker flag, and policy text', function (string $id) {
        $artifact = Artifact::decoded();
        $decision = Artifact::decisionsById($artifact)[$id];
        $expected = Artifact::DECISION_EXPECTATIONS[$id];

        expect($decision['id'])->toBe($id)
            ->and($decision['title'])->toBe($expected['title'])
            ->and($decision['implementation_status'])->toBe($expected['implementation_status'])
            ->and($decision['blocks_production_enablement'])->toBe($expected['blocks_production_enablement'])
            ->and($decision['policy_decision'])->toBe($expected['policy_decision']);
    })->with(Artifact::DECISION_IDS);

    it('freezes Option B 16-character Crockford credentials including generation, alphabet, and storage', function () {
        $artifact = Artifact::decoded();
        $credential = $artifact['claim_credential'];

        expect($credential['option'])->toBe('B')
            ->and($credential['encoding'])->toBe(Artifact::CROCKFORD_ENCODING)
            ->and($credential['length_characters'])->toBe(16)
            ->and($credential['entropy_bits_approximate'])->toBe(80)
            ->and($credential['alphabet'])->toBe(Artifact::CROCKFORD_ALPHABET)
            ->and($credential['excluded_characters'])->toBe(Artifact::EXCLUDED_CHARACTERS)
            ->and($credential['alphabet'])->not->toContain('I')
            ->and($credential['alphabet'])->not->toContain('L')
            ->and($credential['alphabet'])->not->toContain('O')
            ->and($credential['alphabet'])->not->toContain('U')
            ->and($credential['display_only_format'])->toBe(Artifact::DISPLAY_FORMAT)
            ->and($credential['canonical_form'])->toBe(Artifact::CANONICAL_FORM)
            ->and($credential['generation'])->toBe(Artifact::GENERATION)
            ->and($credential['ttl_days'])->toBe(30)
            ->and($credential['successful_uses'])->toBe(1)
            ->and($credential['show_print_send'])->toBe(Artifact::SHOW_PRINT_SEND)
            ->and($credential['storage'])->toBe(Artifact::STORAGE)
            ->and($credential['plaintext_persistence'])->toBeFalse()
            ->and($credential['plaintext_prohibited_in'])->toBe(Artifact::PLAINTEXT_PROHIBITED_IN)
            ->and($credential['comparison']['strip_display_separators'])->toBeTrue()
            ->and($credential['comparison']['canonicalize_case'])->toBeTrue()
            ->and($credential['comparison']['hyphens_never_part_of_canonical_secret_or_hash_input'])->toBeTrue();
    });

    it('requires the full PC-002 four-factor bundle including a non-empty bound account National ID', function () {
        $artifact = Artifact::decoded();
        $proof = $artifact['proof'];

        expect($proof['high_confidence_requires_all'])->toBe(Artifact::HIGH_CONFIDENCE_BUNDLE)
            ->and($proof['high_confidence_requires_all'])->toHaveCount(4)
            ->and($proof['account_bound_national_id_required'])->toBeTrue()
            ->and($proof['missing_account_bound_national_id_is_not_high_confidence'])->toBeTrue()
            ->and($proof['matches_bound_identity_null_stored_hmac_is_not_high_confidence'])->toBeTrue()
            ->and($proof['missing_account_bound_national_id_routes_to'])->toBe('internal_manual_review_generic_client_contract')
            ->and($proof['national_id_plus_otp_alone_insufficient'])->toBeTrue()
            ->and($proof['not_independent_additional_proof'])->toBe(Artifact::REJECTED_INDEPENDENT_PROOF)
            ->and($artifact['adr_0011']['relationship'])->toBe('extends_rather_than_weakens');
    });

    it('keeps legacy unlinked profiles manual-review-only without automatic or retroactive credentials', function () {
        $artifact = Artifact::decoded();

        expect($artifact['legacy_profiles']['unlinked_without_issued_credential'])->toBe('MANUAL_REVIEW_ONLY')
            ->and($artifact['legacy_profiles']['external_response'])->toBe('manual_review_required')
            ->and($artifact['legacy_profiles']['retroactive_credential_generation'])->toBeFalse()
            ->and($artifact['legacy_profiles']['automatic_credential_generation'])->toBeFalse();
    });

    it('freezes every numeric Policy v1 value independently of the artifact loader path', function () {
        $table = Artifact::decoded()['numeric_table'];

        foreach (Artifact::NUMERIC_TABLE as $key => $value) {
            expect($table[$key])->toBe($value);
        }
    });

    it('freezes the complete non-enumeration client_must_not_learn set and hidden denial', function () {
        $artifact = Artifact::decoded();
        $enumeration = $artifact['non_enumeration'];

        expect($enumeration['client_must_not_learn'])->toBe(Artifact::CLIENT_MUST_NOT_LEARN)
            ->and($enumeration['prohibited_client_visible_states'])->toBe(Artifact::PROHIBITED_CLIENT_STATES)
            ->and($enumeration['generic_pending'])->toBe('manual_review_required')
            ->and($enumeration['hidden_denial'])->toBe('NOT_FOUND');
    });

    it('forbids reclaiming, overwriting, reassigning, or transferring already-bound profiles', function () {
        $artifact = Artifact::decoded();
        $already = $artifact['eligibility']['already_bound'];

        expect($already['self_reclaim'])->toBeFalse()
            ->and($already['overwrite'])->toBeFalse()
            ->and($already['automatic_reassignment'])->toBeFalse()
            ->and($already['automatic_transfer_to_another_user'])->toBeFalse()
            ->and($already['existing_user_id_unchanged'])->toBeTrue()
            ->and($already['disclose_already_bound'])->toBeFalse()
            ->and($artifact['dispute']['automatic_reassignment'])->toBeFalse()
            ->and($artifact['dispute']['automatic_transfer_to_another_user'])->toBeFalse()
            ->and($artifact['ownership']['one_user_one_patient_profile'])->toBeTrue()
            ->and($artifact['ownership']['user_id_unique_index'])->toBe('patient_profiles_user_id_unique');
    });

    it('records PC-017, PC-019, PC-020, and PC-022 as production-enablement blockers', function () {
        $artifact = Artifact::decoded();
        $byId = Artifact::decisionsById($artifact);

        expect($byId['PC-017']['blocks_production_enablement'])->toBeTrue()
            ->and($byId['PC-019']['blocks_production_enablement'])->toBeTrue()
            ->and($byId['PC-020']['blocks_production_enablement'])->toBeTrue()
            ->and($byId['PC-022']['blocks_production_enablement'])->toBeTrue()
            ->and($byId['PC-020']['implementation_status'])->toBe('ALREADY_ENFORCED')
            ->and($artifact['pc020_prerequisites'])->toBe(Artifact::PC020_PREREQUISITES)
            ->and($artifact['observability_prerequisites_before_pc020'])->toBe(Artifact::OBSERVABILITY_PREREQUISITES)
            ->and($artifact['kill_switch']['verified_kill_switch_required_before_pc020'])->toBeTrue()
            ->and($artifact['kill_switch']['disabling_stops_new_claims'])->toBeTrue()
            ->and($artifact['kill_switch']['disabling_does_not_automatically_unlink_valid_links'])->toBeTrue()
            ->and($artifact['kill_switch']['env_flag_true_alone_cannot_enable_production'])->toBeTrue()
            ->and($artifact['kill_switch']['post_pc020_env_false_disables_without_code_change'])->toBeTrue()
            ->and($byId['PC-020']['policy_decision'])->toContain('env flag alone cannot enable production')
            ->and($byId['PC-019']['policy_decision'])->toContain('verified kill switch')
            ->and($byId['PC-022']['policy_decision'])->toContain('without a code deployment');
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

    it('keeps SF-001 / P02-AUDIT-006 untouched', function () {
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
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-006']['sf_001'])->toBe('OPEN / UNCHANGED');
    });

    it('proves P02-AUDIT-005 is OPEN without using a generic OPEN substring', function () {
        $artifact = Artifact::decoded();

        expect($artifact['p02_audit_005'])->toBe('OPEN')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-005']['status'])->toBe('OPEN')
            ->and($artifact['p02_audit_005'])->not->toBe('CLOSED')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-005']['status'])->not->toBe('CLOSED');
    });

    it('proves T46 is OPEN without using a generic OPEN substring or the audit-005 field', function () {
        $artifact = Artifact::decoded();
        $t46 = $artifact['threat_register']['T46'];

        expect($artifact['t46'])->toBe('OPEN')
            ->and($t46['status'])->toBe('OPEN')
            ->and($t46['id'])->toBe('P02-T46')
            ->and($t46['this_artifact_does_not_close_t46'])->toBeTrue()
            ->and($artifact['p02_audit_005'])->toBe('OPEN');
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

    it('keeps T46 OPEN only inside the P02-T46 threat-register section', function () {
        $register = Artifact::threatModelRaw();
        $section = Artifact::t46ThreatSection($register);

        expect($section)->toContain('### P02-T46 — Profile-claim enablement (out of scope)')
            ->and($section)->toContain('P02-AUDIT-005')
            ->and($section)->not->toContain('### P02-T47')
            ->and(Artifact::t46ThreatStatus($section))->toBe('OPEN')
            ->and($section)->not->toMatch('/^\| Status \| \*\*(CLOSED|MITIGATED)\*\* \|/m');
    });

    it('does not treat a later threat OPEN row as T46 OPEN', function () {
        $full = Artifact::threatModelRaw();
        $section = Artifact::t46ThreatSection($full);
        $closedSection = preg_replace('/^\| Status \| \*\*OPEN\*\* \|/m', '| Status | **CLOSED** |', $section, 1);

        expect($closedSection)->toBeString();

        $mutated = str_replace($section, (string) $closedSection, $full);

        expect(Artifact::t46ThreatStatus(Artifact::t46ThreatSection($mutated)))->toBe('CLOSED')
            ->and($mutated)->toContain('| Status | **OPEN** |')
            ->and(Artifact::t46ThreatStatus())->toBe('OPEN');
    });

    it('does not require the future ceremony class surface to exist beyond the dark stub', function () {
        expect(class_exists(LinkVerifiedPatientAccount::class))->toBeTrue()
            ->and(PatientStatus::Disputed->value)->toBe('disputed')
            ->and(PatientStatus::from('disputed')->isClaimEligible())->toBeFalse();
    });
});

describe('P02-AUDIT-005 evidence-document consistency', function () {
    it('keeps the evidence Markdown semantically aligned with frozen Policy v1 facts', function () {
        $issues = (new Phase02ProfileClaimPolicyValidator)->validate(Artifact::decoded(), Artifact::evidenceRaw());

        expect($issues)->toBe([]);
    });

    it('does not treat a does-not-close sentence as P02-AUDIT-005 CLOSED or T46 CLOSED', function () {
        $evidence = Artifact::evidenceRaw();
        $issues = (new Phase02ProfileClaimPolicyValidator)->validate(Artifact::decoded(), $evidence);

        $normalized = preg_replace('/\s+/', ' ', $evidence) ?? $evidence;

        expect($normalized)->toContain('does **not** close P02-AUDIT-005')
            ->and($normalized)->toContain('does **not** change T46 from OPEN')
            ->and($issues)->toBe([])
            ->and($issues)->not->toContain('evidence_final_status_p02_audit_005_closed')
            ->and($issues)->not->toContain('evidence_prose_p02_audit_005_closed')
            ->and($issues)->not->toContain('evidence_final_status_t46_closed')
            ->and($issues)->not->toContain('evidence_prose_t46_mitigated');
    });

    it('fails a contradictory final-status T46 CLOSED while the identity table remains OPEN', function () {
        $issues = (new Phase02ProfileClaimPolicyValidator)->validate(
            Artifact::decoded(),
            Mutations::evidenceFinalStatus(Artifact::evidenceRaw(), 'T46', 'OPEN', 'CLOSED'),
        );

        expect($issues)->toContain('evidence_final_status_t46_closed')
            ->and($issues)->toContain('evidence_final_status_t46_not_open')
            ->and($issues)->not->toContain('evidence_identity_t46_closed');
    });

    it('does not treat a does-not-close sentence as P02-AUDIT-005 OPEN or CLOSED', function () {
        $issues = (new Phase02ProfileClaimPolicyValidator)->validate(
            Artifact::decoded(),
            Mutations::evidenceReplaceOnce(
                Artifact::evidenceRaw(),
                'Current material state: P02-AUDIT-005 is OPEN.',
                'Current material state no longer assigns P02-AUDIT-005.',
            ),
        );

        expect($issues)->toContain('evidence_prose_p02_audit_005_not_open')
            ->and($issues)->not->toContain('evidence_prose_p02_audit_005_closed')
            ->and($issues)->not->toContain('evidence_final_status_p02_audit_005_closed')
            ->and($issues)->not->toContain('evidence_identity_p02_audit_005_closed');
    });

    it('detects an identity-table CLOSED declaration independently of the final-status block', function () {
        $issues = (new Phase02ProfileClaimPolicyValidator)->validate(
            Artifact::decoded(),
            Mutations::evidenceReplaceOnce(
                Artifact::evidenceRaw(),
                '| **P02-AUDIT-005** | **`OPEN`** |',
                '| **P02-AUDIT-005** | **`CLOSED`** |',
            ),
        );

        expect($issues)->toContain('evidence_identity_p02_audit_005_closed')
            ->and($issues)->toContain('evidence_identity_p02_audit_005_not_open')
            ->and($issues)->not->toContain('evidence_final_status_p02_audit_005_closed');
    });

    it('detects a backtick-and-emphasis wrapped prose CLOSED declaration', function () {
        $issues = (new Phase02ProfileClaimPolicyValidator)->validate(
            Artifact::decoded(),
            Mutations::evidenceReplaceOnce(
                Artifact::evidenceRaw(),
                'Current material state: P02-AUDIT-005 is OPEN.',
                'Current material state: **`P02-AUDIT-005`** is **`CLOSED`**.',
            ),
        );

        expect($issues)->toContain('evidence_prose_p02_audit_005_closed');
    });

    it('keeps Markdown set-valued diagnostic checks order-insensitive while the frozen oracle flags document reorder', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();

        $learnReordered = Mutations::evidenceReplaceOnce(
            $evidence,
            "- nid_exists\n- nid_does_not_exist\n",
            "- nid_does_not_exist\n- nid_exists\n",
        );
        $statesReordered = Mutations::evidenceReplaceOnce(
            $evidence,
            "`wrong_claim_code`,\n`profile_exists`",
            "`profile_exists`,\n`wrong_claim_code`",
        );
        $plaintextReordered = Mutations::evidenceReplaceOnce(
            $evidence,
            'persistence, logs,',
            'logs, persistence,',
        );
        $pc020Reordered = Mutations::evidenceReplaceOnce(
            $evidence,
            "- policy recorded\n- ceremony implementation complete\n",
            "- ceremony implementation complete\n- policy recorded\n",
        );

        $learnIssues = $validator->validate($artifact, $learnReordered);
        $stateIssues = $validator->validate($artifact, $statesReordered);
        $plaintextIssues = $validator->validate($artifact, $plaintextReordered);
        $pc020Issues = $validator->validate($artifact, $pc020Reordered);

        expect($learnIssues)->toContain('evidence_markdown_drift')
            ->and($learnIssues)->not->toContain('evidence_client_must_not_learn_incomplete')
            ->and($learnIssues)->not->toContain('evidence_client_must_not_learn_unexpected_member')
            ->and($stateIssues)->toContain('evidence_markdown_drift')
            ->and($stateIssues)->not->toContain('evidence_prohibited_client_states_incomplete')
            ->and($plaintextIssues)->toContain('evidence_markdown_drift')
            ->and($plaintextIssues)->not->toContain('evidence_plaintext_destinations_incomplete')
            ->and($pc020Issues)->toContain('evidence_markdown_drift')
            ->and($pc020Issues)->not->toContain('evidence_pc020_prerequisites_incomplete');
    });
});

describe('P02-AUDIT-005 policy mutation detection', function () {
    it('fails each isolated Policy v1 or evidence mutation with the specific expected issue', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $baseline = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();

        expect($validator->validate($baseline, $evidence))->toBe([]);

        foreach (Mutations::isolatedCases($baseline, $evidence) as $case) {
            $issues = $validator->validate($case['artifact'], $case['evidence']);
            expect($issues)->toContain($case['expected_issue']);
            if ($case['evidence'] === $evidence) {
                expect($issues)->not->toContain('evidence_markdown_drift');
            } else {
                expect($issues)->toContain('evidence_markdown_drift');
            }
        }
    });

    it('emits evidence_markdown_drift for every previous markdown survivor independently of phrase checks', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        $missed = [];

        expect(Mutations::previousMarkdownSurvivorCases($evidence))->toHaveCount(35);

        foreach (Mutations::previousMarkdownSurvivorCases($evidence) as $case) {
            $issues = $validator->validate($artifact, $case['evidence']);
            if (! in_array('evidence_markdown_drift', $issues, true)) {
                $missed[] = $case['id'];
            }
        }

        expect($missed)->toBe([]);
    });

    it('emits evidence_markdown_drift for every previous fresh-adversarial markdown survivor', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        $missed = [];

        expect(Mutations::previousFreshAdversarialSurvivorCases($evidence))->toHaveCount(23);

        foreach (Mutations::previousFreshAdversarialSurvivorCases($evidence) as $case) {
            $issues = $validator->validate($artifact, $case['evidence']);
            if (! in_array('evidence_markdown_drift', $issues, true)) {
                $missed[] = $case['id'];
            }
        }

        expect($missed)->toBe([]);
    });

    it('emits evidence_markdown_drift for every fresh arbitrary markdown mutation', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        $missed = [];

        expect(Mutations::freshMarkdownAdversarialCases($evidence))->toHaveCount(37);

        foreach (Mutations::freshMarkdownAdversarialCases($evidence) as $case) {
            $issues = $validator->validate($artifact, $case['evidence']);
            if (! in_array('evidence_markdown_drift', $issues, true)) {
                $missed[] = $case['id'];
            }
        }

        expect($missed)->toBe([]);
    });

    it('emits evidence_markdown_drift for independence mutations even when phrase checks are irrelevant', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();

        $lorem = $validator->validate(
            $artifact,
            Mutations::evidenceAppendParagraph($evidence, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'),
        );
        $removed = $validator->validate(
            $artifact,
            Mutations::evidenceReplaceOnce(
                $evidence,
                "AI/agent authoring is engineering evidence, not independent human approval.\n",
                '',
            ),
        );
        $word = $validator->validate($artifact, Mutations::evidenceReplaceOnce($evidence, 'walk-in row', 'walk-in record'));
        $status = $validator->validate($artifact, Mutations::evidenceReplaceOnce($evidence, '| **T46** | **`OPEN`** |', '| **T46** | **`CLOSED`** |'));
        $number = $validator->validate($artifact, Mutations::evidenceReplaceOnce($evidence, '| OTP length | 6 digits |', '| OTP length | 8 digits |'));
        $heading = $validator->validate($artifact, Mutations::evidenceReplaceOnce($evidence, '## Hybrid model', '## Hybrid scheme'));
        $approval = $validator->validate($artifact, Mutations::evidenceAppendParagraph($evidence, 'This policy is APPROVED by Product.'));
        $closure = $validator->validate($artifact, Mutations::evidenceAppendParagraph($evidence, 'P02-AUDIT-005 is CLOSED.'));
        $authorization = $validator->validate($artifact, Mutations::evidenceAppendParagraph($evidence, 'Production enablement is AUTHORIZED.'));
        $proof = $validator->validate($artifact, Mutations::evidenceReplaceOnce($evidence, 'requires **all four** factors:', 'requires **three** factors:'));
        $revealing = $validator->validate($artifact, Mutations::evidenceAppendParagraph($evidence, 'Clients may observe profile_exists.'));

        expect($lorem)->toContain('evidence_markdown_drift')
            ->and($removed)->toContain('evidence_markdown_drift')
            ->and($word)->toContain('evidence_markdown_drift')
            ->and($status)->toContain('evidence_markdown_drift')
            ->and($number)->toContain('evidence_markdown_drift')
            ->and($heading)->toContain('evidence_markdown_drift')
            ->and($approval)->toContain('evidence_markdown_drift')
            ->and($closure)->toContain('evidence_markdown_drift')
            ->and($authorization)->toContain('evidence_markdown_drift')
            ->and($proof)->toContain('evidence_markdown_drift')
            ->and($revealing)->toContain('evidence_markdown_drift');
    });

    it('accepts only documented formatting-only markdown normalization', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        $unexpected = [];

        expect(Mutations::markdownFormattingOnlyCases($evidence))->toHaveCount(4);

        foreach (Mutations::markdownFormattingOnlyCases($evidence) as $case) {
            $issues = $validator->validate($artifact, $case['evidence']);
            if ($issues !== []) {
                $unexpected[] = $case['id'].':'.implode(',', $issues);
            }
        }

        expect($unexpected)->toBe([]);
    });

    it('records zero semantic markdown survivors across all oracle campaigns', function () {
        $validator = new Phase02ProfileClaimPolicyValidator;
        $artifact = Artifact::decoded();
        $evidence = Artifact::evidenceRaw();
        $semanticMissed = [];
        $semanticCaught = 0;

        foreach (Mutations::markdownSemanticOracleCases($evidence) as $case) {
            $issues = $validator->validate($artifact, $case['evidence']);
            if (in_array('evidence_markdown_drift', $issues, true)) {
                $semanticCaught++;
            } else {
                $semanticMissed[] = $case['id'];
            }
        }

        expect($semanticMissed)->toBe([])
            ->and($semanticCaught)->toBe(count(Mutations::markdownSemanticOracleCases($evidence)))
            ->and($semanticCaught)->toBeGreaterThanOrEqual(35 + 23 + 30);
    });

    it('keeps independently frozen test oracles from being treated as governance approval', function () {
        $evidence = Artifact::evidenceRaw();

        expect($evidence)->toContain('test oracles only')
            ->and($evidence)->toContain('A Controller freeze is not Product approval')
            ->and($evidence)->not->toContain('POLICY_V1_REMEDIATED_AWAITING_INDEPENDENT_RE_QA')
            ->and($evidence)->not->toContain('It has never been merged to `main`.')
            ->and($evidence)->not->toMatch('/Controller freeze is Product approval/');
    });
});
