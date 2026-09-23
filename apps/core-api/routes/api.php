<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminDoctorApplicantController;
use Modules\Admin\Http\Controllers\AdminVerificationController;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Clinics\Http\Controllers\ClinicLocationController;
use Modules\Clinics\Http\Controllers\ClinicStaffInvitationController;
use Modules\Doctors\Http\Controllers\DoctorProfileController;
use Modules\Patients\Http\Controllers\PatientProfileController;
use Modules\Pharmacies\Http\Controllers\PharmacyBranchController;
use Modules\Pharmacies\Http\Controllers\PharmacyOrganizationController;
use Modules\Pharmacies\Http\Controllers\PharmacyStaffInvitationController;
use Modules\Platform\Http\Controllers\DiagnosticsController;
use Modules\Platform\Http\Controllers\PlatformHealthController;
use Modules\Verification\Http\Controllers\DoctorVerificationController;
use Modules\Verification\Http\Controllers\DoctorVerificationUploadController;
use Modules\Verification\Http\Controllers\PharmacyVerificationController;
use Modules\Verification\Http\Controllers\ReviewerDocumentDownloadController;

/*
|--------------------------------------------------------------------------
| Public API — /api/v1
|--------------------------------------------------------------------------
|
| Every externally reachable route lives here and speaks the response
| envelope, except the purpose-bound reviewer document download which
| streams canonical bytes. Operational probes (/live, /ready) are
| deliberately NOT here: they are unversioned, unenveloped, and registered
| in routes/operational.php so they can be excluded from the public
| gateway route.
|
*/

Route::prefix('v1')->group(function (): void {

    Route::get('/health', [PlatformHealthController::class, 'health'])
        ->name('api.v1.health');

    Route::get('/meta/version', [PlatformHealthController::class, 'version'])
        ->name('api.v1.meta.version');

    Route::middleware(['platform.diagnostics', 'platform.idempotency'])
        ->post('/diagnostics/round-trip', [DiagnosticsController::class, 'roundTrip'])
        ->name('api.v1.diagnostics.round-trip');

    Route::middleware('identity.session')
        ->get('/auth/csrf', [AuthController::class, 'csrf'])
        ->name('api.v1.auth.csrf');

    Route::middleware('platform.idempotency')
        ->post('/auth/registrations', [AuthController::class, 'register'])
        ->name('api.v1.auth.registrations');

    Route::middleware('platform.idempotency')
        ->post('/auth/otp-requests', [AuthController::class, 'requestOtp'])
        ->name('api.v1.auth.otp-requests');

    Route::middleware(['platform.idempotency', 'identity.session'])
        ->post('/auth/otp-verifications', [AuthController::class, 'verifyOtp'])
        ->name('api.v1.auth.otp-verifications');

    Route::middleware('identity.session')
        ->post('/auth/login', [AuthController::class, 'login'])
        ->name('api.v1.auth.login');

    Route::middleware('identity.session')
        ->post('/auth/mfa/challenges/{id}/verify', [AuthController::class, 'verifyMfa'])
        ->name('api.v1.auth.mfa.verify');

    Route::middleware('platform.idempotency')
        ->post('/auth/token/refresh', [AuthController::class, 'refresh'])
        ->name('api.v1.auth.token.refresh');

    Route::post('/auth/recovery/start', [AuthController::class, 'recoveryStart'])
        ->name('api.v1.auth.recovery.start');

    Route::middleware('platform.idempotency')
        ->post('/auth/recovery/complete', [AuthController::class, 'recoveryComplete'])
        ->name('api.v1.auth.recovery.complete');

    Route::get('/verification-review-files/{caseId}/{documentId}', [ReviewerDocumentDownloadController::class, 'show'])
        ->name('api.v1.verification-review-files.show');

    Route::middleware(['identity.session', 'auth.actor', 'auth.pending'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->name('api.v1.auth.logout');

        Route::get('/auth/sessions', [AuthController::class, 'sessions'])
            ->name('api.v1.auth.sessions.index');

        Route::delete('/auth/sessions/{sessionId}', [AuthController::class, 'destroySession'])
            ->name('api.v1.auth.sessions.destroy');

        Route::middleware('platform.idempotency')
            ->post('/auth/sessions/revoke-all', [AuthController::class, 'revokeAll'])
            ->name('api.v1.auth.sessions.revoke-all');

        Route::post('/auth/password/change', [AuthController::class, 'changePassword'])
            ->name('api.v1.auth.password.change');

        Route::post('/auth/mfa/totp/enroll', [AuthController::class, 'enrollTotp'])
            ->name('api.v1.auth.mfa.totp.enroll');

        Route::post('/auth/mfa/totp/confirm', [AuthController::class, 'confirmTotp'])
            ->name('api.v1.auth.mfa.totp.confirm');

        Route::post('/auth/mfa/recovery-codes/rotate', [AuthController::class, 'rotateRecoveryCodes'])
            ->name('api.v1.auth.mfa.recovery-codes.rotate');

        Route::post('/auth/mfa/totp/disable', [AuthController::class, 'disableTotp'])
            ->name('api.v1.auth.mfa.totp.disable');

        Route::post('/auth/recovery/requests/{id}/apply', [AuthController::class, 'applyRecovery'])
            ->name('api.v1.auth.recovery.apply');

        Route::get('/me', [AuthController::class, 'me'])
            ->name('api.v1.me.show');

        Route::get('/me/capabilities', [AuthController::class, 'capabilities'])
            ->name('api.v1.me.capabilities');

        Route::middleware('platform.idempotency')
            ->post('/patients/onboarding', [PatientProfileController::class, 'onboard'])
            ->name('api.v1.patients.onboarding');

        Route::get('/patients/me/profile', [PatientProfileController::class, 'me'])
            ->name('api.v1.patients.me.profile');

        Route::patch('/patients/me/demographics', [PatientProfileController::class, 'updateDemographics'])
            ->name('api.v1.patients.me.demographics');

        Route::middleware('platform.idempotency')
            ->post('/doctors/onboarding', [DoctorProfileController::class, 'onboard'])
            ->name('api.v1.doctors.onboarding');

        Route::get('/doctors/me/profile', [DoctorProfileController::class, 'me'])
            ->name('api.v1.doctors.me.profile');

        Route::get('/doctors/specialties', [DoctorProfileController::class, 'specialties'])
            ->name('api.v1.doctors.specialties');

        Route::middleware('platform.idempotency')
            ->post('/pharmacy-organizations/onboarding', [PharmacyOrganizationController::class, 'onboard'])
            ->name('api.v1.pharmacy-organizations.onboarding');

        Route::get('/pharmacy-organizations/me', [PharmacyOrganizationController::class, 'me'])
            ->name('api.v1.pharmacy-organizations.me');

        Route::get('/pharmacy-organizations/{organizationId}/branches', [PharmacyBranchController::class, 'index'])
            ->name('api.v1.pharmacy-organizations.branches.index');

        Route::middleware('platform.idempotency')
            ->post('/pharmacy-organizations/{organizationId}/branches', [PharmacyBranchController::class, 'store'])
            ->name('api.v1.pharmacy-organizations.branches.store');

        Route::get('/pharmacy-organizations/{organizationId}/branches/{branchId}', [PharmacyBranchController::class, 'show'])
            ->name('api.v1.pharmacy-organizations.branches.show');

        Route::patch('/pharmacy-organizations/{organizationId}/branches/{branchId}', [PharmacyBranchController::class, 'update'])
            ->name('api.v1.pharmacy-organizations.branches.update');

        Route::middleware('platform.idempotency')
            ->post('/pharmacy-organizations/{organizationId}/branches/{branchId}/staff-invitations', [PharmacyBranchController::class, 'invite'])
            ->name('api.v1.pharmacy-organizations.branches.staff-invitations');

        Route::get('/pharmacy-organizations/{organizationId}/branches/{branchId}/memberships', [PharmacyBranchController::class, 'memberships'])
            ->name('api.v1.pharmacy-organizations.branches.memberships.index');

        Route::delete('/pharmacy-organizations/{organizationId}/branches/{branchId}/memberships/{membershipId}', [PharmacyBranchController::class, 'revokeMembership'])
            ->name('api.v1.pharmacy-organizations.branches.memberships.destroy');

        Route::middleware('platform.idempotency')
            ->post('/pharmacy-staff-invitations/{invitationId}/accept', [PharmacyStaffInvitationController::class, 'accept'])
            ->name('api.v1.pharmacy-staff-invitations.accept');

        Route::get('/clinic-locations', [ClinicLocationController::class, 'index'])
            ->name('api.v1.clinic-locations.index');

        Route::middleware('platform.idempotency')
            ->post('/clinic-locations', [ClinicLocationController::class, 'store'])
            ->name('api.v1.clinic-locations.store');

        Route::get('/clinic-locations/{locationId}', [ClinicLocationController::class, 'show'])
            ->name('api.v1.clinic-locations.show');

        Route::patch('/clinic-locations/{locationId}', [ClinicLocationController::class, 'update'])
            ->name('api.v1.clinic-locations.update');

        Route::middleware('platform.idempotency')
            ->post('/clinic-locations/{locationId}/staff-invitations', [ClinicLocationController::class, 'invite'])
            ->name('api.v1.clinic-locations.staff-invitations');

        Route::get('/clinic-locations/{locationId}/memberships', [ClinicLocationController::class, 'memberships'])
            ->name('api.v1.clinic-locations.memberships.index');

        Route::delete('/clinic-locations/{locationId}/memberships/{membershipId}', [ClinicLocationController::class, 'revokeMembership'])
            ->name('api.v1.clinic-locations.memberships.destroy');

        Route::middleware('platform.idempotency')
            ->post('/clinic-staff-invitations/{invitationId}/accept', [ClinicStaffInvitationController::class, 'accept'])
            ->name('api.v1.clinic-staff-invitations.accept');

        Route::middleware('platform.idempotency')
            ->post('/pharmacy-organizations/me/verification-cases', [PharmacyVerificationController::class, 'open'])
            ->name('api.v1.pharmacy-organizations.me.verification-cases');

        Route::middleware('platform.idempotency')
            ->post('/pharmacy-organizations/me/verification-submissions', [PharmacyVerificationController::class, 'submit'])
            ->name('api.v1.pharmacy-organizations.me.verification-submissions');

        Route::get('/pharmacy-organizations/me/verification-status', [PharmacyVerificationController::class, 'status'])
            ->name('api.v1.pharmacy-organizations.me.verification-status');

        Route::get('/pharmacy-organizations/{organizationId}/verification-status', [PharmacyVerificationController::class, 'statusForOrganization'])
            ->name('api.v1.pharmacy-organizations.verification-status');

        Route::middleware('platform.idempotency')
            ->post('/doctors/me/verification-cases', [DoctorVerificationController::class, 'open'])
            ->name('api.v1.doctors.me.verification-cases');

        Route::middleware('platform.idempotency')
            ->post('/doctors/me/verification-submissions', [DoctorVerificationController::class, 'submit'])
            ->name('api.v1.doctors.me.verification-submissions');

        Route::get('/doctors/me/verification-status', [DoctorVerificationController::class, 'status'])
            ->name('api.v1.doctors.me.verification-status');

        Route::middleware('platform.idempotency')
            ->post('/verification-uploads', [DoctorVerificationUploadController::class, 'create'])
            ->name('api.v1.verification-uploads.create');

        Route::middleware('platform.idempotency')
            ->post('/verification-uploads/{uploadId}/complete', [DoctorVerificationUploadController::class, 'complete'])
            ->name('api.v1.verification-uploads.complete');

        Route::get('/verification-uploads/{uploadId}', [DoctorVerificationUploadController::class, 'show'])
            ->name('api.v1.verification-uploads.show');

        Route::get('/admin/doctor-applicants/specialties', [AdminDoctorApplicantController::class, 'specialties'])
            ->name('api.v1.admin.doctor-applicants.specialties');

        Route::middleware('platform.idempotency')
            ->post('/admin/doctor-applicants', [AdminDoctorApplicantController::class, 'store'])
            ->name('api.v1.admin.doctor-applicants.store');

        Route::middleware('platform.idempotency')
            ->post('/admin/doctor-applicants/{doctorId}/verification-uploads', [AdminDoctorApplicantController::class, 'createUpload'])
            ->name('api.v1.admin.doctor-applicants.verification-uploads');

        Route::middleware('platform.idempotency')
            ->post('/admin/doctor-applicants/{doctorId}/verification-submissions', [AdminDoctorApplicantController::class, 'submit'])
            ->name('api.v1.admin.doctor-applicants.verification-submissions');

        Route::get('/admin/verification-cases', [AdminVerificationController::class, 'index'])
            ->name('api.v1.admin.verification-cases.index');

        Route::get('/admin/verification-cases/{caseId}', [AdminVerificationController::class, 'show'])
            ->name('api.v1.admin.verification-cases.show');

        Route::post('/admin/verification-cases/{caseId}/claim', [AdminVerificationController::class, 'claim'])
            ->name('api.v1.admin.verification-cases.claim');

        Route::middleware('platform.idempotency')
            ->post('/admin/verification-cases/{caseId}/decisions', [AdminVerificationController::class, 'decide'])
            ->name('api.v1.admin.verification-cases.decisions');

        Route::post('/admin/verification-cases/{caseId}/documents/{documentId}/access', [AdminVerificationController::class, 'documentAccess'])
            ->name('api.v1.admin.verification-cases.documents.access');
    });
});
