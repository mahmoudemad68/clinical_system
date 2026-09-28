<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Tests\Support\ProfileCorrection\Phase02PatientProfileCorrectionPolicyArtifact as Artifact;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('P02-AUDIT-003 HTTP alignment with freeze policy', function () {
    it('rejects National ID unknown fields and caller-supplied reason or source on PATCH with 422', function () {
        $session = patientsActiveSession('p02-corr-deny');
        $created = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('p02-corr-on'),
        );
        $created->assertCreated();

        $headers = patientsAuth($session['token']);

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'national_id' => $session['payload']['national_id'],
        ], $headers)->assertUnprocessable();

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'nickname' => 'not-allowlisted',
        ], $headers)->assertUnprocessable();

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'reason_code' => 'staff_correction',
            'height_cm' => 170,
        ], $headers)->assertUnprocessable();

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'source_type' => 'admin_created',
            'weight_kg' => 70,
        ], $headers)->assertUnprocessable();

        $this->patchJson('/api/v1/patients/me/demographics', [
            'height_cm' => 170,
        ], $headers)->assertUnprocessable();

        expect((int) DB::table('patient_profiles')->value('version'))->toBe(1)
            ->and(Artifact::stringList(Artifact::decoded(), 'self_correction.closed_allowlist'))
            ->toBe(Artifact::APPROVED_SELF_EDIT_ALLOWLIST);
    });

    it('stores protected full_name revisions and plaintext residuals for other editable fields', function () {
        $session = patientsActiveSession('p02-corr-rev');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id'], 'Original Name'),
            patientsAuth($session['token']) + patientsIdem('p02-corr-rev-on'),
        )->assertCreated();

        $headers = patientsAuth($session['token']);
        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'full_name' => 'Original Name',
            'gender' => 'male',
        ], $headers)->assertOk()->assertJsonPath('data.version', 2);

        $nameRevision = DB::table('patient_demographic_revisions')
            ->where('field_name', 'full_name')
            ->orderByDesc('created_at')
            ->first();
        $genderRevision = DB::table('patient_demographic_revisions')
            ->where('field_name', 'gender')
            ->where('reason_code', 'self_correction')
            ->orderByDesc('created_at')
            ->first();

        expect($nameRevision)->not->toBeNull()
            ->and($genderRevision)->not->toBeNull()
            ->and($nameRevision->old_plain)->toBeNull()
            ->and($nameRevision->new_plain)->toBeNull()
            ->and(BinaryColumn::asString($nameRevision->old_protected))->not->toBe('')
            ->and(BinaryColumn::asString($nameRevision->new_protected))->not->toBe('')
            ->and($nameRevision->reason_code)->toBe('self_correction')
            ->and($nameRevision->source_type)->toBe('self_onboarding')
            ->and($nameRevision->actor_type)->toBe('user')
            ->and((string) $nameRevision->actor_id)->toBe($session['user_id'])
            ->and($genderRevision->old_protected)->toBeNull()
            ->and($genderRevision->new_protected)->toBeNull()
            ->and((string) $genderRevision->old_plain)->toBe('female')
            ->and((string) $genderRevision->new_plain)->toBe('male');
    });

    it('returns 404 for staff or admin demographic correction routes that are not implemented', function () {
        $session = patientsActiveSession('p02-corr-staff');
        $created = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('p02-corr-staff-on'),
        );
        $created->assertCreated();
        $patientId = $created->json('data.patient_id');
        $headers = patientsAuth($session['token']);

        foreach ([
            '/api/v1/admin/patients/'.$patientId.'/demographics',
            '/api/v1/doctors/patients/'.$patientId.'/demographics',
            '/api/v1/pharmacies/patients/'.$patientId.'/demographics',
            '/api/v1/patients/'.$patientId.'/demographics',
        ] as $uri) {
            $this->patchJson($uri, ['version' => 1, 'gender' => 'male'], $headers)->assertNotFound();
        }

        expect(Artifact::decoded()['staff_admin_correction']['policy'])
            ->toBe('NO_STAFF_OR_ADMIN_DEMOGRAPHIC_CORRECTION_SURFACE');
    });

    it('stores a valid caller X-Request-Id as revision correlation while keeping reason source and actor server-controlled', function () {
        $session = patientsActiveSession('p02-corr-xid');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('p02-corr-xid-on'),
        )->assertCreated();

        $supplied = app(IdentityGenerator::class)->next()->value;
        Identifier::fromString($supplied);

        $response = $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'gender' => 'male',
        ], patientsAuth($session['token']) + ['X-Request-Id' => $supplied]);

        $response->assertOk()
            ->assertHeader('X-Request-Id', $supplied)
            ->assertJsonPath('request_id', $supplied);

        $revision = DB::table('patient_demographic_revisions')
            ->where('field_name', 'gender')
            ->where('reason_code', 'self_correction')
            ->orderByDesc('created_at')
            ->first();
        $policy = Artifact::decoded();

        expect($revision)->not->toBeNull()
            ->and((string) $revision->request_id)->toBe($supplied)
            ->and($revision->reason_code)->toBe('self_correction')
            ->and($revision->reason_code)->toBe($policy['reason']['code'])
            ->and($revision->source_type)->toBe('self_onboarding')
            ->and($revision->source_type)->toBe($policy['provenance']['source_type'])
            ->and($revision->actor_type)->toBe('user')
            ->and((string) $revision->actor_id)->toBe($session['user_id'])
            ->and($policy['reason']['caller_supplied'])->toBeFalse()
            ->and($policy['provenance']['source_type_caller_supplied'])->toBeFalse()
            ->and($policy['provenance']['actor_identity_caller_supplied'])->toBeFalse()
            ->and($policy['provenance']['request_id']['may_originate_from_valid_caller_x_request_id'])->toBeTrue()
            ->and($policy['provenance']['request_id']['is_proof_of_caller_identity'])->toBeFalse()
            ->and($policy['provenance']['request_id']['is_trustworthy_provenance_by_itself'])->toBeFalse();
    });

    it('replaces a malformed X-Request-Id before storing demographic revision correlation', function () {
        $session = patientsActiveSession('p02-corr-xid-bad');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('p02-corr-xid-bad-on'),
        )->assertCreated();

        $hostile = 'not-a-uuid';
        $response = $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'weight_kg' => 70,
        ], patientsAuth($session['token']) + ['X-Request-Id' => $hostile]);

        $response->assertOk();
        $echoed = (string) $response->headers->get('X-Request-Id');
        $revision = DB::table('patient_demographic_revisions')
            ->where('field_name', 'weight_kg')
            ->orderByDesc('created_at')
            ->first();

        expect($revision)->not->toBeNull()
            ->and($echoed)->not->toBe($hostile)
            ->and((string) $revision->request_id)->toBe($echoed)
            ->and((string) $revision->request_id)->not->toBe($hostile)
            ->and(Identifier::fromString((string) $revision->request_id)->value)->toBe($echoed)
            ->and($revision->reason_code)->toBe('self_correction')
            ->and($revision->source_type)->toBe('self_onboarding')
            ->and(Artifact::decoded()['provenance']['request_id']['malformed_replaced_by_server'])->toBeTrue();
    });

    it('leaves surviving erasure residual classes that the freeze policy names', function () {
        $session = patientsActiveSession('p02-corr-erase');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id'], 'Original Name'),
            patientsAuth($session['token']) + patientsIdem('p02-corr-erase-on'),
        )->assertCreated();

        $this->patchJson('/api/v1/patients/me/demographics', [
            'version' => 1,
            'full_name' => 'Revised Name',
            'gender' => 'male',
            'height_cm' => 170,
        ], patientsAuth($session['token']))->assertOk();

        $profileId = (string) DB::table('patient_profiles')->where('user_id', $session['user_id'])->value('id');
        $before = DB::table('patient_profiles')->where('id', $profileId)->first();
        expect($before)->not->toBeNull();

        $admin = User::factory()->create([
            'account_type' => AccountType::Admin->value,
            'status' => AccountStatus::Active->value,
        ]);
        $operator = new ActorContext(
            Identifier::fromTrusted((string) $admin->id),
            AccountType::Admin,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal2Totp,
            1,
            null,
            Identifier::fromTrusted((string) $admin->id),
            [],
            Capabilities::forActor('admin', true),
        );

        app(EraseSubjectService::class)->handle(
            $operator,
            Identifier::fromTrusted($session['user_id']),
            'subject_erasure',
        );

        $after = DB::table('patient_profiles')->where('id', $profileId)->first();
        $revisions = DB::table('patient_demographic_revisions')->where('patient_profile_id', $profileId)->get();
        $policy = Artifact::decoded();
        $plaintextColumns = Artifact::stringList($policy, 'erasure_retention.live_profile_plaintext_demographics_not_rewritten');
        $nameRevision = $revisions
            ->where('field_name', 'full_name')
            ->where('reason_code', 'self_correction')
            ->sortByDesc('created_at')
            ->first();
        $genderRevision = $revisions
            ->where('field_name', 'gender')
            ->where('reason_code', 'self_correction')
            ->sortByDesc('created_at')
            ->first();

        expect($after)->not->toBeNull()
            ->and($after->user_id)->toBeNull()
            ->and((string) $after->status)->toBe('archived')
            ->and(BinaryColumn::asString($after->national_id_ciphertext))->not->toBe(BinaryColumn::asString($before->national_id_ciphertext))
            ->and(BinaryColumn::asString($after->full_name_ciphertext))->not->toBe(BinaryColumn::asString($before->full_name_ciphertext))
            ->and($revisions)->not->toBeEmpty()
            ->and($nameRevision)->not->toBeNull()
            ->and($genderRevision)->not->toBeNull()
            ->and($nameRevision->old_plain)->toBeNull()
            ->and($nameRevision->new_plain)->toBeNull()
            ->and(BinaryColumn::asString($nameRevision->old_protected))->not->toBe('')
            ->and(BinaryColumn::asString($nameRevision->new_protected))->not->toBe('')
            ->and((string) $genderRevision->old_plain)->toBe('female')
            ->and((string) $genderRevision->new_plain)->toBe('male')
            ->and((string) $genderRevision->actor_id)->toBe($session['user_id'])
            ->and($policy['erasure_retention']['does_not_remove_all_personal_data'])->toBeTrue()
            ->and($policy['erasure_retention']['historical_revision_records_remain'])->toBeTrue()
            ->and($policy['erasure_retention']['non_name_revision_plaintext_may_remain'])->toBeTrue()
            ->and($policy['erasure_retention']['revision_actor_id_may_remain'])->toBeTrue()
            ->and($policy['erasure_retention']['encrypted_historical_name_may_remain_after_live_profile_erasure'])->toBeTrue()
            ->and($policy['erasure_retention']['legal_retention_duration'])->toBe('OPEN_LEGAL_DECISION');

        foreach ($plaintextColumns as $column) {
            $expected = (string) $before->{$column};
            $actual = (string) $after->{$column};
            if ($column === 'date_of_birth') {
                $expected = substr($expected, 0, 10);
                $actual = substr($actual, 0, 10);
            }

            expect($actual)->not->toBe('')
                ->and($actual)->toBe($expected);
        }
    });
});
