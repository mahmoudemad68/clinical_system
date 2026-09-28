<?php

declare(strict_types=1);

use FilesystemIterator;
use Illuminate\Support\Facades\Route;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Services\DefaultDenyAuthorizer;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\NationalId;
use Modules\Patients\Http\Controllers\PatientProfileController;
use Modules\Patients\Services\UpdateOwnDemographics;
use Modules\Patients\Support\DemographicRules;
use Modules\Patients\Support\PatientDemographicRevisionRecorder;
use Modules\Patients\Support\PatientSubjectHoldings;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Support\Identifier;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use Tests\Support\ProfileCorrection\Phase02PatientProfileCorrectionPolicyArtifact as Artifact;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return list<string>
 */
function phase02PrivateStringList(string $class, string $constant): array
{
    $reflected = (new ReflectionClass($class))->getReflectionConstant($constant);
    expect($reflected)->toBeInstanceOf(ReflectionClassConstant::class);
    $value = $reflected->getValue();
    expect($value)->toBeArray();

    return array_values(array_map('strval', $value));
}

/**
 * @return list<string>
 */
function phase02OpenApiPatchProperties(): array
{
    $yaml = (string) file_get_contents(Artifact::repositoryRoot().'/packages/contracts/openapi/openapi.yaml');
    expect(preg_match(
        '/    PatientDemographicsPatchRequest:\n(?P<body>(?:      .*\n)+)/',
        $yaml,
        $match,
    ))->toBe(1);
    $body = $match['body'];
    expect($body)->toContain('additionalProperties: false');

    preg_match_all('/^        ([A-Za-z0-9_]+):$/m', $body, $keys);

    return array_values($keys[1]);
}

/**
 * @return list<string>
 */
function phase02RevisionCheckFields(): array
{
    $migration = (string) file_get_contents(base_path('database/migrations/2026_09_01_150000_create_patient_profile_tables.php'));
    expect(preg_match(
        '/patient_demographic_revisions_field_check.*?CHECK \(field_name IN \((.*?)\)\)/s',
        $migration,
        $match,
    ))->toBe(1);
    preg_match_all("/'([a-z_]+)'/", $match[1], $fields);

    return array_values($fields[1]);
}

describe('P02-AUDIT-003 profile-correction policy artifact', function () {
    it('keeps the SHA-256 companion equal to the hash of the JSON file bytes', function () {
        $computed = Artifact::computedSha256();
        $recorded = Artifact::recordedSha256();

        expect($computed)->toHaveLength(64)
            ->and($recorded)->toBe($computed)
            ->and(Artifact::rawJson())->not->toContain($computed);
    });

    it('records Freeze Current Behavior identity without reusing the verification-policy namespace', function () {
        $artifact = Artifact::decoded();
        $verification = json_decode(
            (string) file_get_contents(Artifact::repositoryRoot().'/'.Artifact::RELATIVE_VERIFICATION_POLICY),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($artifact['policy_namespace'])->toBe('phase02-patient-profile-correction')
            ->and($artifact['distinct_from_verification_policy'])->toBeTrue()
            ->and($artifact['title'])->toBe('Phase 02 Patient Profile Correction Policy')
            ->and($artifact['version'])->toBe('v1.0.0-phase02')
            ->and($artifact['supersedes'])->toBeNull()
            ->and($artifact['release_date'])->toBe('2026-09-28')
            ->and($artifact['status'])->toBe('APPROVED_PRODUCTION_POLICY')
            ->and($artifact['decision'])->toBe('FREEZE_CURRENT_BEHAVIOR')
            ->and($artifact['p02_mapping']['audit_id'])->toBe('P02-AUDIT-003')
            ->and($artifact['p02_mapping']['threat_id'])->toBe('P02-T45')
            ->and($artifact['version'])->not->toBe($verification['version'])
            ->and($artifact['policy_id'])->not->toBe('phase02-verification-policy');
    });

    it('attributes Product Privacy and Security using the established verification-policy identities', function () {
        $artifact = Artifact::decoded();
        $verification = json_decode(
            (string) file_get_contents(Artifact::repositoryRoot().'/'.Artifact::RELATIVE_VERIFICATION_POLICY),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $governance = $artifact['approval']['repository_governance_attribution'];

        expect($verification['approved_by']['product'])->toBe('prod-gov-lead@system.internal')
            ->and($verification['approved_by']['privacy'])->toBe('privacy-dpo@system.internal')
            ->and($verification['approved_by']['security'])->toBe('ciso-gov@system.internal')
            ->and($governance['product'])->toBe($verification['approved_by']['product'])
            ->and($governance['privacy'])->toBe($verification['approved_by']['privacy'])
            ->and($governance['security'])->toBe($verification['approved_by']['security'])
            ->and($governance['precedent_artifact'])->toBe(Artifact::RELATIVE_VERIFICATION_POLICY)
            ->and($artifact['approval']['controller_decision'])->toBe('FREEZE_CURRENT_BEHAVIOR')
            ->and($artifact['approval']['independent_qa'])->toBe('PENDING')
            ->and($artifact['approval']['g_08_04'])->toBe('OPEN / EXTERNAL_HUMAN')
            ->and($artifact['approval']['g_08_04_claimed_approved'])->toBeFalse()
            ->and($artifact['approval']['controller_decision_evidence_class'])->toBe('GOVERNANCE_ONLY')
            ->and($artifact['approval']['independent_qa_evidence_class'])->toBe('GOVERNANCE_ONLY')
            ->and($artifact['approval']['g_08_04_evidence_class'])->toBe('GOVERNANCE_ONLY');
    });

    it('keeps G-08-04 OPEN wording and does not claim approval', function () {
        $artifact = Artifact::decoded();
        $evidence = (string) file_get_contents(Artifact::evidencePath());
        $json = Artifact::rawJson();

        expect($artifact['external_audit_boundaries']['P02-AUDIT-007']['g_08_04'])->toBe('OPEN / EXTERNAL_HUMAN')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-007']['status'])->toBe('OPEN / EXTERNAL_HUMAN')
            ->and($json)->not->toContain('G-08-04 APPROVED')
            ->and($evidence)->toContain('**G-08-04**')
            ->and($evidence)->toContain('`OPEN`')
            ->and($evidence)->toContain('EXTERNAL_HUMAN');

        foreach ([$json, $evidence] as $text) {
            preg_match_all('/G-08-04[^\n]{0,240}/i', $text, $matches);
            expect($matches[0])->not->toBeEmpty();
            foreach ($matches[0] as $window) {
                if (preg_match('/\bOPEN\b/', $window) === 1) {
                    continue;
                }
                if (preg_match('/\bnot\b.{0,40}\b(APPROVED|ACCEPTED|CLOSED)\b/i', $window) === 1) {
                    continue;
                }
                expect($window)->not->toMatch('/\b(APPROVED|ACCEPTED|CLOSED)\b/');
            }
        }
    });

    it('keeps SF-001 and profile-claim outside this policy', function () {
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
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-006']['sf_001'])->toBe('OPEN / UNCHANGED')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-006']['package'])->toBe('extract-zip@2.0.1')
            ->and($artifact['external_audit_boundaries']['P02-AUDIT-005']['status'])->toBe('OPEN')
            ->and(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        $features = (string) file_get_contents(base_path('Modules/Platform/app/Services/Features/PlatformFeatures.php'));
        expect($features)
            ->toContain('IDENTITY_PROFILE_CLAIM')
            ->toContain("config('app.env') === 'production'");
    });
});

describe('P02-AUDIT-003 runtime alignment', function () {
    it('aligns the closed seven-field allowlist across policy and implementation', function () {
        $artifact = Artifact::decoded();
        $policyAllowlist = Artifact::stringList($artifact, 'self_correction.closed_allowlist');
        $revisionNames = Artifact::stringList($artifact, 'revision_history.allowed_field_names');
        $otherPlain = Artifact::stringList($artifact, 'revision_privacy_storage.other_editable_fields.fields');
        $editable = phase02PrivateStringList(UpdateOwnDemographics::class, 'EDITABLE');
        $creation = phase02PrivateStringList(PatientDemographicRevisionRecorder::class, 'CREATION_FIELDS');
        $patchKeys = array_values(array_diff(array_keys(DemographicRules::patch()), ['version']));
        $openapi = array_values(array_diff(phase02OpenApiPatchProperties(), ['version']));
        $check = phase02RevisionCheckFields();

        expect($policyAllowlist)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($revisionNames)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($editable)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($creation)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($patchKeys)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($openapi)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($check)->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST)
            ->and($otherPlain)->toBe(array_values(array_diff(Artifact::APPROVED_SELF_EDIT_ALLOWLIST, ['full_name'])))
            ->and($artifact['self_correction']['unknown_properties'])->toBe('default_deny');
    });

    it('excludes National ID identifiers status internals and caller provenance from the PATCH schema', function () {
        $artifact = Artifact::decoded();
        $excluded = Artifact::stringList($artifact, 'immutable_excluded_fields');
        $patchKeys = array_keys(DemographicRules::patch());
        $openapi = phase02OpenApiPatchProperties();
        $editable = phase02PrivateStringList(UpdateOwnDemographics::class, 'EDITABLE');

        expect($excluded)->toBe(Artifact::IMMUTABLE_EXCLUDED_FIELDS)
            ->and($artifact['national_id']['mutable_on_demographic_patch'])->toBeFalse();

        foreach (Artifact::IMMUTABLE_EXCLUDED_FIELDS as $field) {
            expect($patchKeys)->not->toContain($field)
                ->and($openapi)->not->toContain($field)
                ->and($editable)->not->toContain($field)
                ->and(phase02RevisionCheckFields())->not->toContain($field);
        }
    });

    it('keeps reason source and actor server-controlled as implemented', function () {
        $artifact = Artifact::decoded();
        $source = (string) file_get_contents(base_path('Modules/Patients/app/Services/UpdateOwnDemographics.php'));

        expect($artifact['reason']['code'])->toBe('self_correction')
            ->and($artifact['reason']['caller_supplied'])->toBeFalse()
            ->and($artifact['reason']['catalogue_required_in_phase02'])->toBeFalse()
            ->and($artifact['reason']['free_text_required_in_phase02'])->toBeFalse()
            ->and($artifact['provenance']['source_type'])->toBe('self_onboarding')
            ->and($artifact['provenance']['actor_type'])->toBe('user')
            ->and($artifact['provenance']['caller_supplied'])->toBeFalse()
            ->and($source)->toContain("'reason_code' => 'self_correction'")
            ->and($source)->toContain("'source_type' => 'self_onboarding'")
            ->and($source)->toContain("'actor_type' => 'user'")
            ->and($source)->toContain("'request_id' => \$requestId->value");
    });

    it('accepts AAL1 live own-profile correction and denies pending-phone without requiring AAL2', function () {
        $artifact = Artifact::decoded();
        $authorizer = new DefaultDenyAuthorizer(Mockery::mock(GrantStore::class));
        $live = new ActorContext(
            Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7d01'),
            AccountType::Patient,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal1Password,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );
        $pending = new ActorContext(
            Identifier::fromTrusted('0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7d02'),
            AccountType::Patient,
            AccountStatus::PendingPhone,
            LanguagePreference::English,
            AssuranceLevel::Aal1Password,
            1,
            null,
            null,
            [],
            Capabilities::AUTHENTICATED_SELF,
        );

        expect($artifact['assurance']['accepted_assurance'])->toBe('AAL1_PASSWORD')
            ->and($artifact['assurance']['aal2_required'])->toBeFalse()
            ->and($artifact['assurance']['step_up_required'])->toBeFalse()
            ->and($artifact['assurance']['pending_phone'])->toBe('denied')
            ->and($artifact['assurance']['own_linked_patient_profile'])->toBeTrue()
            ->and(in_array(Capabilities::PATIENTS_PROFILE_UPDATE_OWN, Capabilities::AUTHENTICATED_SELF, true))->toBeTrue()
            ->and($authorizer->decide($live, Capabilities::PATIENTS_PROFILE_UPDATE_OWN)->allowed)->toBeTrue()
            ->and($authorizer->decide($pending, Capabilities::PATIENTS_PROFILE_UPDATE_OWN)->reasonCode)->toBe('pending_restricted')
            ->and(AssuranceLevel::Aal1Password->satisfiesPrivilegedSession())->toBeFalse();
    });

    it('retains own-profile closed JSON version conflict and no PATCH idempotency middleware', function () {
        $artifact = Artifact::decoded();
        $route = collect(Route::getRoutes())->first(
            fn ($candidate): bool => $candidate->uri() === 'api/v1/patients/me/demographics'
                && in_array('PATCH', $candidate->methods(), true),
        );

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toContain(PatientProfileController::class)
            ->and($route->gatherMiddleware())->not->toContain('platform.idempotency')
            ->and($artifact['self_correction']['http']['path'])->toBe('/api/v1/patients/me/demographics')
            ->and($artifact['self_correction']['http']['idempotency_middleware'])->toBeFalse()
            ->and($artifact['authorization_concurrency']['http_idempotency_key'])->toBeFalse()
            ->and($artifact['authorization_concurrency']['required_profile_version'])->toBeTrue()
            ->and($artifact['authorization_concurrency']['optimistic_concurrency'])->toBe('VERSION_CONFLICT')
            ->and($artifact['authorization_concurrency']['concurrent_same_version'])->toBe('one_success_one_conflict')
            ->and(DemographicRules::patch()['version'])->toContain('required');

        $controller = (string) file_get_contents(base_path('Modules/Patients/app/Http/Controllers/PatientProfileController.php'));
        $validator = (string) file_get_contents(base_path('Modules/Platform/app/Http/Support/ClosedJsonValidator.php'));
        $service = (string) file_get_contents(base_path('Modules/Patients/app/Services/UpdateOwnDemographics.php'));

        expect($controller)->toContain('ClosedJsonValidator::validate($request, DemographicRules::patch())')
            ->and($validator)->toContain('Unexpected property.')
            ->and($service)->toContain('VersionConflict')
            ->and($service)->toContain("\$input['version']");
    });

    it('aligns append-only protected-name and plaintext residual revision storage', function () {
        $artifact = Artifact::decoded();
        $service = (string) file_get_contents(base_path('Modules/Patients/app/Services/UpdateOwnDemographics.php'));
        $migration = (string) file_get_contents(base_path('database/migrations/2026_09_01_150000_create_patient_profile_tables.php'));
        $holdings = PatientSubjectHoldings::plan();
        $revisionHolding = collect($holdings)->firstWhere('holding', 'patient_demographic_revisions');

        expect($artifact['revision_history']['append_only'])->toBeTrue()
            ->and($artifact['revision_history']['read_api'])->toBeFalse()
            ->and($artifact['revision_privacy_storage']['full_name']['old_new_representation'])->toBe('protected_encrypted')
            ->and($artifact['revision_privacy_storage']['full_name']['old_plain'])->toBeNull()
            ->and($artifact['revision_privacy_storage']['full_name']['new_plain'])->toBeNull()
            ->and($artifact['revision_privacy_storage']['full_name']['always_revises_when_supplied'])->toBeTrue()
            ->and($artifact['revision_privacy_storage']['other_editable_fields']['representation'])->toBe('plaintext_revision_columns')
            ->and($artifact['erasure_retention']['subject_erasure_rewrites_revision_ledger'])->toBeFalse()
            ->and($artifact['erasure_retention']['legal_retention_duration'])->toBe('OPEN_LEGAL_DECISION')
            ->and($artifact['erasure_retention']['legal_retention_duration_evidence_class'])->toBe('GOVERNANCE_ONLY')
            ->and($artifact['erasure_retention']['legal_sign_off_blocks_phase02_gate'])->toBeFalse()
            ->and($migration)->toContain('patient_demographic_revisions is append-only')
            ->and($migration)->toContain('BEFORE UPDATE OR DELETE ON patient_demographic_revisions')
            ->and($service)->toContain("'old_plain' => null")
            ->and($service)->toContain("'new_plain' => null")
            ->and($service)->toContain("'old_plain' => \$current")
            ->and($service)->toContain("'new_plain' => \$normalized")
            ->and($revisionHolding?->action->value)->toBe('PRESERVE_SECURITY_AUDIT');

        expect(preg_match(
            '/if \(\$field === \'full_name\'\).*?return \[/s',
            $service,
        ))->toBe(1);
        expect($service)->not->toContain("if (\$field === 'full_name' && \$this->same");
    });

    it('does not introduce staff correction re-verification supporting documents or claim', function () {
        $artifact = Artifact::decoded();
        $service = (string) file_get_contents(base_path('Modules/Patients/app/Services/UpdateOwnDemographics.php'));
        $nationalId = (string) file_get_contents(base_path('Modules/Identity/app/Support/NationalId.php'));

        $demographicRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'demographic'))
            ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        expect($demographicRoutes)->toHaveCount(1)
            ->and($demographicRoutes[0])->toContain('PATCH')
            ->and($demographicRoutes[0])->toContain('api/v1/patients/me/demographics')
            ->and($artifact['staff_admin_correction']['policy'])->toBe('NO_STAFF_OR_ADMIN_DEMOGRAPHIC_CORRECTION_SURFACE')
            ->and($artifact['identity_reverification']['required_for_full_name_change'])->toBeFalse()
            ->and($artifact['identity_reverification']['required_for_date_of_birth_change'])->toBeFalse()
            ->and($artifact['identity_reverification']['workflow_exists_in_phase02'])->toBeFalse()
            ->and($artifact['authorization_concurrency']['status_active_gate'])->toBeFalse()
            ->and($artifact['authorization_concurrency']['correction_specific_rate_limit'])->toBeFalse()
            ->and($service)->not->toContain('VerificationService')
            ->and($service)->not->toContain('Notify')
            ->and($service)->not->toContain('status === ')
            ->and($service)->not->toContain('$row->status')
            ->and($nationalId)->toContain('checkdate')
            ->and($service)->not->toContain(NationalId::class);

        $patientFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('Modules/Patients/app'), FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $patientFiles[] = $file->getPathname();
            }
        }
        $patientsPhp = collect($patientFiles)
            ->map(fn (string $path): string => (string) file_get_contents($path))
            ->implode("\n");
        expect($patientsPhp)->not->toContain('Modules\\Verification\\Services\\VerificationService')
            ->and($patientsPhp)->not->toContain('correction_document')
            ->and($patientsPhp)->not->toContain('demographic_appeal');

        $residualIds = array_map(
            fn (array $row): string => $row['id'],
            $artifact['accepted_residuals'],
        );
        expect($residualIds)->toBe(Artifact::ACCEPTED_RESIDUAL_IDS);
        foreach ($artifact['accepted_residuals'] as $residual) {
            expect($residual['classification'])->toBe('ACCEPTED_PHASE02_RESIDUAL')
                ->and($residual['evidence_class'])->toBe('RUNTIME_ALIGNED');
        }

        expect($artifact['deferred_not_required_for_phase02'])->toContain('supporting_documents_for_demographic_correction')
            ->and($artifact['deferred_not_required_for_phase02'])->toContain('staff_assisted_correction')
            ->and($artifact['deferred_not_required_for_phase02'])->toContain('identity_reverification_workflow')
            ->and($artifact['deferred_not_required_for_phase02'])->toContain('correction_specific_aal2')
            ->and($artifact['deferred_not_required_for_phase02'])->toContain('client_selected_reason_catalogue');
    });
});
