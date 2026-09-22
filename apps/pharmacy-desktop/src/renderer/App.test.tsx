/** @vitest-environment jsdom */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { PharmacyClinicBridge, PharmacyOrganizationView } from '@clinic/desktop-bridge-contracts';
import { App } from './App';

const CANARIES = {
  legalName: 'CANARY-LEGAL-PHARMACY-NAME',
  registration: 'CANARY-REG-CR-998877',
  address: 'CANARY-BRANCH-ADDRESS-99 Nile St',
  phone: '01911112222',
  path: '/tmp/clinic-canary-evidence.pdf',
  signedUrl: 'https://objects.example/upload?X-Amz-Signature=CANARY-SIGNATURE',
  signature: 'CANARY-SIGNATURE',
  locator: 'verification/q/canary-object-key',
  objectId: '0199a5c8-ffff-7000-8000-000000000099',
  notes: 'reviewer-private-notes-must-not-cross-ipc',
  access: 'canary-access-token-value',
  refresh: 'canary-refresh-token-value',
};

const ORG_ID = '0199a5c8-0000-7000-8000-000000000010';
const CASE_ID = '0199a5c8-0000-7000-8000-000000000040';
const UPLOAD_ID = '0199a5c8-0000-7000-8000-000000000021';

function ok<T>(value: T) {
  return Promise.resolve({ ok: true as const, value });
}

function fail(code: 'UNAUTHENTICATED' | 'PERMISSION_DENIED', message = 'failed') {
  return Promise.resolve({ ok: false as const, error: { code, message } });
}

function organization(status: 'draft' | 'pending' | 'active' = 'draft'): PharmacyOrganizationView {
  const verificationStatus =
    status === 'active' ? 'approved' : status === 'pending' ? 'pending_review' : 'draft';
  const membershipStatus = status === 'active' ? 'active' : 'pending';
  return {
    organizationId: ORG_ID,
    publicName: 'Safe Pharmacy',
    verificationStatus,
    status,
    version: 1,
    createdAt: '2026-09-21T00:00:00Z',
    updatedAt: '2026-09-21T00:00:00Z',
    initialBranch: {
      branchId: '0199a5c8-0000-7000-8000-000000000011',
      publicName: 'Main',
      countryCode: 'EG',
      status,
      version: 1,
    },
    membership: {
      membershipId: '0199a5c8-0000-7000-8000-000000000012',
      role: 'owner',
      status: membershipStatus,
    },
  };
}

function installBridge(overrides: Partial<PharmacyClinicBridge['pharmacy']> & Record<string, unknown> = {}) {
  let present = false;
  const pharmacy: PharmacyClinicBridge['pharmacy'] = {
    getOwnOrganization: () =>
      ok(present ? { present: true as const, organization: organization() } : { present: false as const }),
    onboard: async (input) => {
      expect(JSON.stringify(input)).toContain(CANARIES.legalName);
      present = true;
      return ok({
        status: 'organization_ready' as const,
        organizationId: ORG_ID,
        branchId: '0199a5c8-0000-7000-8000-000000000011',
        membershipId: '0199a5c8-0000-7000-8000-000000000012',
        version: 1,
      });
    },
    openVerificationCase: () =>
      ok({
        status: 'ready' as const,
        organizationId: ORG_ID,
        caseId: CASE_ID,
        caseStatus: 'draft' as const,
        caseVersion: 1,
        organizationVersion: 1,
      }),
    verificationStatus: () =>
      ok({
        applicantType: 'pharmacy' as const,
        organizationId: ORG_ID,
        organizationVerificationStatus: 'draft' as const,
        organizationStatus: 'draft' as const,
        organizationVersion: 1,
        caseId: CASE_ID,
        caseStatus: 'draft' as const,
        caseVersion: 1,
        caseType: 'pharmacy_verification' as const,
        submittedAt: null,
        decidedAt: null,
        decision: null,
        reasonCode: null,
        documents: [],
      }),
    submitVerification: () =>
      ok({
        status: 'submitted' as const,
        organizationId: ORG_ID,
        caseId: CASE_ID,
        caseStatus: 'pending_review' as const,
        caseVersion: 2,
        organizationVersion: 1,
        organizationVerificationStatus: 'pending_review' as const,
      }),
    selectEvidence: () =>
      ok({
        selected: true as const,
        handleId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        displayName: 'registration.pdf',
        sizeBytes: 1200,
        candidateMediaType: 'application/pdf' as const,
      }),
    clearEvidence: () => ok({ cleared: true as const }),
    uploadEvidence: () =>
      ok({
        uploadId: UPLOAD_ID,
        requirementCode: 'organization_registration_evidence',
        state: 'quarantined' as const,
        rejectionReason: null,
        expiresAt: '2026-09-21T00:10:00Z',
        completedAt: '2026-09-21T00:01:00Z',
      }),
    uploadStatus: () =>
      ok({
        uploadId: UPLOAD_ID,
        requirementCode: 'organization_registration_evidence',
        state: 'available' as const,
        rejectionReason: null,
        expiresAt: '2026-09-21T00:10:00Z',
        completedAt: '2026-09-21T00:01:00Z',
      }),
    listBranches: () => ok({ branches: [], hasMore: false, nextCursor: null }),
    createBranch: () =>
      Promise.resolve({
        ok: false as const,
        error: { code: 'PERMISSION_DENIED' as const, message: 'denied' },
      }),
    getBranch: () =>
      Promise.resolve({
        ok: false as const,
        error: { code: 'NOT_FOUND' as const, message: 'missing' },
      }),
    updateBranch: () =>
      Promise.resolve({
        ok: false as const,
        error: { code: 'NOT_FOUND' as const, message: 'missing' },
      }),
    inviteOperator: () =>
      Promise.resolve({
        ok: false as const,
        error: { code: 'NOT_FOUND' as const, message: 'missing' },
      }),
    listMemberships: () => ok({ memberships: [] }),
    revokeMembership: () =>
      Promise.resolve({
        ok: false as const,
        error: { code: 'NOT_FOUND' as const, message: 'missing' },
      }),
    ...overrides,
  };

  const clinic: PharmacyClinicBridge = {
    contractVersion: 1,
    app: {
      metadata: () =>
        ok({
          appId: 'eg.clinic.pharmacy.desktop',
          productName: 'Clinic Pharmacy',
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
          accountType: 'pharmacy',
        }),
      verifyMfa: () =>
        ok({
          status: 'active',
          mfaRequired: false,
          accountType: 'pharmacy',
        }),
      logout: () => ok({ revoked: true as const }),
      me: () => fail('UNAUTHENTICATED'),
      sessions: () => ok({ sessions: [] }),
      revokeSession: () => ok({ revoked: true as const }),
    },
    pharmacy,
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

describe('pharmacy renderer workspace', () => {
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
  });

  it('signs in with MFA, onboards, and never persists canaries', async () => {
    const clinic = installBridge();
    clinic.auth.me = vi
      .fn()
      .mockResolvedValueOnce(fail('UNAUTHENTICATED'))
      .mockResolvedValue(
        ok({
          userId: '0199a5c8-0000-7000-8000-000000000001',
          accountType: 'pharmacy',
          status: 'active',
          language: 'en',
          assuranceLevel: 'aal2_totp',
          capabilities: [],
        }),
      );

    renderApp();
    expect(await screen.findByTestId('login-form')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Mobile number'), { target: { value: '01900000001' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct-horse-battery' } });
    fireEvent.click(screen.getByTestId('sign-in'));
    fireEvent.change(await screen.findByLabelText('Authenticator code'), { target: { value: '123456' } });
    fireEvent.click(screen.getByTestId('sign-in'));

    expect(await screen.findByTestId('onboarding-form')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Legal name'), { target: { value: CANARIES.legalName } });
    fireEvent.change(screen.getByLabelText('Public name'), { target: { value: 'Safe Pharmacy' } });
    fireEvent.change(screen.getByLabelText('Legal registration identifier'), {
      target: { value: CANARIES.registration },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue to branch location' }));
    fireEvent.change(screen.getByLabelText('Initial branch public name'), { target: { value: 'Main' } });
    fireEvent.change(screen.getByLabelText('Address'), { target: { value: CANARIES.address } });
    fireEvent.change(screen.getByLabelText('Branch phone'), { target: { value: CANARIES.phone } });
    fireEvent.click(screen.getByRole('button', { name: 'Create organization' }));

    expect(await screen.findByTestId('verification-workspace')).toBeTruthy();
    expect(screen.queryByDisplayValue(CANARIES.legalName)).toBeNull();
    expect(screen.queryByDisplayValue(CANARIES.registration)).toBeNull();
    expect(screen.queryByDisplayValue(CANARIES.address)).toBeNull();
    expect(screen.queryByDisplayValue(CANARIES.phone)).toBeNull();
    const html = document.body.innerHTML;
    expect(html).not.toContain(CANARIES.legalName);
    expect(html).not.toContain(CANARIES.registration);
    expect(html).not.toContain(CANARIES.address);
    expect(html).not.toContain(CANARIES.phone);
    assertNoCanaries(html);
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(document.cookie).not.toMatch(/access_token|refresh_token|CANARY/);
  });

  it('does not put the signed upload target or file path in the DOM', async () => {
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
    clinic.pharmacy.getOwnOrganization = () =>
      ok({ present: true as const, organization: organization() });

    renderApp();
    expect(await screen.findByTestId('verification-workspace')).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Choose registration evidence' }));
    expect(await screen.findByText(/registration\.pdf/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Upload evidence' }));
    expect(await screen.findByTestId('upload-status')).toBeTruthy();
    const html = document.body.innerHTML;
    assertNoCanaries(html);
    expect(html).not.toContain(CANARIES.path);
    expect(JSON.stringify(await clinic.pharmacy.selectEvidence())).not.toContain(CANARIES.path);
  });

  it('fail-closes a non-pharmacy account', async () => {
    const clinic = installBridge();
    clinic.auth.me = () =>
      ok({
        userId: '0199a5c8-0000-7000-8000-000000000001',
        accountType: 'doctor',
        status: 'active',
        language: 'en',
        assuranceLevel: 'aal2_totp',
        capabilities: [],
      });

    renderApp();
    expect(await screen.findByTestId('account-denied')).toBeTruthy();
    expect(screen.queryByTestId('onboarding-form')).toBeNull();
  });
});
