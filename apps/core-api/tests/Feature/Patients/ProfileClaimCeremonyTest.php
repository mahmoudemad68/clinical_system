<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\AssuranceLevel;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\LinkVerifiedPatientAccount;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\ClaimCredential;
use Modules\Patients\Enums\PatientStatus;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Patients\Services\FreezeDisputedPatientProfile;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Services\Features\PlatformFeatures;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Modules\Platform\Support\Identifier;
use Tests\Support\ProfileClaim\DuplicateMatchPatientIdentityRegistry;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('profile claim fail-closed while disabled', function () {
    it('keeps the disabled onboarding endpoint fail-closed', function () {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        $session = patientsActiveSession('pc-off-ep');
        $nid = $session['payload']['national_id'];
        $handle = profileClaimIssueWalkIn($nid);

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($nid, $handle->claimCredential),
            patientsAuth($session['token']) + patientsIdem('pc-off-ep'),
        );

        profileClaimAssertGenericPending($response, $nid, $handle->claimCredential);
        expect(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
    });

    it('keeps the disabled LinkVerifiedPatientAccount path from attaching', function () {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        $session = patientsActiveSession('pc-off-svc');
        $nid = $session['payload']['national_id'];
        $handle = profileClaimIssueWalkIn($nid);

        expect(fn () => app(LinkVerifiedPatientAccount::class)->handle(
            patientsSelfActor($session['user_id']),
            $nid,
            $handle->claimCredential,
        ))->toThrow(FeatureUnavailable::class);

        expect(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
    });

    it('hides profile_claim OTP as 404 while the flag is off even with a bearer', function () {
        $session = patientsActiveSession('pc-off-otp');

        $this->postJson('/api/v1/auth/otp-requests', [
            'phone' => $session['payload']['phone'],
            'purpose' => 'profile_claim',
            'language' => 'en',
        ], patientsAuth($session['token']) + patientsIdem('pc-off-otp'))->assertNotFound();
    });

    it('issues a walk-in credential without storing plaintext and never retroactively', function () {
        $nid = patientsSyntheticIdentity()['national_id'];
        $first = profileClaimIssueWalkIn($nid);
        expect(strlen((string) $first->claimCredential))->toBe(16)
            ->and(strspn((string) $first->claimCredential, ClaimCredential::ALPHABET))->toBe(16);

        $row = DB::table('patient_claim_credentials')->first();
        expect($row)->not->toBeNull()
            ->and($row->consumed_at)->toBeNull();
        $hmac = BinaryColumn::asString($row->credential_lookup_hmac);
        expect($hmac)->not->toBe($first->claimCredential)
            ->and(bin2hex($hmac))->not->toBe($first->claimCredential);

        $second = app(CreateUnlinkedPatientProfile::class)->handle(
            patientsUnlinkedActor(),
            patientsDemographics($nid, 'Walk In'),
            patientsCorrelationId(),
        );
        expect($second->claimCredential)->toBeNull()
            ->and($second->patientId->value)->toBe($first->patientId->value)
            ->and(DB::table('patient_claim_credentials')->count())->toBe(1);
    });
});

describe('profile claim ceremony when isolated flag is on', function () {
    beforeEach(function () {
        profileClaimEnableIsolated();
    });

    afterEach(function () {
        profileClaimDisableIsolated();
    });

    it('attaches on the PC-002 four-factor bundle and records ial2_verified_link', function () {
        $ready = profileClaimPrepareFourFactor('pc-ok');
        $display = ClaimCredential::display($ready['credential']);

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $display),
            patientsAuth($ready['session']['token']) + patientsIdem('pc-ok'),
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'profile_ready')
            ->assertJsonPath('data.patient_id', $ready['patient_id'])
            ->assertJsonMissingPath('data.profile')
            ->assertJsonMissingPath('data.national_id');
        expect($response->getContent())->not->toContain($ready['nid'])
            ->and($response->getContent())->not->toContain($ready['credential']);

        expect((string) DB::table('patient_profiles')->where('id', $ready['patient_id'])->value('user_id'))
            ->toBe($ready['session']['user_id']);
        expect(DB::table('patient_claim_credentials')->where('patient_id', $ready['patient_id'])->value('consumed_at'))
            ->not->toBeNull();

        $inboxRaw = DB::table('notifications')->where('notifiable_id', $ready['session']['user_id'])->value('data');
        $inbox = is_string($inboxRaw) ? $inboxRaw : json_encode($inboxRaw);
        expect($inbox)->toContain('successful_profile_bind')
            ->and($inbox)->not->toContain($ready['nid'])
            ->and($inbox)->not->toContain($ready['credential'])
            ->and($inbox)->not->toContain($ready['session']['payload']['phone']);

        $audit = DB::table('audit_events')->where('event_name', 'patient.account_linked')->orderByDesc('occurred_at')->first();
        expect($audit)->not->toBeNull();
        $metadata = is_string($audit->metadata) ? $audit->metadata : json_encode($audit->metadata);
        expect($metadata)->toContain('profile_claim')
            ->and($metadata)->toContain('ial2_verified_link')
            ->and($metadata)->not->toContain($ready['credential'])
            ->and($metadata)->not->toContain($ready['nid']);

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->toContain('clinic_profile_claims_total')
            ->and($metrics)->toContain('result="linked"')
            ->and($metrics)->toContain('assurance_level="ial2_verified_link"')
            ->and($metrics)->not->toContain($ready['nid'])
            ->and($metrics)->not->toContain($ready['patient_id']);
    });

    it('treats national id plus otp alone as insufficient', function () {
        $session = patientsActiveSession('pc-nidotp');
        $nid = $session['payload']['national_id'];
        $handle = profileClaimIssueWalkIn($nid);
        profileClaimConsumeOtp($session, 'pc-nidotp');

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($nid),
            patientsAuth($session['token']) + patientsIdem('pc-nidotp'),
        );

        profileClaimAssertGenericPending($response, $nid, $handle->claimCredential);
        expect(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
        expect((string) $response->getStatusCode())->not->toBe('201');
    });

    it('does not treat profile_claim otp verify as a completed claim', function () {
        $session = patientsActiveSession('pc-otp-only');
        profileClaimConsumeOtp($session, 'pc-otp-only');

        expect(DB::table('patient_profiles')->count())->toBe(0)
            ->and(DB::table('otp_requests')->where('purpose', 'profile_claim')->whereNotNull('consumed_at')->count())->toBe(1);
    });

    it('routes a missing bound national id to generic pending', function () {
        $session = patientsActiveSession('pc-nobound');
        $nid = $session['payload']['national_id'];
        $handle = profileClaimIssueWalkIn($nid);
        profileClaimConsumeOtp($session, 'pc-nobound');
        DB::table('identity_national_ids')->where('user_id', $session['user_id'])->delete();

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($nid, $handle->claimCredential),
            patientsAuth($session['token']) + patientsIdem('pc-nobound'),
        );

        profileClaimAssertGenericPending($response, $nid, $handle->claimCredential);
        expect(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
    });

    it('rejects a stale consumed otp older than ten minutes', function () {
        $ready = profileClaimPrepareFourFactor('pc-staleotp');
        DB::table('otp_requests')->where('purpose', 'profile_claim')->update([
            'consumed_at' => now('UTC')->subMinutes(11),
        ]);

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $ready['credential']),
            patientsAuth($ready['session']['token']) + patientsIdem('pc-staleotp'),
        );

        profileClaimAssertGenericPending($response, $ready['nid'], $ready['credential']);
        expect(DB::table('patient_profiles')->where('id', $ready['patient_id'])->value('user_id'))->toBeNull();
    });

    it('rejects an expired claim credential as generic pending', function () {
        $ready = profileClaimPrepareFourFactor('pc-expired');
        DB::table('patient_claim_credentials')->where('patient_id', $ready['patient_id'])->update([
            'expires_at' => now('UTC')->subDay(),
        ]);

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $ready['credential']),
            patientsAuth($ready['session']['token']) + patientsIdem('pc-expired'),
        );

        profileClaimAssertGenericPending($response, $ready['nid'], $ready['credential']);
        expect(DB::table('patient_profiles')->where('id', $ready['patient_id'])->value('user_id'))->toBeNull();
    });

    it('rejects a reused credential after a successful attach', function () {
        $ready = profileClaimPrepareFourFactor('pc-reuse');
        $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $ready['credential']),
            patientsAuth($ready['session']['token']) + patientsIdem('pc-reuse-1'),
        )->assertOk()->assertJsonPath('data.status', 'profile_ready');

        $other = patientsActiveSession('pc-reuse-other');
        $stolen = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $ready['credential']),
            patientsAuth($other['token']) + patientsIdem('pc-reuse-2'),
        );
        profileClaimAssertGenericPending($stolen, $ready['nid'], $ready['credential']);
        expect((string) DB::table('patient_profiles')->where('id', $ready['patient_id'])->value('user_id'))
            ->toBe($ready['session']['user_id']);
    });

    it('does not disclose or steal an already-bound profile', function () {
        $owner = patientsActiveSession('pc-owned-a');
        $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($owner['payload']['national_id']),
            patientsAuth($owner['token']) + patientsIdem('pc-owned-a'),
        )->assertCreated();

        $other = patientsActiveSession('pc-owned-b');
        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($owner['payload']['national_id'], '0123456789ABCDEF'),
            patientsAuth($other['token']) + patientsIdem('pc-owned-b'),
        );

        profileClaimAssertGenericPending($response, $owner['payload']['national_id'], '0123456789ABCDEF');
        expect((string) DB::table('patient_profiles')->value('user_id'))->toBe($owner['user_id'])
            ->and(DB::table('patient_profiles')->count())->toBe(1);
    });

    it('hides ineligible account types behind FeatureUnavailable', function () {
        $session = patientsActiveSession('pc-doctor');
        $actor = new ActorContext(
            Identifier::fromTrusted($session['user_id']),
            AccountType::Admin,
            AccountStatus::Active,
            LanguagePreference::English,
            AssuranceLevel::Aal1Password,
            1,
            null,
            null,
            [],
            [],
        );

        expect(fn () => app(LinkVerifiedPatientAccount::class)->handle($actor, $session['payload']['national_id']))
            ->toThrow(FeatureUnavailable::class);
    });

    it('maps ceremony-start budgets to generic pending rather than 429', function () {
        profileClaimEnableIsolated([
            'identity.profile_claim.ceremony_starts_per_account_per_hour' => 5,
        ]);
        $session = patientsActiveSession('pc-startbudget');
        $nid = $session['payload']['national_id'];
        profileClaimIssueWalkIn($nid);

        for ($i = 1; $i <= 6; $i++) {
            $response = $this->postJson(
                '/api/v1/patients/onboarding',
                profileClaimOnboardingBody($nid, '0123456789ABCDEF'),
                patientsAuth($session['token']) + patientsIdem('pc-startbudget-'.$i),
            );
            profileClaimAssertGenericPending($response, $nid, '0123456789ABCDEF');
            expect($response->status())->toBe(200);
        }
        expect(DB::table('patient_profiles')->count())->toBe(1)
            ->and(DB::table('patient_profiles')->value('user_id'))->toBeNull();
    });

    it('applies a 15-minute cooldown after five credential failures without an oracle', function () {
        $session = patientsActiveSession('pc-cd');
        $nid = $session['payload']['national_id'];
        $handle = profileClaimIssueWalkIn($nid);
        profileClaimConsumeOtp($session, 'pc-cd');

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson(
                '/api/v1/patients/onboarding',
                profileClaimOnboardingBody($nid, '0123456789ABCDEF'),
                patientsAuth($session['token']) + patientsIdem('pc-cd-'.$i),
            );
            profileClaimAssertGenericPending($response, $nid, '0123456789ABCDEF');
        }

        expect(DB::table('patient_claim_failures')->count())->toBe(5)
            ->and(DB::table('patient_claim_locks')->whereNotNull('cooldown_until')->count())->toBe(1);

        $sixth = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($nid, '0123456789ABCDEF'),
            patientsAuth($session['token']) + patientsIdem('pc-cd-6'),
        );
        profileClaimAssertGenericPending($sixth, $nid, '0123456789ABCDEF');
        expect(DB::table('patient_claim_failures')->count())->toBe(5)
            ->and(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
    });

    it('issues a failed-proof lockout after fifteen failures in 24 hours', function () {
        profileClaimEnableIsolated([
            'identity.profile_claim.credential_failures_per_hour' => 100,
        ]);
        $session = patientsActiveSession('pc-lock');
        $nid = $session['payload']['national_id'];
        profileClaimIssueWalkIn($nid);
        profileClaimConsumeOtp($session, 'pc-lock');

        $last = null;
        for ($i = 1; $i <= 15; $i++) {
            $last = $this->postJson(
                '/api/v1/patients/onboarding',
                profileClaimOnboardingBody($nid, '0123456789ABCDEF'),
                patientsAuth($session['token']) + patientsIdem('pc-lock-'.$i),
            );
            profileClaimAssertGenericPending($last, $nid, '0123456789ABCDEF');
        }

        expect(DB::table('patient_claim_failures')->count())->toBe(15)
            ->and(DB::table('patient_claim_locks')->whereNotNull('locked_at')->count())->toBe(1);

        $inbox = json_encode(DB::table('notifications')->where('notifiable_id', $session['user_id'])->pluck('data')->all());
        expect($inbox)->toContain('failed_proof_lockout')
            ->and($inbox)->not->toContain($nid)
            ->and($inbox)->not->toContain('0123456789ABCDEF');

        $event = DB::table('outbox_events')->where('event_type', 'patient.claim_lockout_issued')->first();
        expect($event)->not->toBeNull();
        $payload = is_string($event->payload) ? $event->payload : json_encode($event->payload);
        expect($payload)->toContain('failed_proof_lockout')
            ->and($payload)->not->toContain($nid);
    });

    it('emits a duplicate_match conflict metric without applicant or profile labels', function () {
        $ready = profileClaimPrepareFourFactor('pc-dup');
        app()->instance(
            PatientIdentityRegistry::class,
            new DuplicateMatchPatientIdentityRegistry(app(PatientIdentityRegistry::class)),
        );

        $response = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $ready['credential']),
            patientsAuth($ready['session']['token']) + patientsIdem('pc-dup'),
        );
        profileClaimAssertGenericPending($response, $ready['nid'], $ready['credential']);
        expect(DB::table('patient_profiles')->where('id', $ready['patient_id'])->value('user_id'))->toBeNull();

        $metrics = app(PlatformMetrics::class)->render();
        expect($metrics)->toContain('clinic_profile_claim_conflicts_total')
            ->and($metrics)->toContain('reason_code="duplicate_match"')
            ->and($metrics)->not->toContain($ready['nid'])
            ->and($metrics)->not->toContain($ready['patient_id'])
            ->and($metrics)->not->toContain($ready['session']['user_id']);
    });

    it('hides profile_claim otp without a matching authenticated patient as 404', function () {
        $session = patientsActiveSession('pc-otp-authz');
        $this->postJson('/api/v1/auth/otp-requests', [
            'phone' => $session['payload']['phone'],
            'purpose' => 'profile_claim',
            'language' => 'en',
        ], patientsIdem('pc-otp-unauth'))->assertNotFound();

        $other = patientsActiveSession('pc-otp-other');
        $this->postJson('/api/v1/auth/otp-requests', [
            'phone' => $other['payload']['phone'],
            'purpose' => 'profile_claim',
            'language' => 'en',
        ], patientsAuth($session['token']) + patientsIdem('pc-otp-otherphone'))->assertNotFound();
    });

    it('stops new claims after the kill switch without unlinking a valid bind', function () {
        $ready = profileClaimPrepareFourFactor('pc-kill');
        $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($ready['nid'], $ready['credential']),
            patientsAuth($ready['session']['token']) + patientsIdem('pc-kill-1'),
        )->assertOk()->assertJsonPath('data.status', 'profile_ready');

        profileClaimDisableIsolated();
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        $other = patientsActiveSession('pc-kill-other');
        $otherHandle = profileClaimIssueWalkIn($other['payload']['national_id']);
        $blocked = $this->postJson(
            '/api/v1/patients/onboarding',
            profileClaimOnboardingBody($other['payload']['national_id'], $otherHandle->claimCredential),
            patientsAuth($other['token']) + patientsIdem('pc-kill-2'),
        );
        profileClaimAssertGenericPending($blocked, $other['payload']['national_id'], $otherHandle->claimCredential);

        expect((string) DB::table('patient_profiles')->where('id', $ready['patient_id'])->value('user_id'))
            ->toBe($ready['session']['user_id'])
            ->and(DB::table('patient_profiles')->where('id', $otherHandle->patientId->value)->value('user_id'))->toBeNull();
    });

    it('demonstrates the non-production env false disable path while production still ignores true', function () {
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeTrue();
        config(['identity.profile_claim_enabled' => false, 'app.env' => 'testing']);
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        config(['identity.profile_claim_enabled' => true, 'app.env' => 'production']);
        expect(PlatformFeatures::enabled(PlatformFeatures::IDENTITY_PROFILE_CLAIM))->toBeFalse();

        $features = (string) file_get_contents(base_path('Modules/Platform/app/Services/Features/PlatformFeatures.php'));
        expect($features)->toContain("config('app.env') === 'production'");
    });
});

describe('profile claim dispute freeze', function () {
    it('freezes a linked profile to disputed without reassignment and notifies safely', function () {
        $session = patientsActiveSession('pc-freeze');
        $created = $this->postJson(
            '/api/v1/patients/onboarding',
            patientsDemographics($session['payload']['national_id']),
            patientsAuth($session['token']) + patientsIdem('pc-freeze-on'),
        );
        $created->assertCreated();
        $patientId = Identifier::fromTrusted((string) $created->json('data.patient_id'));

        expect(fn () => app(FreezeDisputedPatientProfile::class)->handle(patientsSelfActor($session['user_id']), $patientId))
            ->toThrow(AuthorizationDenied::class);

        $status = app(FreezeDisputedPatientProfile::class)->handle(profileClaimOperatorActor(), $patientId);
        expect($status)->toBe(PatientStatus::Disputed)
            ->and((string) DB::table('patient_profiles')->where('id', $patientId->value)->value('status'))->toBe('disputed')
            ->and((string) DB::table('patient_profiles')->where('id', $patientId->value)->value('user_id'))->toBe($session['user_id']);

        $inbox = json_encode(DB::table('notifications')->where('notifiable_id', $session['user_id'])->pluck('data')->all());
        expect($inbox)->toContain('dispute_freeze')
            ->and($inbox)->not->toContain($session['payload']['national_id'])
            ->and($inbox)->not->toContain($session['payload']['phone']);

        $event = DB::table('outbox_events')->where('event_type', 'patient.profile_disputed')->first();
        expect($event)->not->toBeNull();
        $payload = is_string($event->payload) ? $event->payload : json_encode($event->payload);
        expect($payload)->toContain('dispute_freeze')
            ->and($payload)->not->toContain($session['payload']['national_id']);
    });

    it('keeps a disputed unlinked walk-in ineligible for self-service claim', function () {
        profileClaimEnableIsolated();
        try {
            $session = patientsActiveSession('pc-disp-claim');
            $nid = $session['payload']['national_id'];
            $handle = profileClaimIssueWalkIn($nid);
            app(FreezeDisputedPatientProfile::class)->handle(
                profileClaimOperatorActor(),
                $handle->patientId,
            );
            profileClaimConsumeOtp($session, 'pc-disp-claim');

            $response = $this->postJson(
                '/api/v1/patients/onboarding',
                profileClaimOnboardingBody($nid, $handle->claimCredential),
                patientsAuth($session['token']) + patientsIdem('pc-disp-claim'),
            );
            profileClaimAssertGenericPending($response, $nid, $handle->claimCredential);
            expect((string) DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('status'))->toBe('disputed')
                ->and(DB::table('patient_profiles')->where('id', $handle->patientId->value)->value('user_id'))->toBeNull();
        } finally {
            profileClaimDisableIsolated();
        }
    });
});

it('keeps observability artifacts bounded and the runbook from calling the registry unavailable', function () {
    $root = dirname(base_path(), 2);
    $alert = (string) file_get_contents($root.'/infra/monitoring/alerts/platform.yaml');
    $dashboard = (string) file_get_contents($root.'/infra/monitoring/grafana/dashboards/profile-claim.json');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/disputed-profile-link.md');
    $metrics = (string) file_get_contents(base_path('Modules/Platform/app/Services/Telemetry/PlatformMetrics.php'));

    expect($alert)->toContain('ProfileClaimDuplicateMatchConflict')
        ->and($alert)->toContain('reason_code="duplicate_match"')
        ->and($alert)->not->toContain('applicant_id')
        ->and($dashboard)->toContain('clinic_profile_claims_total')
        ->and($dashboard)->toContain('clinic_profile_claim_conflicts_total')
        ->and($dashboard)->not->toContain('applicant')
        ->and($metrics)->toContain('clinic_profile_claims_total')
        ->and($metrics)->toContain('clinic_profile_claim_conflicts_total')
        ->and($runbook)->toContain('PostgresPatientIdentityRegistry')
        ->and($runbook)->toContain('PENDING_EXTERNAL')
        ->and($runbook)->not->toContain('registry is unavailable on purpose')
        ->and($runbook)->toContain('PRODUCTION ENABLEMENT NOT AUTHORIZED');
});
