/** @vitest-environment jsdom */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type {
  PharmacyClinicBridge,
  PharmacyBranchCreateRequest,
  PharmacyBranchInviteOperatorRequest,
  PharmacyBranchInviteOperatorResponse,
  PharmacyBranchMembershipView,
  PharmacyBranchPrivateView,
  PharmacyOrganizationView,
  PharmacyVerificationStatus,
} from '@clinic/desktop-bridge-contracts';
import { App } from '../../App';
import { canManagePharmacyBranches } from './eligibility';
import { parsePracticeHash, PHASE_10_HASH_FRAGMENTS } from './practiceRoute';

const PHONE_CANARY = 'CANARY-OPERATOR-PHONE-01099999999';
const ADDRESS_CANARY = 'CANARY-ADDRESS-1-TAHRIR-SQUARE';
const ORG_ID = '0199a5c8-0000-7000-8000-000000000010';
const BRANCH_A = '0199a5c8-0000-7000-8000-0000000000aa';
const BRANCH_B = '0199a5c8-0000-7000-8000-0000000000ab';
const MEMBERSHIP_ID = '0199a5c8-0000-7000-8000-0000000000bb';
const INVITATION_ID = '0199a5c8-0000-7000-8000-0000000000cc';
const FOREIGN_BRANCH = '0199a5c8-0000-7000-8000-0000000000ff';

function ok<T>(value: T) {
  return Promise.resolve({ ok: true as const, value });
}

function fail(
  code: 'NOT_FOUND' | 'VERSION_CONFLICT' | 'VALIDATION_FAILED' | 'PERMISSION_DENIED',
  message = 'failed',
) {
  return Promise.resolve({ ok: false as const, error: { code, message } });
}

function organization(
  verificationStatus: PharmacyOrganizationView['verificationStatus'] = 'approved',
  status: PharmacyOrganizationView['status'] = 'active',
): PharmacyOrganizationView {
  return {
    organizationId: ORG_ID,
    publicName: 'Safe Pharmacy',
    verificationStatus,
    status,
    version: 1,
    createdAt: '2026-09-21T00:00:00Z',
    updatedAt: '2026-09-21T00:00:00Z',
    initialBranch: {
      branchId: BRANCH_A,
      publicName: 'Main',
      countryCode: 'EG',
      status,
      version: 1,
    },
    membership: {
      membershipId: '0199a5c8-0000-7000-8000-000000000012',
      role: 'owner',
      status: verificationStatus === 'approved' && status === 'active' ? 'active' : 'pending',
    },
  };
}

function verification(
  status: PharmacyVerificationStatus['organizationVerificationStatus'] = 'approved',
): PharmacyVerificationStatus {
  return {
    applicantType: 'pharmacy',
    organizationId: ORG_ID,
    organizationVerificationStatus: status,
    organizationStatus: status === 'approved' ? 'active' : 'pending',
    organizationVersion: 1,
    caseId: '0199a5c8-0000-7000-8000-000000000040',
    caseStatus: status === 'approved' ? 'approved' : 'pending_review',
    caseVersion: 1,
    caseType: 'pharmacy_verification',
    submittedAt: '2026-09-21T00:01:00Z',
    decidedAt: status === 'approved' ? '2026-09-21T00:02:00Z' : null,
    decision: status === 'approved' ? 'approved' : null,
    reasonCode: null,
    documents: [],
  };
}

function branchView(overrides: Partial<PharmacyBranchPrivateView> = {}): PharmacyBranchPrivateView {
  return {
    branchId: BRANCH_A,
    publicName: 'Cairo Pharmacy',
    countryCode: 'EG',
    status: 'active',
    version: 1,
    createdAt: '2026-09-21T00:00:00Z',
    updatedAt: '2026-09-21T00:00:00Z',
    address: '1 Tahrir Square, Cairo',
    latitude: 30.0444,
    longitude: 31.2357,
    ...overrides,
  };
}

function membership(overrides: Partial<PharmacyBranchMembershipView> = {}): PharmacyBranchMembershipView {
  return {
    membershipId: MEMBERSHIP_ID,
    role: 'branch_operator',
    status: 'active',
    version: 1,
    invitedAt: '2026-09-21T00:04:00Z',
    acceptedAt: '2026-09-21T00:05:00Z',
    revokedAt: null,
    ...overrides,
  };
}

function pharmacyMe(overrides: Partial<{ status: string; assuranceLevel: string; capabilities: string[] }> = {}) {
  return ok({
    userId: '0199a5c8-0000-7000-8000-000000000001',
    accountType: 'pharmacy' as const,
    status: overrides.status ?? 'active',
    language: 'en' as const,
    assuranceLevel: overrides.assuranceLevel ?? 'aal2_totp',
    capabilities: overrides.capabilities ?? ['pharmacies.branch.write'],
  });
}

function emptyPharmacyMethods(): PharmacyClinicBridge['pharmacy'] {
  return {
    getOwnOrganization: () => ok({ present: true as const, organization: organization() }),
    onboard: () => ok({ status: 'manual_review_required' as const }),
    openVerificationCase: () =>
      ok({
        status: 'ready' as const,
        organizationId: ORG_ID,
        caseId: '0199a5c8-0000-7000-8000-000000000040',
        caseStatus: 'draft',
        caseVersion: 1,
        organizationVersion: 1,
      }),
    verificationStatus: () => ok(verification('approved')),
    submitVerification: () => fail('NOT_FOUND'),
    selectEvidence: () => ok({ selected: false as const }),
    clearEvidence: () => ok({ cleared: true as const }),
    uploadEvidence: () => fail('NOT_FOUND'),
    uploadStatus: () => fail('NOT_FOUND'),
    listBranches: () => ok({ branches: [], hasMore: false, nextCursor: null }),
    createBranch: () => fail('NOT_FOUND'),
    getBranch: () => fail('NOT_FOUND'),
    updateBranch: () => fail('NOT_FOUND'),
    inviteOperator: () => fail('NOT_FOUND'),
    listMemberships: () => ok({ memberships: [] }),
    revokeMembership: () => fail('NOT_FOUND'),
  };
}

function installApproved(
  pharmacyOverrides: Partial<PharmacyClinicBridge['pharmacy']> = {},
  authOverrides: Partial<PharmacyClinicBridge['auth']> = {},
) {
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
          status: 'active',
          mfaRequired: false,
          accountType: 'pharmacy',
        }),
      verifyMfa: () =>
        ok({
          status: 'active',
          mfaRequired: false,
          accountType: 'pharmacy',
        }),
      logout: () => ok({ revoked: true as const }),
      me: () => pharmacyMe(),
      sessions: () => ok({ sessions: [] }),
      revokeSession: () => ok({ revoked: true as const }),
      ...authOverrides,
    },
    pharmacy: {
      ...emptyPharmacyMethods(),
      ...pharmacyOverrides,
    },
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

async function openBranches(): Promise<void> {
  expect(await screen.findByTestId('practice-branches-nav')).toBeTruthy();
  fireEvent.click(screen.getByTestId('open-practice-branches'));
  expect(await screen.findByTestId('practice-branches')).toBeTruthy();
}

function fillCairoForm(name: string, address = '1 Tahrir Square, Cairo', phone?: string): void {
  fireEvent.change(screen.getByRole('textbox', { name: /Public name/ }), { target: { value: name } });
  fireEvent.change(screen.getByRole('textbox', { name: /Address/ }), { target: { value: address } });
  fireEvent.change(screen.getByRole('textbox', { name: /Latitude/ }), { target: { value: '30.0444' } });
  fireEvent.change(screen.getByRole('textbox', { name: /Longitude/ }), { target: { value: '31.2357' } });
  if (phone !== undefined) {
    fireEvent.change(screen.getByLabelText(/Branch or operator phone/), { target: { value: phone } });
  }
  fireEvent.click(screen.getByRole('checkbox', { name: /I confirm these coordinates/ }));
}

describe('pharmacy practice eligibility and routes', () => {
  it('gates branch management on approved active owner state, not capabilities', () => {
    const me = {
      userId: '0199a5c8-0000-7000-8000-000000000001',
      accountType: 'pharmacy',
      status: 'active',
      language: 'en' as const,
      assuranceLevel: 'aal2_totp',
      capabilities: ['pharmacies.branch.write'],
    };
    expect(canManagePharmacyBranches(me, organization('approved', 'active'))).toBe(true);
    expect(canManagePharmacyBranches(me, organization('pending_review', 'pending'))).toBe(false);
    expect(canManagePharmacyBranches(me, organization('rejected', 'draft'))).toBe(false);
    expect(canManagePharmacyBranches({ ...me, assuranceLevel: 'aal1_password' }, organization())).toBe(false);
  });

  it('parses branch hashes and treats Phase-10 fragments as home', () => {
    expect(parsePracticeHash('#/practice/branches')).toEqual({ name: 'branches' });
    expect(parsePracticeHash('#/practice/branches/new')).toEqual({ name: 'create' });
    expect(parsePracticeHash(`#/practice/branches/${BRANCH_A}/team`)).toEqual({
      name: 'detail',
      branchId: BRANCH_A,
      tab: 'team',
    });
    for (const fragment of PHASE_10_HASH_FRAGMENTS) {
      expect(parsePracticeHash(`#${fragment}`).name).toBe('home');
    }
  });
});

describe('pharmacy branch and membership UI', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    document.cookie = '';
    window.location.hash = '';
    document.documentElement.style.fontSize = '';
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

  it('shows branch management for an approved owner and lists server branches', async () => {
    installApproved({
      listBranches: () =>
        ok({
          branches: [branchView(), branchView({ branchId: BRANCH_B, publicName: 'Nasr City' })],
          hasMore: false,
          nextCursor: null,
        }),
    });
    renderApp();
    await openBranches();
    expect(screen.getByText('Cairo Pharmacy')).toBeTruthy();
    expect(screen.getByText('Nasr City')).toBeTruthy();
    expect(screen.getAllByText(/Active/).length).toBeGreaterThan(0);
    expect(screen.queryByTestId('inventory-nav')).toBeNull();
    expect(screen.queryByTestId('pos-nav')).toBeNull();
    expect(screen.getByTestId('no-phase10-nav')).toBeTruthy();
  });

  it('does not show branch management for pending or rejected organizations', async () => {
    installApproved({
      getOwnOrganization: () =>
        ok({ present: true as const, organization: organization('pending_review', 'pending') }),
      verificationStatus: () => ok(verification('pending_review')),
    });
    renderApp();
    expect(await screen.findByTestId('pharmacy-workspace')).toBeTruthy();
    expect(screen.queryByTestId('practice-branches-nav')).toBeNull();
    cleanup();

    installApproved({
      getOwnOrganization: () =>
        ok({ present: true as const, organization: organization('rejected', 'draft') }),
      verificationStatus: () => ok(verification('rejected')),
    });
    renderApp();
    expect(await screen.findByTestId('pharmacy-workspace')).toBeTruthy();
    expect(screen.queryByTestId('practice-branches-nav')).toBeNull();
  });

  it('creates a branch from the owner form', async () => {
    const create = vi.fn(async (_input: PharmacyBranchCreateRequest) =>
      ok({ branchId: BRANCH_B, status: 'active' as const, version: 1 }),
    );
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
      createBranch: create,
      getBranch: () => ok(branchView({ branchId: BRANCH_B, publicName: 'Nasr City Branch' })),
    });
    renderApp();
    await openBranches();
    fireEvent.click(screen.getByTestId('add-branch'));
    expect(await screen.findByTestId('branch-create-form')).toBeTruthy();
    fillCairoForm('Nasr City Branch', ADDRESS_CANARY, PHONE_CANARY);
    fireEvent.click(screen.getByTestId('branch-submit'));
    await waitFor(() => {
      expect(create).toHaveBeenCalled();
    });
    expect(create.mock.calls[0]?.[0]).toMatchObject({
      publicName: 'Nasr City Branch',
      address: ADDRESS_CANARY,
      countryCode: 'EG',
      phone: PHONE_CANARY,
    });
    expect(JSON.stringify(create.mock.calls[0]?.[0])).not.toContain('status');
    expect(JSON.stringify(create.mock.calls[0]?.[0])).not.toContain('version');
  });

  it('shows VERSION_CONFLICT and refreshes latest without auto-resubmit', async () => {
    const update = vi.fn(async () => fail('VERSION_CONFLICT'));
    const getBranch = vi
      .fn()
      .mockResolvedValueOnce(ok(branchView()))
      .mockResolvedValue(ok(branchView({ version: 2, publicName: 'Cairo Nile Pharmacy' })));
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
      getBranch,
      updateBranch: update,
    });
    renderApp();
    await openBranches();
    fireEvent.click(screen.getByTestId(`open-branch-${BRANCH_A}`));
    fireEvent.click(await screen.findByTestId('tab-location'));
    expect(await screen.findByTestId('branch-edit-form')).toBeTruthy();
    fillCairoForm('Cairo Nile Pharmacy');
    fireEvent.click(screen.getByTestId('branch-submit'));
    expect(await screen.findByTestId('version-conflict')).toBeTruthy();
    expect(update).toHaveBeenCalledTimes(1);
    const callsBeforeRefresh = getBranch.mock.calls.length;
    fireEvent.click(screen.getByTestId('refresh-branch'));
    await waitFor(() => {
      expect(getBranch.mock.calls.length).toBeGreaterThan(callsBeforeRefresh);
    });
    expect(update).toHaveBeenCalledTimes(1);
    expect(await screen.findByTestId('branch-version')).toBeTruthy();
  });

  it('edits a branch and increments the visible version', async () => {
    const update = vi.fn(async () => ok(branchView({ publicName: 'Cairo Nile Pharmacy', version: 2 })));
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
      getBranch: () => ok(branchView()),
      updateBranch: update,
    });
    renderApp();
    await openBranches();
    fireEvent.click(screen.getByTestId(`open-branch-${BRANCH_A}`));
    fireEvent.click(await screen.findByTestId('tab-location'));
    fillCairoForm('Cairo Nile Pharmacy');
    fireEvent.click(screen.getByTestId('branch-submit'));
    await waitFor(() => {
      expect(screen.getByTestId('branch-public-name').textContent).toContain('Cairo Nile Pharmacy');
      expect(screen.getByTestId('branch-version').textContent).toContain('2');
    });
  });

  it('clears invite and membership state when switching selected branches', async () => {
    const listMemberships = vi.fn(async ({ branchId }: { branchId: string }) =>
      ok({
        memberships:
          branchId === BRANCH_A ? [membership()] : [membership({ membershipId: '0199a5c8-0000-7000-8000-0000000000dd' })],
      }),
    );
    installApproved({
      listBranches: () =>
        ok({
          branches: [branchView(), branchView({ branchId: BRANCH_B, publicName: 'Heliopolis' })],
          hasMore: false,
          nextCursor: null,
        }),
      getBranch: ({ branchId }) =>
        ok(branchView({ branchId, publicName: branchId === BRANCH_B ? 'Heliopolis' : 'Cairo Pharmacy' })),
      listMemberships,
    });
    renderApp();
    await openBranches();
    fireEvent.click(screen.getByTestId(`open-branch-${BRANCH_A}`));
    fireEvent.click(await screen.findByTestId('tab-team'));
    fireEvent.change(await screen.findByLabelText(/Branch or operator phone/), { target: { value: PHONE_CANARY } });
    expect((screen.getByTestId('operator-invite-phone') as HTMLInputElement).value).toBe(PHONE_CANARY);
    fireEvent.click(screen.getByTestId('open-practice-branches'));
    fireEvent.click(await screen.findByTestId(`open-branch-${BRANCH_B}`));
    fireEvent.click(await screen.findByTestId('tab-team'));
    expect(await screen.findByTestId('operator-invite-phone')).toBeTruthy();
    expect((screen.getByTestId('operator-invite-phone') as HTMLInputElement).value).toBe('');
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
  });

  it('invites with write-only phone and reports generic pending replay', async () => {
    const invite = vi.fn<
      (input: PharmacyBranchInviteOperatorRequest) => Promise<{
        ok: true;
        value: PharmacyBranchInviteOperatorResponse;
      }>
    >();
    invite
      .mockResolvedValueOnce({
        ok: true,
        value: {
          invitationId: INVITATION_ID,
          status: 'pending',
          expiresAt: '2026-09-22T00:00:00Z',
          existingPending: false,
        },
      })
      .mockResolvedValueOnce({
        ok: true,
        value: {
          invitationId: INVITATION_ID,
          status: 'pending',
          expiresAt: '2026-09-22T00:00:00Z',
          existingPending: true,
        },
      });
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
      getBranch: () => ok(branchView()),
      inviteOperator: invite,
    });
    renderApp();
    await openBranches();
    fireEvent.click(screen.getByTestId(`open-branch-${BRANCH_A}`));
    fireEvent.click(await screen.findByTestId('tab-team'));
    fireEvent.change(screen.getByLabelText(/Branch or operator phone/), { target: { value: PHONE_CANARY } });
    fireEvent.click(screen.getByTestId('send-invite'));
    expect(await screen.findByTestId('invitation-result')).toBeTruthy();
    expect(screen.getByTestId('invitation-result').getAttribute('data-existing-pending')).toBe('false');
    expect(screen.queryByDisplayValue(PHONE_CANARY)).toBeNull();
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
    expect(invite.mock.calls[0]?.[0]).toEqual({ branchId: BRANCH_A, phone: PHONE_CANARY });
    expect(JSON.stringify(invite.mock.calls[0]?.[0])).not.toContain('role');

    fireEvent.change(screen.getByLabelText(/Branch or operator phone/), { target: { value: PHONE_CANARY } });
    fireEvent.click(screen.getByTestId('send-invite'));
    await waitFor(() => {
      expect(screen.getByTestId('invitation-result').getAttribute('data-existing-pending')).toBe('true');
    });
    expect(screen.getByText(/Invitation already pending/)).toBeTruthy();
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
    expect(localStorage.length).toBe(0);
    expect(window.location.hash).not.toContain(PHONE_CANARY);
  });

  it('lists safe memberships, confirms revoke, and keeps the revoked row', async () => {
    let current = membership();
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
      getBranch: () => ok(branchView()),
      listMemberships: () => ok({ memberships: [current] }),
      revokeMembership: async () => {
        current = membership({
          status: 'revoked',
          version: 2,
          revokedAt: '2026-09-21T00:06:00Z',
        });
        return ok(current);
      },
    });
    renderApp();
    await openBranches();
    fireEvent.click(screen.getByTestId(`open-branch-${BRANCH_A}`));
    fireEvent.click(await screen.findByTestId('tab-team'));
    expect(await screen.findByTestId(`membership-${MEMBERSHIP_ID}`)).toBeTruthy();
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
    expect(screen.getByTestId(`membership-${MEMBERSHIP_ID}`).textContent).not.toMatch(/owner/i);
    fireEvent.click(screen.getByTestId(`revoke-${MEMBERSHIP_ID}`));
    fireEvent.click(screen.getByTestId('confirm-revoke'));
    await waitFor(() => {
      expect(screen.getByTestId(`membership-${MEMBERSHIP_ID}`).getAttribute('data-membership-status')).toBe(
        'revoked',
      );
    });
  });

  it('fails closed for a foreign branch id and hides nav from ineligible actors', async () => {
    installApproved({
      getBranch: ({ branchId }) => (branchId === FOREIGN_BRANCH ? fail('NOT_FOUND') : ok(branchView())),
    });
    window.location.hash = `/practice/branches/${FOREIGN_BRANCH}`;
    renderApp();
    expect(await screen.findByTestId('branch-unavailable')).toBeTruthy();
    expect(screen.queryByTestId('branch-public-name')).toBeNull();
    cleanup();

    installApproved({
      getOwnOrganization: () =>
        ok({ present: true as const, organization: organization('pending_review', 'pending') }),
      verificationStatus: () => ok(verification('pending_review')),
    });
    renderApp();
    expect(await screen.findByTestId('pharmacy-workspace')).toBeTruthy();
    expect(screen.queryByTestId('practice-branches-nav')).toBeNull();
  });

  it('denies patient and doctor accounts pharmacy branch management', async () => {
    installApproved(
      {},
      {
        me: () =>
          ok({
            userId: '0199a5c8-0000-7000-8000-000000000001',
            accountType: 'patient',
            status: 'active',
            language: 'en',
            assuranceLevel: 'aal2_totp',
            capabilities: [],
          }),
      },
    );
    renderApp();
    expect(await screen.findByTestId('account-denied')).toBeTruthy();
    expect(screen.queryByTestId('practice-branches-nav')).toBeNull();
    cleanup();

    installApproved(
      {},
      {
        me: () =>
          ok({
            userId: '0199a5c8-0000-7000-8000-000000000001',
            accountType: 'doctor',
            status: 'active',
            language: 'en',
            assuranceLevel: 'aal2_totp',
            capabilities: [],
          }),
      },
    );
    renderApp();
    expect(await screen.findByTestId('account-denied')).toBeTruthy();
    expect(screen.queryByTestId('open-practice-branches')).toBeNull();
  });

  it('renders Arabic RTL membership copy and keeps primary actions at 200% text size', async () => {
    document.documentElement.style.fontSize = '200%';
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
    });
    renderApp();
    await openBranches();
    expect(screen.getByTestId('add-branch')).toBeTruthy();
    fireEvent.change(screen.getByTestId('language-select'), { target: { value: 'ar' } });
    await waitFor(() => {
      expect(document.documentElement.getAttribute('dir')).toBe('rtl');
    });
    expect(await screen.findByTestId('practice-branches')).toBeTruthy();
    expect(screen.getAllByText('الفروع').length).toBeGreaterThan(0);
    expect(screen.queryByTestId('inventory-nav')).toBeNull();
    expect((window as unknown as { clinic: { invoke?: unknown } }).clinic.invoke).toBeUndefined();
    expect(localStorage.length).toBe(0);
  });

  it('clears practice state on logout', async () => {
    installApproved({
      listBranches: () => ok({ branches: [branchView()], hasMore: false, nextCursor: null }),
    });
    renderApp();
    await openBranches();
    expect(window.location.hash).toContain('/practice/branches');
    fireEvent.click(screen.getByTestId('sign-out'));
    expect(await screen.findByTestId('login-form')).toBeTruthy();
    expect(window.location.hash).not.toContain('/practice');
    expect(screen.queryByTestId('practice-branches')).toBeNull();
  });
});
