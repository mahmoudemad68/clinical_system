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

import { platformGateway, resetPlatformGatewaySession } from './platform-gateway';
import {
  BRANCH_CREATE_INTENT,
  ONBOARD_INTENT,
  OPEN_CASE_INTENT,
  SUBMIT_INTENT,
  UPLOAD_COMPLETE_INTENT,
  UPLOAD_CREATE_INTENT,
  pharmacyBranchCreateIntentFingerprint,
  pharmacyGateway,
  pharmacyIntentKeys,
  resetPharmacyGatewayState,
} from './pharmacy-gateway';
import { UploadTargetError } from './upload-target';
import {
  pharmacyOnboardResponseSchema,
  pharmacyBranchCreateResponseSchema,
  pharmacyUploadStatusResponseSchema,
  pharmacyVerificationSubmitResponseSchema,
} from '@clinic/desktop-bridge-contracts';
import { EVIDENCE_REQUIREMENT_CODE, PharmacyEvidenceHandleStore } from './evidence-handles';
import {
  TimeoutError,
  acceptIpcSchema,
  createDeferred,
  openIpcDeadline,
  runIpcDelivered,
} from './ipc-delivery';
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
    expect((fetchMock.mock.calls[3]?.[1] as { method?: string; redirect?: string; credentials?: string }).method).toBe('PUT');
    expect((fetchMock.mock.calls[3]?.[1] as { redirect?: string }).redirect).toBe('error');
    expect((fetchMock.mock.calls[3]?.[1] as { credentials?: string }).credentials).toBe('omit');
    expect((fetchMock.mock.calls[3]?.[1] as { headers?: Record<string, string> }).headers?.['Authorization']).toBeUndefined();
    for (const call of fetchMock.mock.calls) {
      expect((call[1] as { credentials?: string }).credentials).toBe('omit');
      const names = Object.keys((call[1] as { headers?: Record<string, string> }).headers ?? {}).map((name) =>
        name.toLowerCase(),
      );
      expect(names).not.toContain('cookie');
      expect(names).not.toContain('x-xsrf-token');
    }
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

  function passThrough<T>(value: T): T {
    return value;
  }

  function withUnexpectedField<T extends object>(value: T): unknown {
    return { ...value, extra: 'must-not-cross-ipc' };
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
      passThrough,
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
      passThrough,
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
      passThrough,
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
    await runIpcDelivered(pharmacyIntentKeys, () => pharmacyGateway.openVerificationCase('en'), hang(), passThrough);
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
      passThrough,
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
      passThrough,
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

    async function uploadThroughIpc(
      deadline: Promise<never>,
      deliver: (value: Awaited<ReturnType<typeof pharmacyGateway.uploadEvidence>>) => Awaited<
        ReturnType<typeof pharmacyGateway.uploadEvidence>
      > = acceptIpcSchema(pharmacyUploadStatusResponseSchema),
    ) {
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
        deliver,
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

  it('retries onboarding with the same key when a successful mutation fails the IPC response contract', async () => {
    const ready = envelope(200, {
      status: 'organization_ready',
      organization_id: '0199a5c8-0000-7000-8000-000000000010',
      branch_id: '0199a5c8-0000-7000-8000-000000000011',
      membership_id: '0199a5c8-0000-7000-8000-000000000012',
      version: 1,
    });
    fetchMock.mockResolvedValueOnce(ready);
    fetchMock.mockResolvedValueOnce(ready);

    const fingerprint = onboardFingerprint();
    const accept = acceptIpcSchema(pharmacyOnboardResponseSchema);
    const rejectMalformed = (value: Awaited<ReturnType<typeof pharmacyGateway.onboard>>) =>
      accept(withUnexpectedField(value));

    await expect(
      runIpcDelivered(
        pharmacyIntentKeys,
        () => pharmacyGateway.onboard('en', onboardInput),
        hang(),
        rejectMalformed,
      ),
    ).rejects.toMatchObject({ name: 'ResponseContractError', message: 'INTERNAL_ERROR' });

    const original = pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(pharmacyIntentKeys.keyFor(ONBOARD_INTENT, fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.onboard('en', onboardInput),
      hang(),
      accept,
    );
    expect(delivered.status).toBe('organization_ready');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
  });

  it('retries onboarding with the same key when organization_ready omits required result fields', async () => {
    const incomplete = envelope(200, {
      status: 'organization_ready',
    });
    const ready = envelope(200, {
      status: 'organization_ready',
      organization_id: '0199a5c8-0000-7000-8000-000000000010',
      branch_id: '0199a5c8-0000-7000-8000-000000000011',
      membership_id: '0199a5c8-0000-7000-8000-000000000012',
      version: 1,
    });
    fetchMock.mockResolvedValueOnce(incomplete);
    fetchMock.mockResolvedValueOnce(ready);

    const fingerprint = onboardFingerprint();
    const accept = acceptIpcSchema(pharmacyOnboardResponseSchema);

    await expect(
      runIpcDelivered(
        pharmacyIntentKeys,
        () => pharmacyGateway.onboard('en', onboardInput),
        hang(),
        accept,
      ),
    ).rejects.toMatchObject({ name: 'GatewayError', failureCode: 'UPSTREAM_FAILED' });

    const original = pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(pharmacyIntentKeys.keyFor(ONBOARD_INTENT, fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.onboard('en', onboardInput),
      hang(),
      accept,
    );
    expect(delivered.status).toBe('organization_ready');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
  });

  it('retires the onboarding key after a caller-deliverable manual_review_required result', async () => {
    const review = envelope(200, { status: 'manual_review_required' });
    fetchMock.mockResolvedValueOnce(review);

    const fingerprint = onboardFingerprint();
    const delivered = await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.onboard('en', onboardInput),
      hang(),
      acceptIpcSchema(pharmacyOnboardResponseSchema),
    );
    expect(delivered).toEqual({ status: 'manual_review_required' });
    const used = idempotencyKeys();
    expect(used).toHaveLength(1);
    expect(pharmacyIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
    expect(pharmacyIntentKeys.keyFor(ONBOARD_INTENT, fingerprint)).not.toBe(used[0]);
  });

  it('retries verification submit with the same key when a successful mutation fails the IPC response contract', async () => {
    const submitted = envelope(200, {
      status: 'submitted',
      organization_id: '0199a5c8-0000-7000-8000-000000000010',
      case_id: '0199a5c8-0000-7000-8000-000000000040',
      case_status: 'pending_review',
      case_version: 2,
      organization_version: 1,
      organization_verification_status: 'pending_review',
    });
    fetchMock.mockResolvedValueOnce(submitted);
    fetchMock.mockResolvedValueOnce(submitted);

    const submitInput = { caseVersion: 1, organizationVersion: 1 };
    const fingerprint = pharmacyIntentKeys.fingerprint(submitInput);
    const accept = acceptIpcSchema(pharmacyVerificationSubmitResponseSchema);
    const rejectMalformed = (value: Awaited<ReturnType<typeof pharmacyGateway.submitVerification>>) =>
      accept(withUnexpectedField(value));

    await expect(
      runIpcDelivered(
        pharmacyIntentKeys,
        () => pharmacyGateway.submitVerification('en', submitInput),
        hang(),
        rejectMalformed,
      ),
    ).rejects.toMatchObject({ name: 'ResponseContractError', message: 'INTERNAL_ERROR' });

    const original = pharmacyIntentKeys.peek(SUBMIT_INTENT, fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(pharmacyIntentKeys.keyFor(SUBMIT_INTENT, fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.submitVerification('en', submitInput),
      hang(),
      accept,
    );
    expect(delivered.status).toBe('submitted');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(pharmacyIntentKeys.peek(SUBMIT_INTENT, fingerprint)).toBeUndefined();
  });

  it('keeps the evidence handle and upload keys when the completed projection fails the IPC response contract', async () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'clinic-upload-schema-'));
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
    fetchMock.mockResolvedValueOnce(created);
    fetchMock.mockResolvedValueOnce({ status: 200 });
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
    const completeFingerprint = pharmacyIntentKeys.fingerprint({
      uploadId: '0199a5c8-0000-7000-8000-000000000021',
    });
    const accept = acceptIpcSchema(pharmacyUploadStatusResponseSchema);
    const rejectMalformed = (
      value: Awaited<ReturnType<typeof pharmacyGateway.uploadEvidence>>,
    ) => accept(withUnexpectedField(value));

    async function uploadThroughIpc(
      deliver: (
        value: Awaited<ReturnType<typeof pharmacyGateway.uploadEvidence>>,
      ) => Awaited<ReturnType<typeof pharmacyGateway.uploadEvidence>>,
    ) {
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
        hang(),
        deliver,
      );
      store.invalidate(handleId);
      return uploaded;
    }

    await expect(uploadThroughIpc(rejectMalformed)).rejects.toMatchObject({
      name: 'ResponseContractError',
      message: 'INTERNAL_ERROR',
    });

    const createKey = pharmacyIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint);
    const completeKey = pharmacyIntentKeys.peek(UPLOAD_COMPLETE_INTENT, completeFingerprint);
    expect(store.hasHandle(handleId)).toBe(true);
    expect(createKey).toEqual(expect.any(String));
    expect(completeKey).toEqual(expect.any(String));
    expect(pharmacyIntentKeys.keyFor(UPLOAD_CREATE_INTENT, createFingerprint)).toBe(createKey);
    expect(pharmacyIntentKeys.keyFor(UPLOAD_COMPLETE_INTENT, completeFingerprint)).toBe(completeKey);

    const retry = await uploadThroughIpc(accept);
    expect(retry.uploadId).toBe('0199a5c8-0000-7000-8000-000000000021');
    expect(store.hasHandle(handleId)).toBe(false);
    expect(pharmacyIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toBeUndefined();
    expect(pharmacyIntentKeys.peek(UPLOAD_COMPLETE_INTENT, completeFingerprint)).toBeUndefined();

    const createKeys = fetchMock.mock.calls
      .filter((call) => {
        const url = String(call[0]);
        const method = (call[1] as { method?: string } | undefined)?.method;
        return method === 'POST' && url.includes('/api/v1/verification-uploads') && !url.includes('/complete');
      })
      .map((call) => (call[1] as { headers?: Record<string, string> }).headers?.['Idempotency-Key']);
    const completeKeys = fetchMock.mock.calls
      .filter((call) => {
        const url = String(call[0]);
        const method = (call[1] as { method?: string } | undefined)?.method;
        return method === 'POST' && url.includes('/complete');
      })
      .map((call) => (call[1] as { headers?: Record<string, string> }).headers?.['Idempotency-Key']);
    expect(createKeys[0]).toBe(createKey);
    expect(createKeys[1]).toBe(createKey);
    expect(completeKeys[0]).toBe(completeKey);
    expect(completeKeys[1]).toBe(completeKey);
    rmSync(dir, { recursive: true, force: true });
  });

  it('fail-closes a Core-issued Host header before PUT', async () => {
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
          headers: { Host: '127.0.0.1:19000', 'Content-Type': 'application/pdf' },
          expires_at: '2026-09-21T00:10:00Z',
        },
      }),
    );

    await expect(
      pharmacyGateway.uploadEvidence('en', {
        caseId: '0199a5c8-0000-7000-8000-000000000030',
        bytes: Buffer.from('%PDF-1.4\n%%EOF\n'),
        sizeBytes: 14,
        candidateMediaType: 'application/pdf',
      }),
    ).rejects.toBeInstanceOf(UploadTargetError);
    const putCalls = fetchMock.mock.calls.filter(
      (call) => (call[1] as { method?: string } | undefined)?.method === 'PUT',
    );
    expect(putCalls).toHaveLength(0);
  });
});

describe('pharmacy verification transport without cookie or Host workarounds', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    appState.isPackaged = false;
    resetPlatformGatewaySession();
    resetPharmacyGatewayState();
    process.env['CLINIC_API_BASE_URL'] = 'http://localhost:8080';
  });

  afterEach(() => {
    delete process.env['CLINIC_API_BASE_URL'];
  });

  it('runs login, MFA, organization, onboard, case, and upload on cookieless main transport', async () => {
    fetchMock
      .mockResolvedValueOnce(
        envelope(200, {
          status: 'mfa_required',
          mfa_required: true,
          challenge_id: '0199a5c8-0000-7000-8000-0000000000aa',
          session_kind: 'device',
          account_type: 'pharmacy',
        }),
      )
      .mockResolvedValueOnce(
        envelope(200, {
          status: 'active',
          mfa_required: false,
          session_kind: 'device',
          user_id: '0199a5c8-0000-7000-8000-000000000001',
          account_type: 'pharmacy',
          access_token: 'issued-access',
          refresh_token: 'issued-refresh',
        }),
      )
      .mockResolvedValueOnce({
        status: 404,
        json: async () => ({
          data: null,
          meta: {},
          errors: [{ code: 'NOT_FOUND', message: 'missing' }],
          request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
        }),
      })
      .mockResolvedValueOnce(
        envelope(201, {
          status: 'organization_ready',
          organization_id: '0199a5c8-0000-7000-8000-000000000010',
          branch_id: '0199a5c8-0000-7000-8000-000000000011',
          membership_id: '0199a5c8-0000-7000-8000-000000000012',
          version: 1,
        }),
      )
      .mockResolvedValueOnce(
        envelope(200, {
          status: 'ready',
          organization_id: '0199a5c8-0000-7000-8000-000000000010',
          case_id: '0199a5c8-0000-7000-8000-000000000030',
          case_status: 'draft',
          case_version: 1,
          organization_version: 1,
        }),
      )
      .mockResolvedValueOnce(
        envelope(201, {
          upload_id: '0199a5c8-0000-7000-8000-000000000021',
          requirement_code: 'organization_registration_evidence',
          state: 'uploading',
          rejection_reason: null,
          expires_at: '2026-09-21T00:10:00Z',
          completed_at: null,
          upload_target: {
            method: 'PUT',
            url: 'https://objects.example/upload',
            headers: { 'Content-Type': 'application/pdf' },
            expires_at: '2026-09-21T00:10:00Z',
          },
        }),
      )
      .mockResolvedValueOnce({ status: 200 })
      .mockResolvedValueOnce(
        envelope(200, {
          upload_id: '0199a5c8-0000-7000-8000-000000000021',
          requirement_code: 'organization_registration_evidence',
          state: 'available',
          rejection_reason: null,
          expires_at: '2026-09-21T00:10:00Z',
          completed_at: '2026-09-21T00:01:00Z',
        }),
      );

    await platformGateway.login('en', {
      phone: '01000000000',
      password: 'password-value',
      deviceLabel: 'synthetic',
    });
    await platformGateway.verifyMfa('en', {
      challengeId: '0199a5c8-0000-7000-8000-0000000000aa',
      code: '123456',
    });
    const organization = await pharmacyGateway.getOwnOrganization('en');
    expect(organization.present).toBe(false);
    const onboarded = await pharmacyGateway.onboard('en', {
      legalName: 'Synthetic Pharmacy LLC',
      publicName: 'Synthetic Pharmacy',
      legalRegistrationIdentifier: 'CR-1',
      branchPublicName: 'Main',
      address: '12 Test Street',
      countryCode: 'EG' as const,
      latitude: 30.0444,
      longitude: 31.2357,
      phone: '01011112222',
    });
    expect(onboarded.status).toBe('organization_ready');
    const opened = await pharmacyGateway.openVerificationCase('en');
    expect(opened.caseId).toBe('0199a5c8-0000-7000-8000-000000000030');
    const uploaded = await pharmacyGateway.uploadEvidence('en', {
      caseId: opened.caseId,
      bytes: Buffer.from('%PDF-1.4\n%%EOF\n'),
      sizeBytes: 14,
      candidateMediaType: 'application/pdf',
    });
    expect(uploaded.state).toBe('available');

    for (const call of fetchMock.mock.calls) {
      const init = call[1] as { credentials?: string; headers?: Record<string, string>; method?: string };
      expect(init.credentials).toBe('omit');
      const names = Object.keys(init.headers ?? {}).map((name) => name.toLowerCase());
      expect(names).not.toContain('cookie');
      expect(names).not.toContain('x-xsrf-token');
    }
    const put = fetchMock.mock.calls.find((call) => (call[1] as { method?: string }).method === 'PUT');
    expect(put).toBeDefined();
    expect((put?.[1] as { headers?: Record<string, string> }).headers?.['Authorization']).toBeUndefined();
    expect((put?.[1] as { redirect?: string }).redirect).toBe('error');
    expect(JSON.stringify(uploaded)).not.toContain(CANARIES.signedUrl);
  });
});

const ORG_ID = '0199a5c8-0000-7000-8000-000000000010';
const BRANCH_ID = '0199a5c8-0000-7000-8000-0000000000aa';
const MEMBERSHIP_ID = '0199a5c8-0000-7000-8000-0000000000bb';
const INVITATION_ID = '0199a5c8-0000-7000-8000-0000000000cc';
const PHONE_CANARY = 'CANARY-OPERATOR-PHONE-01099999999';
const ADDRESS_CANARY = 'CANARY-BRANCH-ADDRESS-99 Nile St';
const HMAC_CANARY = 'CANARY-PHONE-HMAC';

function approvedOrganization(overrides: Record<string, unknown> = {}) {
  return {
    organization_id: ORG_ID,
    public_name: 'Safe Pharmacy',
    verification_status: 'approved',
    status: 'active',
    version: 2,
    created_at: '2026-09-21T00:00:00Z',
    updated_at: '2026-09-21T00:00:00Z',
    initial_branch: {
      branch_id: BRANCH_ID,
      public_name: 'Main',
      country_code: 'EG',
      status: 'active',
      version: 2,
    },
    membership: {
      membership_id: '0199a5c8-0000-7000-8000-000000000012',
      role: 'owner',
      status: 'active',
    },
    ...overrides,
  };
}

function apiBranch(overrides: Record<string, unknown> = {}) {
  return {
    branch_id: BRANCH_ID,
    organization_id: ORG_ID,
    public_name: 'Cairo Pharmacy',
    country_code: 'EG',
    status: 'active',
    version: 1,
    created_at: '2026-09-21T00:00:00Z',
    updated_at: '2026-09-21T00:00:00Z',
    address: ADDRESS_CANARY,
    latitude: 30.0444,
    longitude: 31.2357,
    phone: PHONE_CANARY,
    phone_hmac: HMAC_CANARY,
    ...overrides,
  };
}

describe('pharmacy gateway branch and membership projections', () => {
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

  function hang(): Promise<never> {
    return new Promise(() => undefined);
  }

  function lastIdempotencyKey(): string {
    const keys = fetchMock.mock.calls
      .map((call) => (call[1] as { headers?: Record<string, string> } | undefined)?.headers?.['Idempotency-Key'])
      .filter((key): key is string => typeof key === 'string');
    const last = keys.at(-1);
    expect(last).toEqual(expect.any(String));
    return last as string;
  }

  function assertIntentStoreOmitsCanaries(): void {
    const snapshot = pharmacyIntentKeys.snapshot();
    const serialized = JSON.stringify(snapshot);
    expect(serialized).not.toContain(ADDRESS_CANARY);
    expect(serialized).not.toContain(PHONE_CANARY);
    for (const [mapKey, uuid] of snapshot) {
      expect(mapKey).not.toContain(ADDRESS_CANARY);
      expect(mapKey).not.toContain(PHONE_CANARY);
      expect(uuid).not.toContain(ADDRESS_CANARY);
      expect(uuid).not.toContain(PHONE_CANARY);
      expect(uuid).toMatch(
        /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
      );
    }
  }

  const createPayload = {
    publicName: 'Cairo Pharmacy',
    address: ADDRESS_CANARY,
    countryCode: 'EG' as const,
    latitude: 30.0444,
    longitude: 31.2357,
    phone: PHONE_CANARY,
  };

  async function expectChangedCreateKey(patch: Partial<typeof createPayload>): Promise<void> {
    const nextPayload = { ...createPayload, ...patch };
    const firstFingerprint = pharmacyBranchCreateIntentFingerprint(ORG_ID, createPayload);
    const nextFingerprint = pharmacyBranchCreateIntentFingerprint(ORG_ID, nextPayload);
    expect(nextFingerprint).not.toBe(firstFingerprint);

    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockRejectedValueOnce(new Error('network'));
    await expect(pharmacyGateway.createBranch('en', createPayload)).rejects.toThrow();
    const firstKey = lastIdempotencyKey();
    expect(firstKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    );
    expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, firstFingerprint)).toBe(firstKey);

    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce(envelope(201, { branch_id: BRANCH_ID, status: 'active', version: 1 }));
    await pharmacyGateway.createBranch('en', nextPayload);
    const secondKey = lastIdempotencyKey();
    expect(secondKey).not.toBe(firstKey);
    expect(secondKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    );
    expect(secondKey).not.toContain(ADDRESS_CANARY);
    expect(secondKey).not.toContain(PHONE_CANARY);
    expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, firstFingerprint)).toBe(firstKey);
    expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, nextFingerprint)).toBeUndefined();
    assertIntentStoreOmitsCanaries();
  }

  it('lists owner branches without phone, hmac, or organization internals', async () => {
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce({
      status: 200,
      json: async () => ({
        data: [apiBranch()],
        meta: { pagination: { has_more: true, next: 'opaque-cursor', limit: 25 } },
        errors: [],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      }),
    });

    const listed = await pharmacyGateway.listBranches('en', {});
    expect(listed.hasMore).toBe(true);
    expect(listed.nextCursor).toBe('opaque-cursor');
    expect(listed.branches[0]?.publicName).toBe('Cairo Pharmacy');
    expect(listed.branches[0]?.address).toBe(ADDRESS_CANARY);
    const serialized = JSON.stringify(listed);
    expect(serialized).not.toContain(PHONE_CANARY);
    expect(serialized).not.toContain(HMAC_CANARY);
    expect(serialized).not.toContain('organization_id');
    expect(serialized).not.toContain(ORG_ID);
  });

  it('retries the exact branch-create payload with the same Idempotency-Key after an uncertain result', async () => {
    const payload = {
      publicName: 'Cairo Pharmacy',
      address: ADDRESS_CANARY,
      countryCode: 'EG' as const,
      latitude: 30.0444,
      longitude: 31.2357,
      phone: PHONE_CANARY,
    };
    const fingerprint = pharmacyBranchCreateIntentFingerprint(ORG_ID, payload);
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockRejectedValueOnce(new Error('network'));
    await expect(pharmacyGateway.createBranch('en', payload)).rejects.toThrow();
    const firstKey = lastIdempotencyKey();
    expect(firstKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    );
    expect(firstKey).not.toContain(ADDRESS_CANARY);
    expect(firstKey).not.toContain(PHONE_CANARY);
    expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, fingerprint)).toBe(firstKey);
    assertIntentStoreOmitsCanaries();

    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce(envelope(201, { branch_id: BRANCH_ID, status: 'active', version: 1 }));
    const created = await pharmacyGateway.createBranch('en', payload);
    expect(created.branchId).toBe(BRANCH_ID);
    expect(JSON.stringify(created)).not.toContain(ADDRESS_CANARY);
    expect(JSON.stringify(created)).not.toContain(PHONE_CANARY);
    expect(lastIdempotencyKey()).toBe(firstKey);
    expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, fingerprint)).toBeUndefined();
  });

  it('keeps the original branch-create key when a late success arrives after the IPC deadline', async () => {
    const payload = {
      publicName: 'Cairo Pharmacy',
      address: ADDRESS_CANARY,
      countryCode: 'EG' as const,
      latitude: 30.0444,
      longitude: 31.2357,
      phone: PHONE_CANARY,
    };
    const fingerprint = pharmacyBranchCreateIntentFingerprint(ORG_ID, payload);
    const held = createDeferred<ReturnType<typeof envelope>>();
    const ready = envelope(201, { branch_id: BRANCH_ID, status: 'active', version: 1 });
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockImplementationOnce(() => held.promise);

    const deadline = openIpcDeadline();
    const pending = runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.createBranch('en', payload),
      deadline.promise,
      acceptIpcSchema(pharmacyBranchCreateResponseSchema),
    );
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, fingerprint)).toEqual(expect.any(String));
    });
    const original = pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, fingerprint);
    deadline.expire();
    await expect(pending).rejects.toBeInstanceOf(TimeoutError);
    held.resolve(ready);
    await vi.waitFor(() => {
      expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, fingerprint)).toBe(original);
    });
    assertIntentStoreOmitsCanaries();

    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce(envelope(201, { branch_id: BRANCH_ID, status: 'active', version: 1 }));
    const delivered = await runIpcDelivered(
      pharmacyIntentKeys,
      () => pharmacyGateway.createBranch('en', payload),
      hang(),
      acceptIpcSchema(pharmacyBranchCreateResponseSchema),
    );
    expect(delivered.branchId).toBe(BRANCH_ID);
    const keys = fetchMock.mock.calls
      .map((call) => (call[1] as { headers?: Record<string, string> } | undefined)?.headers?.['Idempotency-Key'])
      .filter((key): key is string => typeof key === 'string');
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[keys.length - 1]).toBe(original);
    expect(pharmacyIntentKeys.peek(BRANCH_CREATE_INTENT, fingerprint)).toBeUndefined();
  });

  it('mints a new branch-create key when only the address changes after an uncertain result', async () => {
    await expectChangedCreateKey({ address: 'CANARY-BRANCH-ADDRESS-CHANGED-1 Tahrir' });
  });

  it('mints a new branch-create key when only the phone changes after an uncertain result', async () => {
    await expectChangedCreateKey({ phone: 'CANARY-BRANCH-PHONE-01000000000' });
  });

  it('mints a new branch-create key when public name or coordinates change after an uncertain result', async () => {
    await expectChangedCreateKey({ publicName: 'Nile Pharmacy' });
    resetPharmacyGatewayState();
    await expectChangedCreateKey({ latitude: 30.05, longitude: 31.24 });
  });

  it('does not retain or log branch-create address and phone canaries in the intent map', async () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
    const info = vi.spyOn(console, 'info').mockImplementation(() => undefined);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const debug = vi.spyOn(console, 'debug').mockImplementation(() => undefined);
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockRejectedValueOnce(new Error('network'));
    await expect(
      pharmacyGateway.createBranch('en', {
        publicName: 'Cairo Pharmacy',
        address: ADDRESS_CANARY,
        countryCode: 'EG',
        latitude: 30.0444,
        longitude: 31.2357,
        phone: PHONE_CANARY,
      }),
    ).rejects.toThrow();
    assertIntentStoreOmitsCanaries();
    const firstKey = lastIdempotencyKey();
    expect(firstKey).not.toContain(ADDRESS_CANARY);
    expect(firstKey).not.toContain(PHONE_CANARY);
    for (const spy of [log, info, warn, error, debug]) {
      expect(JSON.stringify(spy.mock.calls)).not.toContain(ADDRESS_CANARY);
      expect(JSON.stringify(spy.mock.calls)).not.toContain(PHONE_CANARY);
      spy.mockRestore();
    }
  });

  it('requires expected_version on update and maps VERSION_CONFLICT', async () => {
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce({
      status: 409,
      json: async () => ({
        data: null,
        meta: {},
        errors: [{ code: 'VERSION_CONFLICT', message: `stale ${PHONE_CANARY}` }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      }),
    });
    await expect(
      pharmacyGateway.updateBranch('en', {
        branchId: BRANCH_ID,
        expectedVersion: 1,
        publicName: 'Cairo Nile Pharmacy',
      }),
    ).rejects.toMatchObject({ failureCode: 'VERSION_CONFLICT' });
  });

  it('marks a 200 invitation replay as existingPending without echoing phone', async () => {
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        invitation_id: INVITATION_ID,
        status: 'pending',
        expires_at: '2026-09-22T00:00:00Z',
        phone: PHONE_CANARY,
        phone_hmac: HMAC_CANARY,
      }),
    );
    const invited = await pharmacyGateway.inviteOperator('en', { branchId: BRANCH_ID, phone: PHONE_CANARY });
    expect(invited.existingPending).toBe(true);
    expect(invited.invitationId).toBe(INVITATION_ID);
    const serialized = JSON.stringify(invited);
    expect(serialized).not.toContain(PHONE_CANARY);
    expect(serialized).not.toContain(HMAC_CANARY);
    const init = fetchMock.mock.calls.at(-1)?.[1] as { body?: string };
    expect(JSON.parse(init.body ?? '{}')).toEqual({ phone: PHONE_CANARY });
  });

  it('maps memberships without user identifiers or phones', async () => {
    fetchMock.mockResolvedValueOnce(envelope(200, approvedOrganization()));
    fetchMock.mockResolvedValueOnce(
      envelope(200, [
        {
          membership_id: MEMBERSHIP_ID,
          branch_id: BRANCH_ID,
          role: 'branch_operator',
          status: 'active',
          version: 1,
          invited_at: '2026-09-21T00:04:00Z',
          accepted_at: '2026-09-21T00:05:00Z',
          revoked_at: null,
          user_id: '0199a5c8-0000-7000-8000-000000000099',
          phone: PHONE_CANARY,
          phone_hmac: HMAC_CANARY,
        },
      ]),
    );
    const listed = await pharmacyGateway.listMemberships('en', BRANCH_ID);
    expect(listed.memberships[0]?.role).toBe('branch_operator');
    const serialized = JSON.stringify(listed);
    expect(serialized).not.toContain(PHONE_CANARY);
    expect(serialized).not.toContain('user_id');
    expect(serialized).not.toContain('0199a5c8-0000-7000-8000-000000000099');
  });

  it('fails closed when the organization is not approved', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(200, approvedOrganization({ verification_status: 'pending_review', status: 'pending' })),
    );
    await expect(pharmacyGateway.listBranches('en', {})).rejects.toMatchObject({
      failureCode: 'PERMISSION_DENIED',
    });
  });
});

