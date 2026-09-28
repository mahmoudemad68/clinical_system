<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Services\Persistence\BinaryColumn;
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
});
