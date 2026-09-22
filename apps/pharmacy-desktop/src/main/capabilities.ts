import { app, BrowserWindow, dialog, ipcMain, type IpcMainInvokeEvent } from 'electron';
import {
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  PHARMACY_ALL_CHANNELS,
  PHARMACY_CAPABILITY_REGISTRY,
  PHARMACY_CHANNELS,
  withinSizeBound,
  type BridgeError,
  type BridgeResult,
  type PharmacyRegisteredChannelName,
} from '@clinic/desktop-bridge-contracts';
import { APP_CONFIG } from '../shared/app-config';
import { isTrustedFrameOrigin } from '../shared/sender-policy';
import { EvidenceFileError, PharmacyEvidenceHandleStore } from './evidence-handles';
import { runIpcDelivered, timeoutDeadline, TimeoutError, ResponseContractError } from './ipc-delivery';
import { pharmacyGateway, pharmacyIntentKeys } from './pharmacy-gateway';
import { GatewayError, platformGateway, SecureStorageUnavailableError } from './platform-gateway';
import { UploadTargetError } from './upload-target';

/**
 * The privileged side of the IPC boundary.
 *
 * Shared Auth/Platform handlers come from CAPABILITY_REGISTRY. Pharmacy domain
 * handlers come from PHARMACY_CAPABILITY_REGISTRY and are registered only here,
 * in the Pharmacy application. The Doctor desktop never imports this file.
 */

let localeState: 'ar' | 'en' = 'en';
const evidenceHandles = new PharmacyEvidenceHandleStore();

const PHARMACY_REGISTRY = {
  ...CAPABILITY_REGISTRY,
  ...PHARMACY_CAPABILITY_REGISTRY,
} as const;

export function registerCapabilities(): void {
  handle(CHANNELS.appMetadata, async () => ({
    appId: APP_CONFIG.appId,
    productName: APP_CONFIG.productName,
    appVersion: process.env['npm_package_version'] ?? '0.1.0',
    contractVersion: BRIDGE_CONTRACT_VERSION,
  }));

  handle(CHANNELS.platformHealth, async () => platformGateway.health(localeState));
  handle(CHANNELS.platformVersion, async () => platformGateway.version(localeState));

  handle(CHANNELS.localeGet, async () => ({ locale: localeState }));
  handle(CHANNELS.localeSet, async (payload: { locale: 'ar' | 'en' }) => {
    localeState = payload.locale;
    return { locale: localeState };
  });

  handle(CHANNELS.authSecureStatus, async () => platformGateway.secureStatus());
  handle(CHANNELS.authLogin, async (payload: { phone: string; password: string; deviceLabel: string }) =>
    platformGateway.login(localeState, payload),
  );
  handle(CHANNELS.authVerifyMfa, async (payload: { challengeId: string; code: string }) =>
    platformGateway.verifyMfa(localeState, payload),
  );
  handle(CHANNELS.authLogout, async () => {
    const result = await platformGateway.logout(localeState);
    clearPharmacySession();
    return result;
  });
  handle(CHANNELS.authMe, async () => platformGateway.me(localeState));
  handle(CHANNELS.authSessions, async () => platformGateway.sessions(localeState));
  handle(CHANNELS.authRevokeSession, async (payload: { sessionId: string }) =>
    platformGateway.revokeSession(localeState, payload.sessionId),
  );

  handle(PHARMACY_CHANNELS.organizationGetOwn, async () => pharmacyGateway.getOwnOrganization(localeState));
  handle(PHARMACY_CHANNELS.organizationOnboard, async (payload) =>
    pharmacyGateway.onboard(localeState, payload),
  );
  handle(PHARMACY_CHANNELS.verificationOpenCase, async () => pharmacyGateway.openVerificationCase(localeState));
  handle(PHARMACY_CHANNELS.verificationStatus, async () => pharmacyGateway.verificationStatus(localeState));
  handle(PHARMACY_CHANNELS.verificationSubmit, async (payload) =>
    pharmacyGateway.submitVerification(localeState, payload),
  );
  handle(PHARMACY_CHANNELS.evidenceSelect, async (_payload, event) => selectEvidence(event));
  handle(PHARMACY_CHANNELS.evidenceClear, async (payload: { handleId: string }) => {
    evidenceHandles.clearHandle(payload.handleId);
    return { cleared: true as const };
  });
  handle(PHARMACY_CHANNELS.evidenceUpload, async (payload: { handleId: string; caseId: string }) => {
    const file = evidenceHandles.readForUpload(payload.handleId);
    return pharmacyGateway.uploadEvidence(localeState, {
      caseId: payload.caseId,
      bytes: file.bytes,
      sizeBytes: file.sizeBytes,
      candidateMediaType: file.candidateMediaType,
    });
  });
  handle(PHARMACY_CHANNELS.uploadStatus, async (payload: { uploadId: string }) =>
    pharmacyGateway.uploadStatus(localeState, payload.uploadId),
  );
  handle(PHARMACY_CHANNELS.branchesList, async (payload) =>
    pharmacyGateway.listBranches(localeState, payload),
  );
  handle(PHARMACY_CHANNELS.branchCreate, async (payload) =>
    pharmacyGateway.createBranch(localeState, payload),
  );
  handle(PHARMACY_CHANNELS.branchGet, async (payload: { branchId: string }) =>
    pharmacyGateway.getBranch(localeState, payload.branchId),
  );
  handle(PHARMACY_CHANNELS.branchUpdate, async (payload) =>
    pharmacyGateway.updateBranch(localeState, payload),
  );
  handle(PHARMACY_CHANNELS.branchInviteOperator, async (payload) =>
    pharmacyGateway.inviteOperator(localeState, payload),
  );
  handle(PHARMACY_CHANNELS.branchMemberships, async (payload: { branchId: string }) =>
    pharmacyGateway.listMemberships(localeState, payload.branchId),
  );
  handle(PHARMACY_CHANNELS.branchRevokeMembership, async (payload) =>
    pharmacyGateway.revokeMembership(localeState, payload),
  );
}

async function selectEvidence(event: IpcMainInvokeEvent) {
  const window = BrowserWindow.fromWebContents(event.sender);
  if (window === null) {
    throw new GatewayError('PERMISSION_DENIED');
  }

  const choice = await dialog.showOpenDialog(window, {
    title: 'Select pharmacy registration evidence',
    properties: ['openFile'],
    filters: [
      { name: 'PDF, JPEG, or PNG', extensions: ['pdf', 'jpg', 'jpeg', 'png'] },
    ],
  });

  if (choice.canceled || choice.filePaths.length === 0) {
    return { selected: false as const };
  }

  const selectedPath = choice.filePaths[0];
  if (typeof selectedPath !== 'string') {
    return { selected: false as const };
  }

  return evidenceHandles.registerSelectedFile(selectedPath);
}

export function clearPharmacySession(): void {
  evidenceHandles.clear();
  pharmacyGateway.clearIntents();
}

function handle(
  channel: PharmacyRegisteredChannelName,
  execute: (payload: never, event: IpcMainInvokeEvent) => Promise<unknown>,
): void {
  const contract = PHARMACY_REGISTRY[channel];

  ipcMain.handle(channel, async (event: IpcMainInvokeEvent, rawPayload: unknown): Promise<BridgeResult<unknown>> => {
    if (!isTrustedSender(event)) {
      return fail('PERMISSION_DENIED', 'Caller is not permitted to use this capability.');
    }

    if (!withinSizeBound(rawPayload, MAX_IPC_PAYLOAD_BYTES)) {
      return fail('PAYLOAD_TOO_LARGE', 'The request exceeded the maximum IPC payload size.');
    }

    const parsed = contract.request.safeParse(rawPayload ?? {});
    if (!parsed.success) {
      return fail('INVALID_REQUEST', 'The request did not match the expected shape.');
    }

    try {
      const value = await runIpcDelivered(
        pharmacyIntentKeys,
        () => execute(parsed.data as never, event),
        timeoutDeadline(contract.timeoutMs),
        (raw) => {
          const validated = contract.response.safeParse(raw);
          if (!validated.success) {
            throw new ResponseContractError();
          }
          return validated.data;
        },
      );

      if (channel === PHARMACY_CHANNELS.evidenceUpload) {
        evidenceHandles.invalidate((parsed.data as { handleId: string }).handleId);
      }

      return { ok: true, value };
    } catch (error) {
      return mapThrown(error);
    }
  });
}

const trustedWebContentsIds = new Set<number>();

export function trustWindow(window: BrowserWindow): void {
  const id = window.webContents.id;
  trustedWebContentsIds.add(id);
  window.on('closed', () => {
    trustedWebContentsIds.delete(id);
    clearPharmacySession();
  });
}

function isTrustedSender(event: IpcMainInvokeEvent): boolean {
  if (!trustedWebContentsIds.has(event.sender.id)) {
    return false;
  }

  const frame = event.senderFrame;

  if (frame === null || frame.parent !== null) {
    return false;
  }

  return isTrustedFrameOrigin(frame.url, APP_CONFIG.packagedOrigin, app.isPackaged);
}

function mapThrown(error: unknown): BridgeResult<never> {
  if (error instanceof TimeoutError) {
    return fail('TIMEOUT', 'The operation took too long.');
  }

  if (error instanceof ResponseContractError) {
    return fail('INTERNAL_ERROR', 'The capability produced an unexpected result.');
  }

  if (error instanceof SecureStorageUnavailableError) {
    return fail('CAPABILITY_NOT_AVAILABLE', error.message);
  }

  if (error instanceof EvidenceFileError) {
    return fail(error.code, evidenceMessage(error.code));
  }

  if (error instanceof UploadTargetError) {
    return fail('UPSTREAM_FAILED', 'The operation could not be completed.');
  }

  if (error instanceof GatewayError) {
    return fail(safeGatewayCode(error.failureCode), safeGatewayMessage(error.failureCode));
  }

  if (error instanceof Error && error.message === 'UNAUTHENTICATED') {
    return fail('UNAUTHENTICATED', 'The session is no longer valid.');
  }

  return fail('UPSTREAM_FAILED', 'The operation could not be completed.');
}

function evidenceMessage(code: EvidenceFileError['code']): string {
  if (code === 'FILE_MISSING') {
    return 'The selected file is no longer available.';
  }
  if (code === 'FILE_CHANGED') {
    return 'The selected file changed after it was chosen. Choose the file again.';
  }
  if (code === 'UNSUPPORTED_FILE') {
    return 'Choose a PDF, JPEG, or PNG file of at most 20 MiB.';
  }
  return 'The request did not match the expected shape.';
}

function safeGatewayCode(code: string): BridgeError['code'] {
  switch (code) {
    case 'UNAUTHENTICATED':
    case 'PERMISSION_DENIED':
    case 'NOT_FOUND':
    case 'VERSION_CONFLICT':
    case 'STATE_CONFLICT':
    case 'VALIDATION_FAILED':
    case 'CANCELLED':
    case 'TIMEOUT':
      return code;
    default:
      return 'UPSTREAM_FAILED';
  }
}

function safeGatewayMessage(code: string): string {
  switch (code) {
    case 'UNAUTHENTICATED':
      return 'The session is no longer valid.';
    case 'PERMISSION_DENIED':
      return 'This account cannot use pharmacy onboarding.';
    case 'NOT_FOUND':
      return 'The requested record is not available.';
    case 'VERSION_CONFLICT':
    case 'STATE_CONFLICT':
      return 'The server state changed. Refresh and continue from the current status.';
    case 'VALIDATION_FAILED':
      return 'The form could not be submitted. Check the highlighted fields.';
    default:
      return 'The operation could not be completed.';
  }
}

function fail(code: BridgeError['code'], message: string): BridgeResult<never> {
  return { ok: false, error: { code, message } };
}

/** Exported for the test that asserts no channel exists outside the registry. */
export const REGISTERED_CHANNELS = PHARMACY_ALL_CHANNELS;

export const pharmacyEvidenceHandleStore = evidenceHandles;
