/** @vitest-environment jsdom */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type {
  DoctorClinicBridge,
  DoctorClinicLocationView,
  DoctorClinicMembershipView,
  DoctorProfileView,
  DoctorVerificationStatus,
} from '@clinic/desktop-bridge-contracts';
import { App } from '../../App';
import { canManageClinicLocations } from './eligibility';
import { parsePracticeHash, PHASE_03_HASH_FRAGMENTS } from './practiceRoute';

const PHONE_CANARY = 'CANARY-SECRETARY-PHONE-01099999999';
const ADDRESS_CANARY = 'CANARY-ADDRESS-1-TAHRIR-SQUARE';
const DOCTOR_ID = '0199a5c8-0000-7000-8000-000000000010';
const SPECIALTY_ID = '0199a5c8-0000-7000-8000-000000000002';
const LOCATION_A = '0199a5c8-0000-7000-8000-0000000000aa';
const LOCATION_B = '0199a5c8-0000-7000-8000-0000000000ab';
const MEMBERSHIP_ID = '0199a5c8-0000-7000-8000-0000000000bb';
const INVITATION_ID = '0199a5c8-0000-7000-8000-0000000000cc';
const FOREIGN_LOCATION = '0199a5c8-0000-7000-8000-0000000000ff';

function ok<T>(value: T) {
  return Promise.resolve({ ok: true as const, value });
}

function fail(code: 'NOT_FOUND' | 'VERSION_CONFLICT' | 'VALIDATION_FAILED' | 'PERMISSION_DENIED', message = 'failed') {
  return Promise.resolve({ ok: false as const, error: { code, message } });
}

function profile(verificationStatus: DoctorProfileView['verificationStatus'] = 'approved'): DoctorProfileView {
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

function verification(status: DoctorProfileView['verificationStatus'] = 'approved'): DoctorVerificationStatus {
  return {
    applicantType: 'doctor',
    doctorId: DOCTOR_ID,
    profileVerificationStatus: status,
    profilePublicStatus: status === 'approved' ? 'listed' : 'hidden',
    profileVersion: 1,
    caseId: '0199a5c8-0000-7000-8000-000000000040',
    caseStatus: status === 'approved' ? 'approved' : status === 'draft' ? 'draft' : 'pending_review',
    caseVersion: 1,
    caseType: 'doctor_verification',
    submittedAt: '2026-09-21T00:01:00Z',
    decidedAt: status === 'approved' ? '2026-09-21T00:02:00Z' : null,
    decision: status === 'approved' ? 'approved' : null,
    reasonCode: null,
    documents: [],
  };
}

function locationView(overrides: Partial<DoctorClinicLocationView> = {}): DoctorClinicLocationView {
  return {
    locationId: LOCATION_A,
    publicName: 'Cairo Clinic',
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

function membership(overrides: Partial<DoctorClinicMembershipView> = {}): DoctorClinicMembershipView {
  return {
    membershipId: MEMBERSHIP_ID,
    role: 'secretary',
    status: 'active',
    version: 1,
    invitedAt: '2026-09-21T00:04:00Z',
    acceptedAt: '2026-09-21T00:05:00Z',
    revokedAt: null,
    ...overrides,
  };
}

function doctorMe(overrides: Partial<{ status: string; assuranceLevel: string; capabilities: string[] }> = {}) {
  return ok({
    userId: '0199a5c8-0000-7000-8000-000000000001',
    accountType: 'doctor' as const,
    status: overrides.status ?? 'active',
    language: 'en' as const,
    assuranceLevel: overrides.assuranceLevel ?? 'aal2_totp',
    capabilities: overrides.capabilities ?? ['clinics.location.write'],
  });
}

function emptyDoctorMethods(): DoctorClinicBridge['doctor'] {
  return {
    getOwnProfile: () => ok({ present: true as const, profile: profile('approved') }),
    listSpecialties: () => ok({ specialties: [] }),
    onboard: () => ok({ status: 'manual_review_required' as const }),
    openVerificationCase: () =>
      ok({
        status: 'ready' as const,
        doctorId: DOCTOR_ID,
        caseId: '0199a5c8-0000-7000-8000-000000000040',
        caseStatus: 'draft',
        caseVersion: 1,
        profileVersion: 1,
      }),
    verificationStatus: () => ok(verification('approved')),
    submitVerification: () => fail('NOT_FOUND'),
    selectEvidence: () => ok({ selected: false as const }),
    clearEvidence: () => ok({ cleared: true as const }),
    uploadEvidence: () => fail('NOT_FOUND'),
    uploadStatus: () => fail('NOT_FOUND'),
    listLocations: () => ok({ locations: [], hasMore: false, nextCursor: null }),
    createLocation: () => fail('NOT_FOUND'),
    getLocation: () => fail('NOT_FOUND'),
    updateLocation: () => fail('NOT_FOUND'),
    inviteStaff: () => fail('NOT_FOUND'),
    listMemberships: () => ok({ memberships: [] }),
    revokeMembership: () => fail('NOT_FOUND'),
  };
}

function installApproved(
  doctorOverrides: Partial<DoctorClinicBridge['doctor']> = {},
  authOverrides: Partial<DoctorClinicBridge['auth']> = {},
) {
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
      login: () => ok({ status: 'active', mfaRequired: false, accountType: 'doctor' }),
      verifyMfa: () => ok({ status: 'active', mfaRequired: false, accountType: 'doctor' }),
      logout: () => ok({ revoked: true as const }),
      me: () => doctorMe(),
      sessions: () => ok({ sessions: [] }),
      revokeSession: () => ok({ revoked: true as const }),
      ...authOverrides,
    },
    doctor: { ...emptyDoctorMethods(), ...doctorOverrides },
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

async function openLocations() {
  fireEvent.click(await screen.findByTestId('open-practice-locations'));
  expect(await screen.findByTestId('practice-locations')).toBeTruthy();
}

function fillCairoForm(name = 'Cairo Clinic', address = '1 Tahrir Square, Cairo') {
  fireEvent.change(screen.getByRole('textbox', { name: /Public name/ }), { target: { value: name } });
  fireEvent.change(screen.getByRole('textbox', { name: /Address/ }), { target: { value: address } });
  fireEvent.change(screen.getByRole('textbox', { name: /Latitude/ }), { target: { value: '30.0444' } });
  fireEvent.change(screen.getByRole('textbox', { name: /Longitude/ }), { target: { value: '31.2357' } });
  fireEvent.click(screen.getByRole('checkbox', { name: /I confirm these coordinates/ }));
}

describe('practice route and eligibility', () => {
  it('parses location hashes and treats Phase 03 paths as home', () => {
    expect(parsePracticeHash('#/practice/locations')).toEqual({ name: 'locations' });
    expect(parsePracticeHash('#/practice/locations/new')).toEqual({ name: 'create' });
    expect(parsePracticeHash(`#/practice/locations/${LOCATION_A}/staff`)).toEqual({
      name: 'detail',
      locationId: LOCATION_A,
      tab: 'staff',
    });
    for (const fragment of PHASE_03_HASH_FRAGMENTS) {
      expect(parsePracticeHash(`#${fragment}`).name).toBe('home');
    }
  });

  it('requires an approved active privileged doctor, not a capability string', () => {
    const approved = {
      userId: '0199a5c8-0000-7000-8000-000000000001',
      accountType: 'doctor',
      status: 'active',
      language: 'en' as const,
      assuranceLevel: 'aal2_totp',
      capabilities: ['clinics.location.write'],
    };
    expect(canManageClinicLocations(approved, profile('approved'))).toBe(true);
    expect(canManageClinicLocations(approved, profile('pending_review'))).toBe(false);
    expect(canManageClinicLocations({ ...approved, status: 'suspended' }, profile('approved'))).toBe(false);
    expect(canManageClinicLocations({ ...approved, assuranceLevel: 'aal1' }, profile('approved'))).toBe(false);
    expect(
      canManageClinicLocations({ ...approved, accountType: 'patient', capabilities: ['clinics.location.write'] }, profile('approved')),
    ).toBe(false);
  });
});

describe('doctor clinic location and staff UI', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    document.cookie = '';
    window.location.hash = '';
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
    window.location.hash = '';
  });

  it('shows loading then empty then populated locations for an approved doctor', async () => {
    let resolveList: ((value: Awaited<ReturnType<DoctorClinicBridge['doctor']['listLocations']>>) => void) | undefined;
    const clinic = installApproved({
      listLocations: () =>
        new Promise((resolve) => {
          resolveList = resolve;
        }),
    });
    renderApp();
    fireEvent.click(await screen.findByTestId('open-practice-locations'));
    expect(await screen.findByTestId('locations-loading')).toBeTruthy();
    resolveList?.(await ok({ locations: [], hasMore: false, nextCursor: null }));
    expect(await screen.findByTestId('locations-empty')).toBeTruthy();

    clinic.doctor.listLocations = () =>
      ok({
        locations: [locationView()],
        hasMore: true,
        nextCursor: 'opaque-cursor',
      });
    fireEvent.click(screen.getByTestId('refresh-locations'));
    expect(await screen.findByText('Cairo Clinic')).toBeTruthy();
    expect(screen.getByText(/Active/)).toBeTruthy();
    expect(screen.getByTestId('load-more-locations')).toBeTruthy();
    expect(screen.queryByText('staff_count')).toBeNull();
    expect(screen.queryByText(/appointment type/i)).toBeNull();
  });

  it('paginates with the server cursor and opens details', async () => {
    const list = vi
      .fn()
      .mockResolvedValueOnce(
        await ok({
          locations: [locationView()],
          hasMore: true,
          nextCursor: 'opaque-cursor',
        }),
      )
      .mockResolvedValueOnce(
        await ok({
          locations: [locationView({ locationId: LOCATION_B, publicName: 'Giza Clinic' })],
          hasMore: false,
          nextCursor: null,
        }),
      );
    installApproved({
      listLocations: list,
      getLocation: ({ locationId }) =>
        locationId === LOCATION_A ? ok(locationView()) : fail('NOT_FOUND'),
    });
    renderApp();
    await openLocations();
    fireEvent.click(screen.getByTestId('load-more-locations'));
    expect(await screen.findByText('Giza Clinic')).toBeTruthy();
    expect(list.mock.calls[1]?.[0]).toEqual({ cursor: 'opaque-cursor' });
    fireEvent.click(screen.getByTestId(`open-location-${LOCATION_A}`));
    expect(await screen.findByTestId('location-version')).toBeTruthy();
    expect(screen.getByTestId('location-version').textContent).toContain('1');
    expect(screen.queryByTestId('tab-schedule')).toBeNull();
  });

  it('creates an EG location without client-owned doctor or status fields', async () => {
    const create = vi.fn(async (input: unknown) => {
      expect(input).toEqual({
        publicName: 'Cairo Clinic',
        address: ADDRESS_CANARY,
        countryCode: 'EG',
        latitude: 30.0444,
        longitude: 31.2357,
      });
      expect(JSON.stringify(input)).not.toContain('doctor_id');
      expect(JSON.stringify(input)).not.toContain('doctorId');
      expect(JSON.stringify(input)).not.toMatch(/"status"/);
      expect(JSON.stringify(input)).not.toContain('expectedVersion');
      return ok({ locationId: LOCATION_A, status: 'active' as const, version: 1 });
    });
    installApproved({
      createLocation: create,
      getLocation: () => ok(locationView({ address: ADDRESS_CANARY })),
    });
    renderApp();
    await openLocations();
    fireEvent.click(screen.getByTestId('add-location'));
    expect(await screen.findByTestId('location-create-form')).toBeTruthy();
    fillCairoForm('Cairo Clinic', ADDRESS_CANARY);
    fireEvent.click(screen.getByTestId('location-submit'));
    expect(await screen.findByTestId('practice-location-details')).toBeTruthy();
    expect(create).toHaveBeenCalledTimes(1);
  });

  it('blocks invalid coordinates before submit', async () => {
    const create = vi.fn();
    installApproved({ createLocation: create });
    renderApp();
    await openLocations();
    fireEvent.click(screen.getByTestId('add-location'));
    expect(await screen.findByTestId('location-create-form')).toBeTruthy();
    fireEvent.change(screen.getByRole('textbox', { name: /Public name/ }), { target: { value: 'Paris Clinic' } });
    fireEvent.change(screen.getByRole('textbox', { name: /Address/ }), { target: { value: 'Paris' } });
    fireEvent.change(screen.getByRole('textbox', { name: /Latitude/ }), { target: { value: '48.8566' } });
    fireEvent.change(screen.getByRole('textbox', { name: /Longitude/ }), { target: { value: '2.3522' } });
    fireEvent.click(screen.getByRole('checkbox', { name: /I confirm these coordinates/ }));
    expect(screen.getByTestId('location-submit')).toHaveProperty('disabled', true);
    expect(create).not.toHaveBeenCalled();
  });

  it('edits with expected_version and shows VERSION_CONFLICT without overwriting', async () => {
    const current = locationView();
    const update = vi.fn(async (input: { expectedVersion: number }) => {
      expect(input.expectedVersion).toBe(1);
      return fail('VERSION_CONFLICT');
    });
    const getLocation = vi.fn().mockResolvedValue(ok(current));
    installApproved({
      listLocations: () => ok({ locations: [current], hasMore: false, nextCursor: null }),
      getLocation,
      updateLocation: update,
    });
    renderApp();
    await openLocations();
    fireEvent.click(screen.getByTestId(`open-location-${LOCATION_A}`));
    fireEvent.click(await screen.findByTestId('tab-location'));
    expect(await screen.findByTestId('location-edit-form')).toBeTruthy();
    fillCairoForm('Cairo Nile Clinic', '1 Tahrir Square, Cairo');
    fireEvent.click(screen.getByTestId('location-submit'));
    expect(await screen.findByTestId('version-conflict')).toBeTruthy();
    expect(update).toHaveBeenCalledTimes(1);
    const callsBeforeRefresh = getLocation.mock.calls.length;
    fireEvent.click(screen.getByTestId('refresh-location'));
    await waitFor(() => {
      expect(getLocation.mock.calls.length).toBeGreaterThan(callsBeforeRefresh);
    });
  });

  it('refreshes the authoritative location after a successful edit', async () => {
    const update = vi.fn(async () => ok(locationView({ publicName: 'Cairo Nile Clinic', version: 2 })));
    installApproved({
      listLocations: () => ok({ locations: [locationView()], hasMore: false, nextCursor: null }),
      getLocation: () => ok(locationView()),
      updateLocation: update,
    });
    renderApp();
    await openLocations();
    fireEvent.click(screen.getByTestId(`open-location-${LOCATION_A}`));
    fireEvent.click(await screen.findByTestId('tab-location'));
    expect(await screen.findByTestId('location-edit-form')).toBeTruthy();
    fillCairoForm('Cairo Nile Clinic');
    fireEvent.click(screen.getByTestId('location-submit'));
    await waitFor(() => {
      expect(screen.getByTestId('location-public-name').textContent).toContain('Cairo Nile Clinic');
      expect(screen.getByTestId('location-version').textContent).toContain('2');
    });
  });

  it('invites with write-only phone and renders pending plus replayed invitations', async () => {
    const invite = vi
      .fn()
      .mockResolvedValueOnce(
        ok({
          invitationId: INVITATION_ID,
          locationId: LOCATION_A,
          status: 'pending' as const,
          expiresAt: '2026-09-22T00:00:00Z',
          existingPending: false,
        }),
      )
      .mockResolvedValueOnce(
        ok({
          invitationId: INVITATION_ID,
          locationId: LOCATION_A,
          status: 'pending' as const,
          expiresAt: '2026-09-22T00:00:00Z',
          existingPending: true,
        }),
      );
    installApproved({
      listLocations: () => ok({ locations: [locationView()], hasMore: false, nextCursor: null }),
      getLocation: () => ok(locationView()),
      inviteStaff: invite,
    });
    renderApp();
    await openLocations();
    fireEvent.click(screen.getByTestId(`open-location-${LOCATION_A}`));
    fireEvent.click(await screen.findByTestId('tab-staff'));
    expect(await screen.findByTestId('staff-invite-form')).toBeTruthy();
    fireEvent.change(screen.getByRole('textbox', { name: /Mobile number/ }), { target: { value: PHONE_CANARY } });
    fireEvent.click(screen.getByTestId('send-invite'));
    expect(await screen.findByTestId('invitation-result')).toBeTruthy();
    expect(screen.getByTestId('invitation-result').getAttribute('data-existing-pending')).toBe('false');
    expect(screen.queryByDisplayValue(PHONE_CANARY)).toBeNull();
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
    expect(invite.mock.calls[0]?.[0]).toEqual({ locationId: LOCATION_A, phone: PHONE_CANARY });
    expect(JSON.stringify(invite.mock.calls[0]?.[0])).not.toContain('role');

    fireEvent.change(screen.getByRole('textbox', { name: /Mobile number/ }), { target: { value: PHONE_CANARY } });
    fireEvent.click(screen.getByTestId('send-invite'));
    await waitFor(() => {
      expect(screen.getByTestId('invitation-result').getAttribute('data-existing-pending')).toBe('true');
    });
    expect(screen.getByText(/Existing invitation still pending/)).toBeTruthy();
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
    expect(localStorage.length).toBe(0);
  });

  it('lists safe memberships, confirms revoke, and shows the revoked state', async () => {
    let current = membership();
    installApproved({
      listLocations: () => ok({ locations: [locationView()], hasMore: false, nextCursor: null }),
      getLocation: () => ok(locationView()),
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
    await openLocations();
    fireEvent.click(screen.getByTestId(`open-location-${LOCATION_A}`));
    fireEvent.click(await screen.findByTestId('tab-staff'));
    expect(await screen.findByTestId('staff-invite-form')).toBeTruthy();
    expect(await screen.findByTestId(`membership-${MEMBERSHIP_ID}`)).toBeTruthy();
    expect(document.body.innerHTML).not.toContain(PHONE_CANARY);
    expect(document.body.innerHTML.toLowerCase()).not.toContain('hmac');
    expect(document.body.innerHTML).not.toContain('National ID');
    fireEvent.click(screen.getByTestId(`revoke-${MEMBERSHIP_ID}`));
    fireEvent.click(screen.getByTestId('confirm-revoke'));
    await waitFor(() => {
      expect(screen.getByTestId(`membership-${MEMBERSHIP_ID}`).getAttribute('data-membership-status')).toBe(
        'revoked',
      );
    });
  });

  it('keeps foreign locations non-enumerating and hides practice nav from ineligible actors', async () => {
    installApproved({
      getLocation: ({ locationId }) => (locationId === FOREIGN_LOCATION ? fail('NOT_FOUND') : ok(locationView())),
    });
    window.location.hash = `/practice/locations/${FOREIGN_LOCATION}`;
    renderApp();
    expect(await screen.findByTestId('location-unavailable')).toBeTruthy();
    expect(screen.queryByTestId('location-public-name')).toBeNull();
    cleanup();

    installApproved({
      getOwnProfile: () => ok({ present: true as const, profile: profile('pending_review') }),
      verificationStatus: () => ok(verification('pending_review')),
    });
    renderApp();
    expect(await screen.findByTestId('doctor-workspace')).toBeTruthy();
    expect(screen.queryByTestId('practice-locations-nav')).toBeNull();
  });

  it('denies patient and pharmacy accounts the doctor practice UI', async () => {
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
    expect(screen.queryByTestId('practice-locations-nav')).toBeNull();
    cleanup();

    installApproved(
      {},
      {
        me: () =>
          ok({
            userId: '0199a5c8-0000-7000-8000-000000000001',
            accountType: 'pharmacy',
            status: 'active',
            language: 'en',
            assuranceLevel: 'aal2_totp',
            capabilities: [],
          }),
      },
    );
    renderApp();
    expect(await screen.findByTestId('account-denied')).toBeTruthy();
    expect(screen.queryByTestId('open-practice-locations')).toBeNull();
  });

  it('renders Arabic RTL membership status and does not persist location data', async () => {
    installApproved({
      listLocations: () => ok({ locations: [locationView()], hasMore: false, nextCursor: null }),
    });
    renderApp();
    await openLocations();
    fireEvent.change(screen.getByTestId('language-select'), { target: { value: 'ar' } });
    await waitFor(() => {
      expect(document.documentElement.getAttribute('dir')).toBe('rtl');
    });
    expect(await screen.findByTestId('practice-locations')).toBeTruthy();
    expect(screen.getAllByText('مواقع العيادة').length).toBeGreaterThan(0);
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(document.cookie).not.toMatch(/access_token|refresh_token/);
    expect(screen.queryByTestId('tab-schedule')).toBeNull();
    expect((window as unknown as { clinic: { invoke?: unknown } }).clinic.invoke).toBeUndefined();
    expect((window as unknown as { require?: unknown }).require).toBeUndefined();
  });

  it('clears practice state on logout', async () => {
    installApproved({
      listLocations: () => ok({ locations: [locationView()], hasMore: false, nextCursor: null }),
    });
    renderApp();
    await openLocations();
    expect(window.location.hash).toContain('/practice/locations');
    fireEvent.click(screen.getByTestId('sign-out'));
    expect(await screen.findByTestId('login-form')).toBeTruthy();
    expect(window.location.hash).not.toContain('/practice');
    expect(screen.queryByTestId('practice-locations')).toBeNull();
  });
});
