/** @vitest-environment jsdom */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type {
  DoctorClinicBridge,
  DoctorProfileView,
  DoctorVerificationStatus,
} from '@clinic/desktop-bridge-contracts';
import { App } from './App';

const CANARIES = {
  nationalId: 'CANARY-NATIONAL-ID-29801011234567',
  syndicate: 'CANARY-SYNDICATE-998877',
  path: '/tmp/clinic-canary-evidence.pdf',
  signedUrl: 'https://objects.example/upload?X-Amz-Signature=CANARY-SIGNATURE',
  signature: 'CANARY-SIGNATURE',
  locator: 'verification/q/canary-object-key',
  objectId: '0199a5c8-ffff-7000-8000-000000000099',
  notes: 'reviewer-private-notes-must-not-cross-ipc',
  access: 'canary-access-token-value',
  refresh: 'canary-refresh-token-value',
};

const DOCTOR_ID = '0199a5c8-0000-7000-8000-000000000010';
const SPECIALTY_ID = '0199a5c8-0000-7000-8000-000000000002';
const CASE_ID = '0199a5c8-0000-7000-8000-000000000040';
const UPLOAD_ID = '0199a5c8-0000-7000-8000-000000000021';

function ok<T>(value: T) {
  return Promise.resolve({ ok: true as const, value });
}

function fail(
  code:
    | 'UNAUTHENTICATED'
    | 'PERMISSION_DENIED'
    | 'NOT_FOUND'
    | 'VERSION_CONFLICT'
    | 'STATE_CONFLICT'
    | 'VALIDATION_FAILED'
    | 'TIMEOUT',
  message = 'failed',
) {
  return Promise.resolve({ ok: false as const, error: { code, message } });
}

function profile(
  verificationStatus: DoctorProfileView['verificationStatus'] = 'draft',
): DoctorProfileView {
  return {
    doctorId: DOCTOR_ID,
    professionalDisplayName: 'Synthetic Doctor',
    specialtyId: SPECIALTY_ID,
    specialtyCode: 'gp',
    specialtyLabelAr: 'طب الأسرة',
    specialtyLabelEn: 'General Practice',
    verificationStatus,
    publicStatus: verificationStatus === 'approved' ? 'listed' : 'hidden',
    version: 1,
    approvedAt: verificationStatus === 'approved' ? '2026-09-21T00:02:00Z' : null,
    suspendedAt: verificationStatus === 'suspended' ? '2026-09-21T00:03:00Z' : null,
    createdAt: '2026-09-21T00:00:00Z',
    updatedAt: '2026-09-21T00:00:00Z',
  };
}

function verification(
  overrides: Partial<DoctorVerificationStatus> = {},
): DoctorVerificationStatus {
  return {
    applicantType: 'doctor',
    doctorId: DOCTOR_ID,
    profileVerificationStatus: 'draft',
    profilePublicStatus: 'hidden',
    profileVersion: 1,
    caseId: CASE_ID,
    caseStatus: 'draft',
    caseVersion: 1,
    caseType: 'doctor_verification',
    submittedAt: null,
    decidedAt: null,
    decision: null,
    reasonCode: null,
    documents: [],
    ...overrides,
  };
}

function cleanDoc(code: string, suffix: string) {
  return {
    documentId: `0199a5c8-0000-7000-8000-0000000000${suffix}`,
    requirementCode: code,
    scanStatus: 'clean' as const,
    status: 'available' as const,
    uploadedAt: '2026-09-21T00:01:00Z',
  };
}

function bothRequiredDocs() {
  return [cleanDoc('medical_license', '51'), cleanDoc('national_id_or_passport', '52')];
}

function doctorMe() {
  return ok({
    userId: '0199a5c8-0000-7000-8000-000000000001',
    accountType: 'doctor' as const,
    status: 'active',
    language: 'en' as const,
    assuranceLevel: 'aal2_totp',
    capabilities: [],
  });
}

function installBridge(
  overrides: Partial<DoctorClinicBridge['doctor']> = {},
  authOverrides: Partial<DoctorClinicBridge['auth']> = {},
) {
  let present = false;
  const doctor: DoctorClinicBridge['doctor'] = {
    getOwnProfile: () =>
      ok(present ? { present: true as const, profile: profile() } : { present: false as const }),
    listSpecialties: () =>
      ok({
        specialties: [
          {
            specialtyId: SPECIALTY_ID,
            code: 'gp',
            labelAr: 'طب الأسرة',
            labelEn: 'General Practice',
            sortOrder: 1,
          },
        ],
      }),
    onboard: async (input) => {
      expect(JSON.stringify(input)).toContain(CANARIES.nationalId);
      present = true;
      return ok({
        status: 'profile_ready' as const,
        doctorId: DOCTOR_ID,
        version: 1,
      });
    },
    openVerificationCase: () =>
      ok({
        status: 'ready' as const,
        doctorId: DOCTOR_ID,
        caseId: CASE_ID,
        caseStatus: 'draft' as const,
        caseVersion: 1,
        profileVersion: 1,
      }),
    verificationStatus: () => ok(verification()),
    submitVerification: () =>
      ok({
        status: 'submitted' as const,
        doctorId: DOCTOR_ID,
        caseId: CASE_ID,
        caseStatus: 'pending_review' as const,
        caseVersion: 2,
        profileVersion: 1,
        profileVerificationStatus: 'pending_review' as const,
      }),
    selectEvidence: async (input) =>
      ok({
        selected: true as const,
        handleId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        displayName: `${input.requirementCode}.pdf`,
        sizeBytes: 1200,
        candidateMediaType: 'application/pdf' as const,
        requirementCode: input.requirementCode,
      }),
    clearEvidence: () => ok({ cleared: true as const }),
    uploadEvidence: () =>
      ok({
        uploadId: UPLOAD_ID,
        requirementCode: 'medical_license',
        state: 'quarantined' as const,
        rejectionReason: null,
        expiresAt: '2026-09-21T00:10:00Z',
        completedAt: '2026-09-21T00:01:00Z',
      }),
    uploadStatus: () =>
      ok({
        uploadId: UPLOAD_ID,
        requirementCode: 'medical_license',
        state: 'available' as const,
        rejectionReason: null,
        expiresAt: '2026-09-21T00:10:00Z',
        completedAt: '2026-09-21T00:01:00Z',
      }),
    listLocations: () => ok({ locations: [], hasMore: false, nextCursor: null }),
    createLocation: () => fail('NOT_FOUND'),
    getLocation: () => fail('NOT_FOUND'),
    updateLocation: () => fail('NOT_FOUND'),
    inviteStaff: () => fail('NOT_FOUND'),
    listMemberships: () => ok({ memberships: [] }),
    revokeMembership: () => fail('NOT_FOUND'),
    ...overrides,
  };

  const clinic: DoctorClinicBridge = {
    contractVersion: 1,
    app: {
      metadata: () =>
        ok({
          appId: 'eg.clinic.doctor.desktop',
          productName: 'Clinic Doctor',
          appVersion: '0.1.0',
          contractVersion: 1 as const,
        }),
    },
    platform: {
      health: () =>
        ok({
          status: 'operational',
          message: 'ok',
          components: { core: 'operational', realtime: 'operational', ai: 'operational' },
          version: '0.1.0',
          serverTime: '2026-09-21T00:00:00Z',
        }),
      version: () =>
        ok({
          service: 'core',
          version: '0.1.0',
          apiVersion: 'v1',
          environment: 'local',
        }),
    },
    locale: {
      get: () => ok({ locale: 'en' as const }),
      set: (locale) => ok({ locale }),
    },
    auth: {
      secureStatus: () => ok({ available: true, backend: 'test' }),
      login: () =>
        ok({
          status: 'mfa_required',
          mfaRequired: true,
          challengeId: '0199a5c8-0000-7000-8000-000000000099',
          accountType: 'doctor',
        }),
      verifyMfa: () =>
        ok({
          status: 'active',
          mfaRequired: false,
          accountType: 'doctor',
        }),
      logout: () => ok({ revoked: true as const }),
      me: () => fail('UNAUTHENTICATED'),
      sessions: () => ok({ sessions: [] }),
      revokeSession: () => ok({ revoked: true as const }),
      ...authOverrides,
    },
    doctor,
  };

  Object.defineProperty(window, 'clinic', { value: clinic, configurable: true, writable: true });
  return clinic;
}

function renderApp() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0 }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  );
}

function assertNoCanaries(haystack: string): void {
  expect(haystack).not.toContain(CANARIES.path);
  expect(haystack).not.toContain(CANARIES.signedUrl);
  expect(haystack).not.toContain(CANARIES.signature);
  expect(haystack).not.toContain(CANARIES.locator);
  expect(haystack).not.toContain(CANARIES.objectId);
  expect(haystack).not.toContain(CANARIES.notes);
  expect(haystack).not.toContain(CANARIES.access);
  expect(haystack).not.toContain(CANARIES.refresh);
}

function assertNoClinicalNav(): void {
  expect(screen.queryByTestId('clinical-nav')).toBeNull();
  expect(screen.queryByRole('button', { name: /schedule|queue|appointment|encounter|prescription|lab/i })).toBeNull();
}

function signedInDoctor(clinic: DoctorClinicBridge): void {
  clinic.auth.me = () => doctorMe();
}

describe('doctor renderer workspace', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    document.cookie = '';
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      configurable: true,
      value: (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => undefined,
        removeListener: () => undefined,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        dispatchEvent: () => false,
      }),
    });
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.location.hash = '';
  });

  it('shows the signed-out login shell', async () => {
    installBridge();
    renderApp();
    expect(await screen.findByTestId('login-form')).toBeTruthy();
    expect(screen.queryByTestId('doctor-workspace')).toBeNull();
    expect(screen.queryByTestId('session-panel')).toBeNull();
  });

  it('signs in with MFA, onboards from the specialty catalogue, and never persists canaries', async () => {
    const clinic = installBridge();
    clinic.auth.me = vi
      .fn()
      .mockResolvedValueOnce(fail('UNAUTHENTICATED'))
      .mockResolvedValue(doctorMe());

    renderApp();
    expect(await screen.findByTestId('login-form')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Mobile number'), { target: { value: '01900000001' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct-horse-battery' } });
    fireEvent.click(screen.getByTestId('sign-in'));
    fireEvent.change(await screen.findByLabelText('Authenticator code'), { target: { value: '123456' } });
    fireEvent.click(screen.getByTestId('sign-in'));

    expect(await screen.findByTestId('onboarding-form')).toBeTruthy();
    expect(await screen.findByRole('option', { name: 'General Practice' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('National ID'), { target: { value: CANARIES.nationalId } });
    fireEvent.change(screen.getByLabelText('Professional display name'), { target: { value: 'Synthetic Doctor' } });
    fireEvent.change(screen.getByTestId('specialty-select'), { target: { value: SPECIALTY_ID } });
    fireEvent.change(screen.getByLabelText('Syndicate number (optional)'), { target: { value: CANARIES.syndicate } });
    fireEvent.click(screen.getByRole('button', { name: 'Create doctor profile' }));

    expect(await screen.findByTestId('verification-workspace')).toBeTruthy();
    expect(screen.queryByDisplayValue(CANARIES.nationalId)).toBeNull();
    expect(screen.queryByDisplayValue(CANARIES.syndicate)).toBeNull();
    const html = document.body.innerHTML;
    expect(html).not.toContain(CANARIES.nationalId);
    expect(html).not.toContain(CANARIES.syndicate);
    assertNoCanaries(html);
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(document.cookie).not.toMatch(/access_token|refresh_token|CANARY/);
  });

  it('renders a generic manual-review state without identity comparison', async () => {
    const clinic = installBridge({
      onboard: async () => ok({ status: 'manual_review_required' as const }),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('onboarding-form')).toBeTruthy();
    expect(await screen.findByRole('option', { name: 'General Practice' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('National ID'), { target: { value: CANARIES.nationalId } });
    fireEvent.change(screen.getByLabelText('Professional display name'), { target: { value: 'Synthetic Doctor' } });
    fireEvent.change(screen.getByTestId('specialty-select'), { target: { value: SPECIALTY_ID } });
    fireEvent.click(screen.getByRole('button', { name: 'Create doctor profile' }));
    expect(await screen.findByTestId('manual-review')).toBeTruthy();
    const html = document.body.innerHTML;
    expect(html).not.toContain(CANARIES.nationalId);
    expect(html).not.toContain('duplicate');
    expect(html).not.toContain('another doctor');
    expect(screen.queryByTestId('verification-workspace')).toBeNull();
  });

  it('fail-closes a non-doctor account', async () => {
    const clinic = installBridge();
    clinic.auth.me = () =>
      ok({
        userId: '0199a5c8-0000-7000-8000-000000000001',
        accountType: 'pharmacy',
        status: 'active',
        language: 'en',
        assuranceLevel: 'aal2_totp',
        capabilities: [],
      });

    renderApp();
    expect(await screen.findByTestId('account-denied')).toBeTruthy();
    expect(screen.queryByTestId('onboarding-form')).toBeNull();
  });

  it('does not put the signed upload target or file path in the DOM', async () => {
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
    });
    signedInDoctor(clinic);

    renderApp();
    expect(await screen.findByTestId('verification-workspace')).toBeTruthy();
    fireEvent.click(await screen.findByTestId('select-evidence-medical_license'));
    expect(await screen.findByText(/medical_license\.pdf/)).toBeTruthy();
    fireEvent.click(screen.getByTestId('upload-evidence-medical_license'));
    expect(await screen.findByTestId('upload-status-medical_license')).toBeTruthy();
    const html = document.body.innerHTML;
    assertNoCanaries(html);
    expect(html).not.toContain(CANARIES.path);
    expect(JSON.stringify(await clinic.doctor.selectEvidence({ requirementCode: 'medical_license' }))).not.toContain(
      CANARIES.path,
    );
  });

  it('shows scanning then available before submit is enabled', async () => {
    let documents: DoctorVerificationStatus['documents'] = [];
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
      verificationStatus: () => ok(verification({ documents })),
      uploadStatus: () =>
        ok({
          uploadId: UPLOAD_ID,
          requirementCode: 'medical_license',
          state: 'scanning',
          rejectionReason: null,
          expiresAt: '2026-09-21T00:10:00Z',
          completedAt: '2026-09-21T00:01:00Z',
        }),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('requirement-slot-medical_license')).toBeTruthy();
    expect(screen.getByTestId('requirement-slot-national_id_or_passport')).toBeTruthy();
    expect(screen.getByTestId('requirement-slot-syndicate_card')).toBeTruthy();
    fireEvent.click(await screen.findByTestId('select-evidence-medical_license'));
    fireEvent.click(await screen.findByTestId('upload-evidence-medical_license'));
    expect(await screen.findByText(/Scanning and validating/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', true);
    documents = [cleanDoc('medical_license', '51')];
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', true);
    });
    documents = bothRequiredDocs();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', false);
    });
  });

  it('submits draft evidence and presents pending review without clinical navigation', async () => {
    let status = verification({ documents: bothRequiredDocs() });
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
      verificationStatus: () => ok(status),
      submitVerification: async () => {
        status = verification({
          profileVerificationStatus: 'pending_review',
          caseStatus: 'pending_review',
          caseVersion: 2,
          decision: null,
        });
        return ok({
          status: 'submitted' as const,
          doctorId: DOCTOR_ID,
          caseId: CASE_ID,
          caseStatus: 'pending_review' as const,
          caseVersion: 2,
          profileVersion: 1,
          profileVerificationStatus: 'pending_review' as const,
        });
      },
    });
    signedInDoctor(clinic);
    renderApp();
    fireEvent.click(await screen.findByTestId('select-evidence-medical_license'));
    fireEvent.click(screen.getByTestId('upload-evidence-medical_license'));
    expect(await screen.findByTestId('upload-status-medical_license')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Submit for review' }));
    expect(await screen.findByTestId('pending-status')).toBeTruthy();
    assertNoClinicalNav();
  });

  it('opens a case from the no-case state', async () => {
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
      verificationStatus: vi
        .fn()
        .mockResolvedValueOnce(ok(verification({ caseId: null, caseStatus: null, caseVersion: null })))
        .mockResolvedValue(ok(verification())),
    });
    signedInDoctor(clinic);
    renderApp();
    fireEvent.click(await screen.findByRole('button', { name: 'Open or resume verification case' }));
    expect(await screen.findByTestId('select-evidence-medical_license')).toBeTruthy();
  });

  it('shows changes requested with a safe reason and a new-case action', async () => {
    const clinic = installBridge({
      getOwnProfile: () =>
        ok({ present: true as const, profile: profile('changes_requested') }),
      verificationStatus: () =>
        ok(
          verification({
            profileVerificationStatus: 'changes_requested',
            caseStatus: 'changes_requested',
            decision: 'changes_requested',
            reasonCode: 'docs_blurry_or_illegible',
            applicantSafeExplanation:
              'صورة المستند المقدم غير واضحة أو يتعذر قراءة البيانات منها. يرجى إعادة رفع نسخة جيدة.',
          }),
        ),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('changes-requested-status')).toBeTruthy();
    expect(screen.getByTestId('verification-reason').textContent).toContain(
      'صورة المستند المقدم غير واضحة أو يتعذر قراءة البيانات منها. يرجى إعادة رفع نسخة جيدة.',
    );
    expect(screen.getByTestId('verification-reason').textContent).not.toContain('docs_blurry_or_illegible');
    expect(screen.getByRole('button', { name: 'Start a new verification case' })).toBeTruthy();
    assertNoClinicalNav();
    expect(document.body.innerHTML).not.toContain(CANARIES.notes);
  });

  it('shows rejected and suspended isolation without clinical navigation', async () => {
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile('rejected') }),
      verificationStatus: () =>
        ok(
          verification({
            profileVerificationStatus: 'rejected',
            caseStatus: 'rejected',
            decision: 'rejected',
            reasonCode: 'evidence_unreadable',
          }),
        ),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('rejected-status')).toBeTruthy();
    assertNoClinicalNav();
    cleanup();

    const suspended = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile('suspended') }),
      verificationStatus: () =>
        ok(
          verification({
            profileVerificationStatus: 'suspended',
            caseStatus: 'rejected',
          }),
        ),
    });
    signedInDoctor(suspended);
    renderApp();
    expect(await screen.findByTestId('suspended-status')).toBeTruthy();
    expect(screen.getByTestId('profile-suspended')).toBeTruthy();
    assertNoClinicalNav();
  });

  it('shows approved verification without Phase-03 clinical screens', async () => {
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile('approved') }),
      verificationStatus: () =>
        ok(
          verification({
            profileVerificationStatus: 'approved',
            profilePublicStatus: 'listed',
            caseStatus: 'approved',
            decision: 'approved',
          }),
        ),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('approved-status')).toBeTruthy();
    expect(screen.getByTestId('doctor-profile').textContent).toContain('Synthetic Doctor');
    expect(screen.getByTestId('practice-locations-nav')).toBeTruthy();
    assertNoClinicalNav();
    expect(screen.queryByTestId('tab-schedule')).toBeNull();
    expect(screen.queryByTestId('tab-appointment-types')).toBeNull();
  });

  it('refreshes authoritative status on version conflict instead of overwriting', async () => {
    const statusFn = vi
      .fn()
      .mockResolvedValueOnce(ok(verification({ documents: bothRequiredDocs() })))
      .mockResolvedValue(ok(verification({ caseVersion: 3, profileVersion: 2, documents: bothRequiredDocs() })));
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
      verificationStatus: statusFn,
      submitVerification: () => fail('VERSION_CONFLICT'),
    });
    signedInDoctor(clinic);
    renderApp();
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', false);
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit for review' }));
    expect(await screen.findByTestId('verification-message')).toBeTruthy();
    await waitFor(() => {
      expect(statusFn.mock.calls.length).toBeGreaterThan(1);
    });
  });

  it('logs out back to the signed-out shell', async () => {
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('doctor-workspace')).toBeTruthy();
    fireEvent.click(screen.getByTestId('sign-out'));
    expect(await screen.findByTestId('login-form')).toBeTruthy();
    expect(screen.queryByTestId('doctor-workspace')).toBeNull();
  });

  it('switches to Arabic RTL without persisting National ID', async () => {
    const clinic = installBridge();
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('onboarding-form')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('National ID'), { target: { value: CANARIES.nationalId } });
    fireEvent.change(screen.getByTestId('language-select'), { target: { value: 'ar' } });
    await waitFor(() => {
      expect(document.documentElement.getAttribute('dir')).toBe('rtl');
      expect(document.documentElement.getAttribute('lang')).toBe('ar');
    });
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
  });

  it('gates submit on both required doctor documents and leaves syndicate optional', async () => {
    let documents: DoctorVerificationStatus['documents'] = [];
    const selected: string[] = [];
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile() }),
      verificationStatus: () => ok(verification({ documents })),
      selectEvidence: async (input) => {
        selected.push(input.requirementCode);
        return ok({
          selected: true as const,
          handleId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          displayName: `${input.requirementCode}.pdf`,
          sizeBytes: 1200,
          candidateMediaType: 'application/pdf' as const,
          requirementCode: input.requirementCode,
        });
      },
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('requirement-slot-medical_license')).toBeTruthy();
    expect(screen.getByTestId('requirement-slot-national_id_or_passport')).toBeTruthy();
    expect(screen.getByTestId('requirement-slot-syndicate_card')).toBeTruthy();
    expect(screen.getByText(/Professional Medical License \(required\)/)).toBeTruthy();
    expect(screen.getByText(/Government Photo ID \(required\)/)).toBeTruthy();
    expect(screen.getByText(/Medical Syndicate Membership Card \(optional\)/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', true);

    fireEvent.click(screen.getByTestId('select-evidence-medical_license'));
    expect(selected).toEqual(['medical_license']);
    documents = [cleanDoc('medical_license', '51')];
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', true);
    });

    documents = [cleanDoc('national_id_or_passport', '52')];
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', true);
    });

    documents = bothRequiredDocs();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Submit for review' })).toHaveProperty('disabled', false);
    });
  });

  it('opens a new case after changes_requested and rejected without showing reviewer notes', async () => {
    const opened: string[] = [];
    const clinic = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile('changes_requested') }),
      openVerificationCase: async () => {
        opened.push('opened');
        return ok({
          status: 'ready' as const,
          doctorId: DOCTOR_ID,
          caseId: CASE_ID,
          caseStatus: 'draft' as const,
          caseVersion: 1,
          profileVersion: 1,
        });
      },
      verificationStatus: () =>
        ok(
          verification({
            profileVerificationStatus: 'changes_requested',
            caseStatus: 'changes_requested',
            decision: 'changes_requested',
            reasonCode: 'identity_mismatch',
            applicantSafeExplanation:
              'البيانات المدخلة في الطلب لا تتطابق مع البيانات الموجودة في المستندات المرفقة.',
          }),
        ),
    });
    signedInDoctor(clinic);
    renderApp();
    expect(await screen.findByTestId('changes-requested-status')).toBeTruthy();
    expect(screen.getByTestId('verification-reason').textContent).toContain(
      'البيانات المدخلة في الطلب لا تتطابق مع البيانات الموجودة في المستندات المرفقة.',
    );
    expect(document.body.innerHTML).not.toContain(CANARIES.notes);
    fireEvent.click(screen.getByRole('button', { name: 'Start a new verification case' }));
    await waitFor(() => {
      expect(opened).toHaveLength(1);
    });
    cleanup();

    const rejected = installBridge({
      getOwnProfile: () => ok({ present: true as const, profile: profile('rejected') }),
      verificationStatus: () =>
        ok(
          verification({
            profileVerificationStatus: 'rejected',
            caseStatus: 'rejected',
            decision: 'rejected',
            reasonCode: 'unauthorized_entity',
            applicantSafeExplanation:
              'المنشأة أو المتقدم لا يستوفي الشروط التنظيمية للتسجيل في المنصة.',
          }),
        ),
    });
    signedInDoctor(rejected);
    renderApp();
    expect(await screen.findByTestId('rejected-status')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Start a new verification case' })).toBeTruthy();
    expect(screen.getByTestId('verification-reason').textContent).not.toContain('unauthorized_entity');
  });
});
