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
import {
  ONBOARD_INTENT,
  OPEN_CASE_INTENT,
  SUBMIT_INTENT,
  UPLOAD_COMPLETE_INTENT,
  UPLOAD_CREATE_INTENT,
  pharmacyGateway,
  pharmacyIntentKeys,
  resetPharmacyGatewayState,
} from './pharmacy-gateway';
import { EVIDENCE_REQUIREMENT_CODE, PharmacyEvidenceHandleStore } from './evidence-handles';
import { TimeoutError, createDeferred, openIpcDeadline, runIpcDelivered } from './ipc-delivery';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

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

  const onboardInput = {
    legalName: 'Legal Pharmacy',
    publicName: 'Public Pharmacy',
    legalRegistrationIdentifier: 'CR-1',
    branchPublicName: 'Main',
    address: '1 Nile',
    countryCode: 'EG' as const,
    latitude: 30.0444,
    longitude: 31.2357,
    phone: '01900000000',
  };

  function hang(): Promise<never> {
    return new Promise(() => undefined);
  }

  function onboardFingerprint(): string {
    return pharmacyIntentKeys.fingerprint({
      legalName: onboardInput.legalName,
      publicName: onboardInput.publicName,
      legalRegistrationIdentifier: onboardInput.legalRegistrationIdentifier,
      branchPublicName: onboardInput.branchPublicName,
      address: onboardInput.address,
      countryCode: onboardInput.countryCode,
      latitude: onboardInput.latitude,
      longitude: onboardInput.longitude,
      phone: onboardInput.phone,
    });
  }

  function idempotencyKeys(): string[] {
    return fetchMock.mock.calls
      .map((call) => (call[1] as { headers?: Record<string, string> } | undefined)?.headers?.['Idempotency-Key'])
      .filter((key): key is string => typeof key === 'string');
  }

  it('retries onboarding with the same idempotency key after a caller-visible timeout', async () => {
    const held = createDeferred<ReturnType<typeof envelope>>();
    const ready = envelope(200, {
      status: 'organization_ready',
      organization_id: '0199a5c8-0000-7000-8000-000000000010',
      branch_id: '0199a5c8-0000-7000-8000-000000000011',
      membership_id: '0199a5c8-0000-7000-8000-000000000012',
      version: 1,
    });
    fetchMock.mockImplementationOnce(() => held.promise);
    fetchMock.mockResolvedValueOnce(ready);

    const deadline = openIpcDeadline();
    const pending = runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.onboard('en', onboardInput),
      deadline.promise,
    );
    const fingerprint = onboardFingerprint();
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toEqual(expect.any(String));
    });
    const original = pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint);
    deadline.expire();
    await expect(pending).rejects.toBeInstanceOf(TimeoutError);
    held.resolve(ready);
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBe(original);
    });

    const delivered = await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.onboard('en', onboardInput),
      hang(),
    );
    expect(delivered.status).toBe('organization_ready');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
  });

  it('retries open and submit with the same key after timeout then retires on delivered success', async () => {
    const openHeld = createDeferred<ReturnType<typeof envelope>>();
    const openReady = envelope(200, {
      status: 'ready',
      organization_id: '0199a5c8-0000-7000-8000-000000000010',
      case_id: '0199a5c8-0000-7000-8000-000000000040',
      case_status: 'draft',
      case_version: 1,
      organization_version: 1,
    });
    fetchMock.mockImplementationOnce(() => openHeld.promise);
    fetchMock.mockResolvedValueOnce(openReady);

    const openFingerprint = pharmacyIntentKeys.fingerprint({ intent: OPEN_CASE_INTENT });
    const openDeadline = openIpcDeadline();
    const openPending = runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.openVerificationCase('en'),
      openDeadline.promise,
    );
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint)).toEqual(expect.any(String));
    });
    const openKey = pharmacyIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint);
    openDeadline.expire();
    await expect(openPending).rejects.toBeInstanceOf(TimeoutError);
    openHeld.resolve(openReady);
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint)).toBe(openKey);
    });
    await runIpcDelivered(pharmacyIntentKeys, () => pharmacyGateway.openVerificationCase('en'), hang());
    expect(pharmacyIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint)).toBeUndefined();

    const submitHeld = createDeferred<ReturnType<typeof envelope>>();
    const submitReady = envelope(200, {
      status: 'submitted',
      organization_id: '0199a5c8-0000-7000-8000-000000000010',
      case_id: '0199a5c8-0000-7000-8000-000000000040',
      case_status: 'pending_review',
      case_version: 2,
      organization_version: 1,
      organization_verification_status: 'pending_review',
    });
    fetchMock.mockImplementationOnce(() => submitHeld.promise);
    fetchMock.mockResolvedValueOnce(submitReady);
    const submitInput = { caseVersion: 1, organizationVersion: 1 };
    const submitFingerprint = pharmacyIntentKeys.fingerprint(submitInput);
    const submitDeadline = openIpcDeadline();
    const submitPending = runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.submitVerification('en', submitInput),
      submitDeadline.promise,
    );
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(SUBMIT_INTENT, submitFingerprint)).toEqual(expect.any(String));
    });
    const submitKey = pharmacyIntentKeys.peek(SUBMIT_INTENT, submitFingerprint);
    submitDeadline.expire();
    await expect(submitPending).rejects.toBeInstanceOf(TimeoutError);
    submitHeld.resolve(submitReady);
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(SUBMIT_INTENT, submitFingerprint)).toBe(submitKey);
    });
    await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.submitVerification('en', submitInput),
      hang(),
    );
    expect(pharmacyIntentKeys.peek(SUBMIT_INTENT, submitFingerprint)).toBeUndefined();
  });

  it('does not drop the evidence handle or mint a second upload key after an IPC timeout', async () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'clinic-upload-timeout-'));
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, Buffer.from('%PDF-1.4\n%%EOF\n'));
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file);
    if (!selected.selected) {
      rmSync(dir, { recursive: true, force: true });
      throw new Error('expected selection');
    }
    const handleId = selected.handleId;
    const sizeBytes = selected.sizeBytes;
    const candidateMediaType = selected.candidateMediaType;

    const created = envelope(201, {
      upload_id: '0199a5c8-0000-7000-8000-000000000021',
      requirement_code: EVIDENCE_REQUIREMENT_CODE,
      state: 'uploading',
      rejection_reason: null,
      expires_at: '2026-09-21T00:10:00Z',
      completed_at: null,
      upload_target: {
        method: 'PUT',
        url: 'http://127.0.0.1:9000/quarantine',
        headers: { 'Content-Type': 'application/pdf' },
        expires_at: '2026-09-21T00:10:00Z',
      },
    });
    const completed = envelope(200, {
      upload_id: '0199a5c8-0000-7000-8000-000000000021',
      requirement_code: EVIDENCE_REQUIREMENT_CODE,
      state: 'quarantined',
      rejection_reason: null,
      expires_at: '2026-09-21T00:10:00Z',
      completed_at: '2026-09-21T00:01:00Z',
    });
    const createHeld = createDeferred<ReturnType<typeof envelope>>();
    fetchMock.mockImplementationOnce(() => createHeld.promise);
    fetchMock.mockResolvedValueOnce({ status: 200 });
    fetchMock.mockResolvedValueOnce(completed);
    fetchMock.mockResolvedValueOnce(completed);
    fetchMock.mockResolvedValueOnce(created);
    fetchMock.mockResolvedValueOnce({ status: 200 });
    fetchMock.mockResolvedValueOnce(completed);

    const caseId = '0199a5c8-0000-7000-8000-000000000030';
    const createFingerprint = pharmacyIntentKeys.fingerprint({
      caseId,
      sizeBytes,
      mediaType: candidateMediaType,
      requirement: EVIDENCE_REQUIREMENT_CODE,
    });

    async function uploadThroughIpc(deadline: Promise<never>) {
      const uploaded = await runIpcDelivered(
        pharmacyIntentKeys,
        async () => {
          const bytes = store.readForUpload(handleId);
          return pharmacyGateway.uploadEvidence('en', {
            caseId,
            bytes: bytes.bytes,
            sizeBytes: bytes.sizeBytes,
            candidateMediaType: bytes.candidateMediaType,
          });
        },
        deadline,
      );
      store.invalidate(handleId);
      return uploaded;
    }

    const deadline = openIpcDeadline();
    const pending = uploadThroughIpc(deadline.promise);
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toEqual(expect.any(String));
    });
    const createKey = pharmacyIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint);
    deadline.expire();
    await expect(pending).rejects.toBeInstanceOf(TimeoutError);
    expect(store.hasHandle(handleId)).toBe(true);

    createHeld.resolve(created);
    const completeFingerprint = pharmacyIntentKeys.fingerprint({
      uploadId: '0199a5c8-0000-7000-8000-000000000021',
    });
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toBe(createKey);
      expect(pharmacyIntentKeys.peek(UPLOAD_COMPLETE_INTENT, completeFingerprint)).toEqual(expect.any(String));
    });
    expect(store.hasHandle(handleId)).toBe(true);

    const status = await pharmacyGateway.uploadStatus('en', '0199a5c8-0000-7000-8000-000000000021');
    expect(status.state).toBe('quarantined');

    const retry = await uploadThroughIpc(hang());
    expect(retry.uploadId).toBe('0199a5c8-0000-7000-8000-000000000021');
    expect(store.hasHandle(handleId)).toBe(false);
    expect(pharmacyIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toBeUndefined();
    const createKeys = fetchMock.mock.calls
      .filter((call) => {
        const url = String(call[0]);
        const method = (call[1] as { method?: string } | undefined)?.method;
        return method === 'POST' && url.includes('/api/v1/verification-uploads') && !url.includes('/complete');
      })
      .map((call) => (call[1] as { headers?: Record<string, string> }).headers?.['Idempotency-Key']);
    expect(createKeys[0]).toBe(createKey);
    expect(createKeys[1]).toBe(createKey);
    rmSync(dir, { recursive: true, force: true });
  });
});
