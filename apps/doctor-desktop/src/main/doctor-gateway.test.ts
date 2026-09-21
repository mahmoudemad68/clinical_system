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
    getPath: () => '/tmp/clinic-doctor-origin-test',
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
  PACKAGED_API_ALLOWED_ORIGINS: ['https://doctor.example.com'],
}));

import { platformGateway, resetPlatformGatewaySession } from './platform-gateway';
import {
  ONBOARD_INTENT,
  OPEN_CASE_INTENT,
  SUBMIT_INTENT,
  UPLOAD_COMPLETE_INTENT,
  UPLOAD_CREATE_INTENT,
  doctorGateway,
  doctorIntentKeys,
  resetDoctorGatewayState,
} from './doctor-gateway';
import { UploadTargetError } from './upload-target';
import {
  doctorOnboardResponseSchema,
  doctorUploadStatusResponseSchema,
  doctorVerificationSubmitResponseSchema,
} from '@clinic/desktop-bridge-contracts';
import { EVIDENCE_REQUIREMENT_CODE, DoctorEvidenceHandleStore } from './evidence-handles';
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
  nationalId: 'CANARY-NATIONAL-ID-29801011234567',
  syndicate: 'CANARY-SYNDICATE-998877',
  signedUrl: 'https://objects.example/upload?X-Amz-Signature=CANARY-SIGNATURE',
  locator: 'verification/q/canary-object-key',
  objectId: '0199a5c8-ffff-7000-8000-000000000099',
  notes: 'reviewer-private-notes-must-not-cross-ipc',
  access: 'canary-access-token-value',
  path: '/tmp/clinic-canary-evidence.pdf',
};

const SPECIALTY_ID = '0199a5c8-0000-7000-8000-000000000002';
const DOCTOR_ID = '0199a5c8-0000-7000-8000-000000000010';

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

describe('doctor gateway safe projections', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    appState.isPackaged = false;
    resetPlatformGatewaySession();
    resetDoctorGatewayState();
    process.env['CLINIC_API_BASE_URL'] = 'http://localhost:8080';
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
  });

  afterEach(() => {
    delete process.env['CLINIC_API_BASE_URL'];
  });

  const onboardInput = {
    nationalId: '29801011234567',
    professionalDisplayName: 'Synthetic Doctor',
    specialtyId: SPECIALTY_ID,
    syndicateNumber: 'SYN-1',
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
    return doctorIntentKeys.fingerprint({
      nationalId: onboardInput.nationalId,
      professionalDisplayName: onboardInput.professionalDisplayName,
      specialtyId: onboardInput.specialtyId,
      syndicateNumber: onboardInput.syndicateNumber,
    });
  }

  function idempotencyKeys(): string[] {
    return fetchMock.mock.calls
      .map((call) => (call[1] as { headers?: Record<string, string> } | undefined)?.headers?.['Idempotency-Key'])
      .filter((key): key is string => typeof key === 'string');
  }

  it('maps own profile without protected identifiers or locators', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        doctor_id: DOCTOR_ID,
        professional_display_name: 'Synthetic Doctor',
        specialty_id: SPECIALTY_ID,
        specialty_code: 'gp',
        specialty_label_ar: 'طب الأسرة',
        specialty_label_en: 'General Practice',
        verification_status: 'draft',
        public_status: 'hidden',
        version: 1,
        approved_at: null,
        suspended_at: null,
        created_at: '2026-09-21T00:00:00Z',
        updated_at: '2026-09-21T00:00:00Z',
        national_id: CANARIES.nationalId,
        syndicate_number: CANARIES.syndicate,
        upload_target: { url: CANARIES.signedUrl },
        object_id: CANARIES.objectId,
        notes: CANARIES.notes,
      }),
    );

    const result = await doctorGateway.getOwnProfile('en');
    expect(result.present).toBe(true);
    const serialized = JSON.stringify(result);
    expect(serialized).toContain('Synthetic Doctor');
    expect(serialized).not.toContain(CANARIES.nationalId);
    expect(serialized).not.toContain(CANARIES.syndicate);
    expect(serialized).not.toContain(CANARIES.signedUrl);
    expect(serialized).not.toContain(CANARIES.locator);
    expect(serialized).not.toContain(CANARIES.objectId);
    expect(serialized).not.toContain(CANARIES.notes);
    expect(serialized).not.toContain(CANARIES.access);
  });

  it('lists specialties without internal database metadata', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        specialties: [
          {
            specialty_id: SPECIALTY_ID,
            code: 'gp',
            label_ar: 'طب الأسرة',
            label_en: 'General Practice',
            sort_order: 1,
            created_at: '2026-01-01T00:00:00Z',
            hmac: 'secret',
          },
        ],
      }),
    );
    const listed = await doctorGateway.listSpecialties('en');
    expect(listed.specialties).toEqual([
      {
        specialtyId: SPECIALTY_ID,
        code: 'gp',
        labelAr: 'طب الأسرة',
        labelEn: 'General Practice',
        sortOrder: 1,
      },
    ]);
    expect(JSON.stringify(listed)).not.toContain('hmac');
    expect(JSON.stringify(listed)).not.toContain('created_at');
  });

  it('drops the signed upload target from the renderer projection', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(201, {
        upload_id: '0199a5c8-0000-7000-8000-000000000021',
        requirement_code: 'professional_id',
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
        requirement_code: 'professional_id',
        state: 'quarantined',
        rejection_reason: null,
        expires_at: '2026-09-21T00:10:00Z',
        completed_at: '2026-09-21T00:01:00Z',
      }),
    );

    const uploaded = await doctorGateway.uploadEvidence('en', {
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
    expect(serialized).not.toContain(CANARIES.path);
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

  it('treats a missing own profile as present:false', async () => {
    fetchMock.mockResolvedValueOnce({
      status: 404,
      json: async () => ({
        data: null,
        meta: {},
        errors: [{ code: 'NOT_FOUND', message: 'missing' }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      }),
    });
    const result = await doctorGateway.getOwnProfile('en');
    expect(result).toEqual({ present: false });
  });

  it('fail-closes when /me is not a doctor account', async () => {
    fetchMock.mockReset();
    resetPlatformGatewaySession();
    resetDoctorGatewayState();
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
    await expect(doctorGateway.getOwnProfile('en')).rejects.toMatchObject({
      failureCode: 'PERMISSION_DENIED',
    });
  });

  it('drops reviewer notes from verification status', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(200, {
        doctor_id: DOCTOR_ID,
        profile_verification_status: 'changes_requested',
        profile_public_status: 'hidden',
        profile_version: 2,
        case_id: '0199a5c8-0000-7000-8000-000000000040',
        case_status: 'changes_requested',
        case_version: 3,
        case_type: 'doctor_verification',
        submitted_at: '2026-09-21T00:00:00Z',
        decided_at: '2026-09-21T00:01:00Z',
        decision: 'changes_requested',
        reason_code: 'evidence_incomplete',
        notes: CANARIES.notes,
        documents: [],
      }),
    );
    const status = await doctorGateway.verificationStatus('en');
    const serialized = JSON.stringify(status);
    expect(status.reasonCode).toBe('evidence_incomplete');
    expect(serialized).not.toContain(CANARIES.notes);
    expect(serialized).not.toContain('reviewer-private');
  });

  it('retries onboarding with the same idempotency key after a caller-visible timeout', async () => {
    const held = createDeferred<ReturnType<typeof envelope>>();
    const ready = envelope(200, {
      status: 'profile_ready',
      doctor_id: DOCTOR_ID,
      version: 1,
    });
    fetchMock.mockImplementationOnce(() => held.promise);
    fetchMock.mockResolvedValueOnce(ready);

    const deadline = openIpcDeadline();
    const pending = runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.onboard('en', onboardInput),
      deadline.promise,
      passThrough,
    );
    const fingerprint = onboardFingerprint();
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toEqual(expect.any(String));
    });
    const original = doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint);
    deadline.expire();
    await expect(pending).rejects.toBeInstanceOf(TimeoutError);
    held.resolve(ready);
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBe(original);
    });

    const delivered = await runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.onboard('en', onboardInput),
      hang(),
      passThrough,
    );
    expect(delivered.status).toBe('profile_ready');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
  });

  it('mints a new onboarding key when the payload changes', async () => {
    fetchMock.mockImplementationOnce(() => hang());
    const pending = doctorGateway.onboard('en', onboardInput);
    const firstFingerprint = onboardFingerprint();
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(ONBOARD_INTENT, firstFingerprint)).toEqual(expect.any(String));
    });
    const firstKey = doctorIntentKeys.peek(ONBOARD_INTENT, firstFingerprint);
    const changed = {
      ...onboardInput,
      professionalDisplayName: 'Other Doctor',
    };
    fetchMock.mockImplementationOnce(() => hang());
    const pendingChanged = doctorGateway.onboard('en', changed);
    const changedFingerprint = doctorIntentKeys.fingerprint({
      nationalId: changed.nationalId,
      professionalDisplayName: changed.professionalDisplayName,
      specialtyId: changed.specialtyId,
      syndicateNumber: changed.syndicateNumber,
    });
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(ONBOARD_INTENT, changedFingerprint)).toEqual(expect.any(String));
    });
    expect(doctorIntentKeys.peek(ONBOARD_INTENT, changedFingerprint)).not.toBe(firstKey);
    pending.catch(() => undefined);
    pendingChanged.catch(() => undefined);
  });

  it('retries open and submit with the same key after timeout then retires on delivered success', async () => {
    const openHeld = createDeferred<ReturnType<typeof envelope>>();
    const openReady = envelope(200, {
      status: 'ready',
      doctor_id: DOCTOR_ID,
      case_id: '0199a5c8-0000-7000-8000-000000000040',
      case_status: 'draft',
      case_version: 1,
      profile_version: 1,
    });
    fetchMock.mockImplementationOnce(() => openHeld.promise);
    fetchMock.mockResolvedValueOnce(openReady);

    const openFingerprint = doctorIntentKeys.fingerprint({ intent: OPEN_CASE_INTENT });
    const openDeadline = openIpcDeadline();
    const openPending = runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.openVerificationCase('en'),
      openDeadline.promise,
      passThrough,
    );
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint)).toEqual(expect.any(String));
    });
    const openKey = doctorIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint);
    openDeadline.expire();
    await expect(openPending).rejects.toBeInstanceOf(TimeoutError);
    openHeld.resolve(openReady);
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint)).toBe(openKey);
    });
    await runIpcDelivered(doctorIntentKeys, () => doctorGateway.openVerificationCase('en'), hang(), passThrough);
    expect(doctorIntentKeys.peek(OPEN_CASE_INTENT, openFingerprint)).toBeUndefined();

    const submitHeld = createDeferred<ReturnType<typeof envelope>>();
    const submitReady = envelope(200, {
      status: 'submitted',
      doctor_id: DOCTOR_ID,
      case_id: '0199a5c8-0000-7000-8000-000000000040',
      case_status: 'pending_review',
      case_version: 2,
      profile_version: 1,
      profile_verification_status: 'pending_review',
    });
    fetchMock.mockImplementationOnce(() => submitHeld.promise);
    fetchMock.mockResolvedValueOnce(submitReady);
    const submitInput = { caseVersion: 1, profileVersion: 1 };
    const submitFingerprint = doctorIntentKeys.fingerprint(submitInput);
    const submitDeadline = openIpcDeadline();
    const submitPending = runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.submitVerification('en', submitInput),
      submitDeadline.promise,
      passThrough,
    );
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(SUBMIT_INTENT, submitFingerprint)).toEqual(expect.any(String));
    });
    const submitKey = doctorIntentKeys.peek(SUBMIT_INTENT, submitFingerprint);
    submitDeadline.expire();
    await expect(submitPending).rejects.toBeInstanceOf(TimeoutError);
    submitHeld.resolve(submitReady);
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(SUBMIT_INTENT, submitFingerprint)).toBe(submitKey);
    });
    await runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.submitVerification('en', submitInput),
      hang(),
      passThrough,
    );
    expect(doctorIntentKeys.peek(SUBMIT_INTENT, submitFingerprint)).toBeUndefined();
  });

  it('retires the submit key on a caller-visible version conflict', async () => {
    fetchMock.mockResolvedValueOnce({
      status: 409,
      json: async () => ({
        data: null,
        meta: {},
        errors: [{ code: 'VERSION_CONFLICT', message: 'stale' }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      }),
    });
    const submitInput = { caseVersion: 1, profileVersion: 1 };
    const fingerprint = doctorIntentKeys.fingerprint(submitInput);
    await expect(doctorGateway.submitVerification('en', submitInput)).rejects.toMatchObject({
      failureCode: 'VERSION_CONFLICT',
    });
    expect(doctorIntentKeys.peek(SUBMIT_INTENT, fingerprint)).toBeUndefined();
  });

  it('does not drop the evidence handle or mint a second upload key after an IPC timeout', async () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'clinic-upload-timeout-'));
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, Buffer.from('%PDF-1.4\n%%EOF\n'));
    const store = new DoctorEvidenceHandleStore();
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
    const createFingerprint = doctorIntentKeys.fingerprint({
      caseId,
      sizeBytes,
      mediaType: candidateMediaType,
      requirement: EVIDENCE_REQUIREMENT_CODE,
    });

    async function uploadThroughIpc(
      deadline: Promise<never>,
      deliver: (value: Awaited<ReturnType<typeof doctorGateway.uploadEvidence>>) => Awaited<
        ReturnType<typeof doctorGateway.uploadEvidence>
      > = acceptIpcSchema(doctorUploadStatusResponseSchema),
    ) {
      const uploaded = await runIpcDelivered(
        doctorIntentKeys,
        async () => {
          const bytes = store.readForUpload(handleId);
          return doctorGateway.uploadEvidence('en', {
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
      expect(doctorIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toEqual(expect.any(String));
    });
    const createKey = doctorIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint);
    deadline.expire();
    await expect(pending).rejects.toBeInstanceOf(TimeoutError);
    expect(store.hasHandle(handleId)).toBe(true);

    createHeld.resolve(created);
    const completeFingerprint = doctorIntentKeys.fingerprint({
      uploadId: '0199a5c8-0000-7000-8000-000000000021',
    });
    await vi.waitFor(() => {
      expect(doctorIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toBe(createKey);
      expect(doctorIntentKeys.peek(UPLOAD_COMPLETE_INTENT, completeFingerprint)).toEqual(expect.any(String));
    });
    expect(store.hasHandle(handleId)).toBe(true);

    const status = await doctorGateway.uploadStatus('en', '0199a5c8-0000-7000-8000-000000000021');
    expect(status.state).toBe('quarantined');

    const retry = await uploadThroughIpc(hang());
    expect(retry.uploadId).toBe('0199a5c8-0000-7000-8000-000000000021');
    expect(store.hasHandle(handleId)).toBe(false);
    expect(doctorIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toBeUndefined();
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
      status: 'profile_ready',
      doctor_id: DOCTOR_ID,
      version: 1,
    });
    fetchMock.mockResolvedValueOnce(ready);
    fetchMock.mockResolvedValueOnce(ready);

    const fingerprint = onboardFingerprint();
    const accept = acceptIpcSchema(doctorOnboardResponseSchema);
    const rejectMalformed = (value: Awaited<ReturnType<typeof doctorGateway.onboard>>) =>
      accept(withUnexpectedField(value));

    await expect(
      runIpcDelivered(
        doctorIntentKeys,
        () => doctorGateway.onboard('en', onboardInput),
        hang(),
        rejectMalformed,
      ),
    ).rejects.toMatchObject({ name: 'ResponseContractError', message: 'INTERNAL_ERROR' });

    const original = doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(doctorIntentKeys.keyFor(ONBOARD_INTENT, fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.onboard('en', onboardInput),
      hang(),
      accept,
    );
    expect(delivered.status).toBe('profile_ready');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
  });

  it('retries onboarding with the same key when profile_ready omits required result fields', async () => {
    const incomplete = envelope(200, {
      status: 'profile_ready',
    });
    const ready = envelope(200, {
      status: 'profile_ready',
      doctor_id: DOCTOR_ID,
      version: 1,
    });
    fetchMock.mockResolvedValueOnce(incomplete);
    fetchMock.mockResolvedValueOnce(ready);

    const fingerprint = onboardFingerprint();
    const accept = acceptIpcSchema(doctorOnboardResponseSchema);

    await expect(
      runIpcDelivered(
        doctorIntentKeys,
        () => doctorGateway.onboard('en', onboardInput),
        hang(),
        accept,
      ),
    ).rejects.toMatchObject({ name: 'GatewayError', failureCode: 'UPSTREAM_FAILED' });

    const original = doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(doctorIntentKeys.keyFor(ONBOARD_INTENT, fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.onboard('en', onboardInput),
      hang(),
      accept,
    );
    expect(delivered.status).toBe('profile_ready');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
  });

  it('retires the onboarding key after a caller-deliverable manual_review_required result', async () => {
    const review = envelope(200, { status: 'manual_review_required' });
    fetchMock.mockResolvedValueOnce(review);

    const fingerprint = onboardFingerprint();
    const delivered = await runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.onboard('en', onboardInput),
      hang(),
      acceptIpcSchema(doctorOnboardResponseSchema),
    );
    expect(delivered).toEqual({ status: 'manual_review_required' });
    const used = idempotencyKeys();
    expect(used).toHaveLength(1);
    expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
    expect(doctorIntentKeys.keyFor(ONBOARD_INTENT, fingerprint)).not.toBe(used[0]);
  });

  it('retries verification submit with the same key when a successful mutation fails the IPC response contract', async () => {
    const submitted = envelope(200, {
      status: 'submitted',
      doctor_id: DOCTOR_ID,
      case_id: '0199a5c8-0000-7000-8000-000000000040',
      case_status: 'pending_review',
      case_version: 2,
      profile_version: 1,
      profile_verification_status: 'pending_review',
    });
    fetchMock.mockResolvedValueOnce(submitted);
    fetchMock.mockResolvedValueOnce(submitted);

    const submitInput = { caseVersion: 1, profileVersion: 1 };
    const fingerprint = doctorIntentKeys.fingerprint(submitInput);
    const accept = acceptIpcSchema(doctorVerificationSubmitResponseSchema);
    const rejectMalformed = (value: Awaited<ReturnType<typeof doctorGateway.submitVerification>>) =>
      accept(withUnexpectedField(value));

    await expect(
      runIpcDelivered(
        doctorIntentKeys,
        () => doctorGateway.submitVerification('en', submitInput),
        hang(),
        rejectMalformed,
      ),
    ).rejects.toMatchObject({ name: 'ResponseContractError', message: 'INTERNAL_ERROR' });

    const original = doctorIntentKeys.peek(SUBMIT_INTENT, fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(doctorIntentKeys.keyFor(SUBMIT_INTENT, fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      doctorIntentKeys,
      () => doctorGateway.submitVerification('en', submitInput),
      hang(),
      accept,
    );
    expect(delivered.status).toBe('submitted');
    const keys = idempotencyKeys();
    expect(keys.length).toBeGreaterThanOrEqual(2);
    expect(keys[0]).toBe(original);
    expect(keys[1]).toBe(original);
    expect(doctorIntentKeys.peek(SUBMIT_INTENT, fingerprint)).toBeUndefined();
  });

  it('keeps the evidence handle and upload keys when the completed projection fails the IPC response contract', async () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'clinic-upload-schema-'));
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, Buffer.from('%PDF-1.4\n%%EOF\n'));
    const store = new DoctorEvidenceHandleStore();
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
    const createFingerprint = doctorIntentKeys.fingerprint({
      caseId,
      sizeBytes,
      mediaType: candidateMediaType,
      requirement: EVIDENCE_REQUIREMENT_CODE,
    });
    const completeFingerprint = doctorIntentKeys.fingerprint({
      uploadId: '0199a5c8-0000-7000-8000-000000000021',
    });
    const accept = acceptIpcSchema(doctorUploadStatusResponseSchema);
    const rejectMalformed = (
      value: Awaited<ReturnType<typeof doctorGateway.uploadEvidence>>,
    ) => accept(withUnexpectedField(value));

    async function uploadThroughIpc(
      deliver: (
        value: Awaited<ReturnType<typeof doctorGateway.uploadEvidence>>,
      ) => Awaited<ReturnType<typeof doctorGateway.uploadEvidence>>,
    ) {
      const uploaded = await runIpcDelivered(
        doctorIntentKeys,
        async () => {
          const bytes = store.readForUpload(handleId);
          return doctorGateway.uploadEvidence('en', {
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

    const createKey = doctorIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint);
    const completeKey = doctorIntentKeys.peek(UPLOAD_COMPLETE_INTENT, completeFingerprint);
    expect(store.hasHandle(handleId)).toBe(true);
    expect(createKey).toEqual(expect.any(String));
    expect(completeKey).toEqual(expect.any(String));
    expect(doctorIntentKeys.keyFor(UPLOAD_CREATE_INTENT, createFingerprint)).toBe(createKey);
    expect(doctorIntentKeys.keyFor(UPLOAD_COMPLETE_INTENT, completeFingerprint)).toBe(completeKey);

    const retry = await uploadThroughIpc(accept);
    expect(retry.uploadId).toBe('0199a5c8-0000-7000-8000-000000000021');
    expect(store.hasHandle(handleId)).toBe(false);
    expect(doctorIntentKeys.peek(UPLOAD_CREATE_INTENT, createFingerprint)).toBeUndefined();
    expect(doctorIntentKeys.peek(UPLOAD_COMPLETE_INTENT, completeFingerprint)).toBeUndefined();

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

  it('clears doctor intent state and evidence handles on logout-equivalent reset', async () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'clinic-logout-handles-'));
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, Buffer.from('%PDF-1.4\n%%EOF\n'));
    const store = new DoctorEvidenceHandleStore();
    const selected = store.registerSelectedFile(file);
    if (!selected.selected) {
      rmSync(dir, { recursive: true, force: true });
      throw new Error('expected selection');
    }
    const fingerprint = onboardFingerprint();
    const key = doctorIntentKeys.keyFor(ONBOARD_INTENT, fingerprint);
    expect(key).toEqual(expect.any(String));
    expect(store.hasHandle(selected.handleId)).toBe(true);
    resetDoctorGatewayState();
    store.clear();
    expect(doctorIntentKeys.peek(ONBOARD_INTENT, fingerprint)).toBeUndefined();
    expect(store.hasHandle(selected.handleId)).toBe(false);
    rmSync(dir, { recursive: true, force: true });
  });

  it('fail-closes a Core-issued Host header before PUT', async () => {
    fetchMock.mockResolvedValueOnce(
      envelope(201, {
        upload_id: '0199a5c8-0000-7000-8000-000000000021',
        requirement_code: 'professional_id',
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
      doctorGateway.uploadEvidence('en', {
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

describe('doctor verification transport without cookie or Host workarounds', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    appState.isPackaged = false;
    resetPlatformGatewaySession();
    resetDoctorGatewayState();
    process.env['CLINIC_API_BASE_URL'] = 'http://localhost:8080';
  });

  afterEach(() => {
    delete process.env['CLINIC_API_BASE_URL'];
  });

  it('runs login, MFA, profile, onboard, case, and upload on cookieless main transport', async () => {
    fetchMock
      .mockResolvedValueOnce(
        envelope(200, {
          status: 'mfa_required',
          mfa_required: true,
          challenge_id: '0199a5c8-0000-7000-8000-0000000000aa',
          session_kind: 'device',
          account_type: 'doctor',
        }),
      )
      .mockResolvedValueOnce(
        envelope(200, {
          status: 'active',
          mfa_required: false,
          session_kind: 'device',
          user_id: '0199a5c8-0000-7000-8000-000000000001',
          account_type: 'doctor',
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
          status: 'profile_ready',
          doctor_id: DOCTOR_ID,
          version: 1,
        }),
      )
      .mockResolvedValueOnce(
        envelope(200, {
          status: 'ready',
          doctor_id: DOCTOR_ID,
          case_id: '0199a5c8-0000-7000-8000-000000000030',
          case_status: 'draft',
          case_version: 1,
          profile_version: 1,
        }),
      )
      .mockResolvedValueOnce(
        envelope(201, {
          upload_id: '0199a5c8-0000-7000-8000-000000000021',
          requirement_code: 'professional_id',
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
          requirement_code: 'professional_id',
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
    const profile = await doctorGateway.getOwnProfile('en');
    expect(profile.present).toBe(false);
    const onboarded = await doctorGateway.onboard('en', {
      nationalId: '29801011234567',
      professionalDisplayName: 'Synthetic Doctor',
      specialtyId: SPECIALTY_ID,
      syndicateNumber: 'SYN-1',
    });
    expect(onboarded.status).toBe('profile_ready');
    const opened = await doctorGateway.openVerificationCase('en');
    expect(opened.caseId).toBe('0199a5c8-0000-7000-8000-000000000030');
    const uploaded = await doctorGateway.uploadEvidence('en', {
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
