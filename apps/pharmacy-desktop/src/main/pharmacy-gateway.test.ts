import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { fetchMock, appState } = vi.hoisted(() => ({
  fetchMock: vi.fn(),
  appState: { isPackaged: false },
}));

vi.mock('electron', () => ({
  app: {
    get isPackaged() {
      return appState.isPackaged;
    },
    getPath: () => '/tmp/clinic-pharmacy-origin-test',
  },
  net: { fetch: fetchMock },
}));

vi.mock('./device-credentials', () => ({
  persistDeviceTokens: vi.fn(),
  loadDeviceTokens: () => ({ access: 'access-token', refresh: 'refresh-token' }),
  clearDeviceTokens: vi.fn(),
  secureStorageStatus: () => ({ available: true, backend: 'gnome_libsecret' }),
  SecureStorageUnavailableError: class SecureStorageUnavailableError extends Error {
    readonly code = 'CAPABILITY_NOT_AVAILABLE' as const;
  },
}));

vi.mock('./packaged-api-allowlist', () => ({
  PACKAGED_API_ALLOWED_ORIGINS: ['https://pharmacy.example.com'],
}));

import { resetPlatformGatewaySession } from './platform-gateway';
import { pharmacyGateway, resetPharmacyGatewayState } from './pharmacy-gateway';

const CANARIES = {
  legalName: 'CANARY-LEGAL-PHARMACY-NAME',
  registration: 'CANARY-REG-CR-998877',
  address: 'CANARY-BRANCH-ADDRESS-99 Nile St',
  phone: '01011112222',
  signedUrl: 'https://objects.example/upload?X-Amz-Signature=CANARY-SIGNATURE',
  locator: 'verification/q/canary-object-key',
  objectId: '0199a5c8-ffff-7000-8000-000000000099',
  notes: 'reviewer-private-notes-must-not-cross-ipc',
  access: 'canary-access-token-value',
};

function envelope(status: number, data: unknown) {
  return {
    status,
    json: async () => ({
      data,
      meta: {},
      errors: [],
      request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    }),
  };
}

describe('pharmacy gateway safe projections', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    appState.isPackaged = false;
    resetPlatformGatewaySession();
    resetPharmacyGatewayState();
    process.env['CLINIC_API_BASE_URL'] = 'http://localhost:8080';
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        user_id: '0199a5c8-0000-7000-8000-000000000001',
        account_type: 'pharmacy',
        status: 'active',
        language: 'en',
        assurance_level: 'aal2_totp',
        profile_links: [],
      }),
    );
    fetchMock.mockResolvedValueOnce(envelope(200, { capabilities: [] }));
  });

  afterEach(() => {
    delete process.env['CLINIC_API_BASE_URL'];
  });

  it('maps own organization without legal identity or locators', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        organization_id: '0199a5c8-0000-7000-8000-000000000010',
        public_name: 'Safe Pharmacy',
        verification_status: 'draft',
        status: 'draft',
        version: 1,
        created_at: '2026-09-21T00:00:00Z',
        updated_at: '2026-09-21T00:00:00Z',
        legal_name: CANARIES.legalName,
        legal_registration_identifier: CANARIES.registration,
        address: CANARIES.address,
        phone: CANARIES.phone,
        upload_target: { url: CANARIES.signedUrl },
        object_id: CANARIES.objectId,
        notes: CANARIES.notes,
        initial_branch: {
          branch_id: '0199a5c8-0000-7000-8000-000000000011',
          public_name: 'Main',
          country_code: 'EG',
          status: 'draft',
          version: 1,
        },
        membership: {
          membership_id: '0199a5c8-0000-7000-8000-000000000012',
          role: 'owner',
          status: 'pending',
        },
      }),
    );

    const result = await pharmacyGateway.getOwnOrganization('en');
    expect(result.present).toBe(true);
    const serialized = JSON.stringify(result);
    expect(serialized).toContain('Safe Pharmacy');
    expect(serialized).not.toContain(CANARIES.legalName);
    expect(serialized).not.toContain(CANARIES.registration);
    expect(serialized).not.toContain(CANARIES.address);
    expect(serialized).not.toContain(CANARIES.phone);
    expect(serialized).not.toContain(CANARIES.signedUrl);
    expect(serialized).not.toContain(CANARIES.locator);
    expect(serialized).not.toContain(CANARIES.objectId);
    expect(serialized).not.toContain(CANARIES.notes);
    expect(serialized).not.toContain(CANARIES.access);
  });

  it('drops the signed upload target from the renderer projection', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(201, {
        upload_id: '0199a5c8-0000-7000-8000-000000000021',
        requirement_code: 'organization_registration_evidence',
        state: 'uploading',
        rejection_reason: null,
        expires_at: '2026-09-21T00:10:00Z',
        completed_at: null,
        upload_target: {
          method: 'PUT',
          url: CANARIES.signedUrl,
          headers: { 'Content-Type': 'application/pdf' },
          expires_at: '2026-09-21T00:10:00Z',
        },
        object_id: CANARIES.objectId,
        storage_locator: CANARIES.locator,
      }),
    );
    fetchMock.mockResolvedValueOnce({ status: 200 });
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        upload_id: '0199a5c8-0000-7000-8000-000000000021',
        requirement_code: 'organization_registration_evidence',
        state: 'quarantined',
        rejection_reason: null,
        expires_at: '2026-09-21T00:10:00Z',
        completed_at: '2026-09-21T00:01:00Z',
      }),
    );

    const uploaded = await pharmacyGateway.uploadEvidence('en', {
      caseId: '0199a5c8-0000-7000-8000-000000000030',
      bytes: Buffer.from('%PDF-1.4\n%%EOF\n'),
      sizeBytes: 14,
      candidateMediaType: 'application/pdf',
    });
    const serialized = JSON.stringify(uploaded);
    expect(uploaded.state).toBe('quarantined');
    expect(serialized).not.toContain(CANARIES.signedUrl);
    expect(serialized).not.toContain('CANARY-SIGNATURE');
    expect(serialized).not.toContain(CANARIES.locator);
    expect(serialized).not.toContain(CANARIES.objectId);
    expect(String(fetchMock.mock.calls[3]?.[0])).toBe(CANARIES.signedUrl);
    expect((fetchMock.mock.calls[3]?.[1] as { method?: string; redirect?: string }).method).toBe('PUT');
    expect((fetchMock.mock.calls[3]?.[1] as { redirect?: string }).redirect).toBe('error');
  });

  it('treats a missing own organization as present:false', async () => {
    fetchMock.mockResolvedValueOnce({
      status: 404,
      json: async () => ({
        data: null,
        meta: {},
        errors: [{ code: 'NOT_FOUND', message: 'missing' }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      }),
    });
    const result = await pharmacyGateway.getOwnOrganization('en');
    expect(result).toEqual({ present: false });
  });

  it('fail-closes when /me is not a pharmacy account', async () => {
    fetchMock.mockReset();
    resetPlatformGatewaySession();
    resetPharmacyGatewayState();
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        user_id: '0199a5c8-0000-7000-8000-000000000001',
        account_type: 'doctor',
        status: 'active',
        language: 'en',
        assurance_level: 'aal2_totp',
        profile_links: [],
      }),
    );
    fetchMock.mockResolvedValueOnce(envelope(200, { capabilities: [] }));
    await expect(pharmacyGateway.getOwnOrganization('en')).rejects.toMatchObject({
      failureCode: 'PERMISSION_DENIED',
    });
  });

  it('drops reviewer notes from verification status', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        applicant_type: 'pharmacy',
        organization_id: '0199a5c8-0000-7000-8000-000000000010',
        organization_verification_status: 'changes_requested',
        organization_status: 'pending',
        organization_version: 2,
        case_id: '0199a5c8-0000-7000-8000-000000000040',
        case_status: 'changes_requested',
        case_version: 3,
        case_type: 'pharmacy_verification',
        submitted_at: '2026-09-21T00:00:00Z',
        decided_at: '2026-09-21T00:01:00Z',
        decision: 'changes_requested',
        reason_code: 'evidence_incomplete',
        notes: CANARIES.notes,
        documents: [],
      }),
    );
    const status = await pharmacyGateway.verificationStatus('en');
    const serialized = JSON.stringify(status);
    expect(status.reasonCode).toBe('evidence_incomplete');
    expect(serialized).not.toContain(CANARIES.notes);
    expect(serialized).not.toContain('reviewer-private');
  });
});
