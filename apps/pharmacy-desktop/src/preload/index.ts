import { contextBridge, ipcRenderer } from 'electron';
import {
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  PHARMACY_CAPABILITY_REGISTRY,
  PHARMACY_CHANNELS,
  withinSizeBound,
  type BridgeResult,
  type PharmacyClinicBridge,
  type PharmacyRegisteredChannelName,
} from '@clinic/desktop-bridge-contracts';

/**
 * The bridge. Deliberately tiny.
 *
 * Pharmacy domain methods are listed explicitly. There is no generic invoke,
 * no channel string parameter, and no way to name a Doctor-only or unknown
 * channel from this renderer.
 */

const PHARMACY_BRIDGE_REGISTRY = {
  ...CAPABILITY_REGISTRY,
  ...PHARMACY_CAPABILITY_REGISTRY,
} as const;

async function call<T>(
  channel: PharmacyRegisteredChannelName,
  payload: unknown = {},
): Promise<BridgeResult<T>> {
  if (!withinSizeBound(payload, MAX_IPC_PAYLOAD_BYTES)) {
    return {
      ok: false,
      error: { code: 'PAYLOAD_TOO_LARGE', message: 'Request exceeded the maximum payload size.' },
    };
  }

  const contract = PHARMACY_BRIDGE_REGISTRY[channel];
  const outbound = contract.request.safeParse(payload);

  if (!outbound.success) {
    return {
      ok: false,
      error: { code: 'INVALID_REQUEST', message: 'Request did not match the expected shape.' },
    };
  }

  try {
    const result: unknown = await ipcRenderer.invoke(channel, outbound.data);

    if (typeof result !== 'object' || result === null || !('ok' in result)) {
      return { ok: false, error: { code: 'INTERNAL_ERROR', message: 'Malformed capability response.' } };
    }

    return result as BridgeResult<T>;
  } catch {
    return {
      ok: false,
      error: { code: 'CAPABILITY_NOT_AVAILABLE', message: 'The capability is not available.' },
    };
  }
}

const bridge: PharmacyClinicBridge = {
  contractVersion: BRIDGE_CONTRACT_VERSION,
  app: {
    metadata: () => call(CHANNELS.appMetadata),
  },
  platform: {
    health: () => call(CHANNELS.platformHealth),
    version: () => call(CHANNELS.platformVersion),
  },
  locale: {
    get: () => call(CHANNELS.localeGet),
    set: (locale) => call(CHANNELS.localeSet, { locale }),
  },
  auth: {
    secureStatus: () => call(CHANNELS.authSecureStatus),
    login: (input) => call(CHANNELS.authLogin, input),
    verifyMfa: (input) => call(CHANNELS.authVerifyMfa, input),
    logout: () => call(CHANNELS.authLogout),
    me: () => call(CHANNELS.authMe),
    sessions: () => call(CHANNELS.authSessions),
    revokeSession: (sessionId) => call(CHANNELS.authRevokeSession, { sessionId }),
  },
  pharmacy: {
    getOwnOrganization: () => call(PHARMACY_CHANNELS.organizationGetOwn),
    onboard: (input) => call(PHARMACY_CHANNELS.organizationOnboard, input),
    openVerificationCase: () => call(PHARMACY_CHANNELS.verificationOpenCase),
    verificationStatus: () => call(PHARMACY_CHANNELS.verificationStatus),
    submitVerification: (input) => call(PHARMACY_CHANNELS.verificationSubmit, input),
    selectEvidence: () => call(PHARMACY_CHANNELS.evidenceSelect),
    clearEvidence: (handleId) => call(PHARMACY_CHANNELS.evidenceClear, { handleId }),
    uploadEvidence: (input) => call(PHARMACY_CHANNELS.evidenceUpload, input),
    uploadStatus: (uploadId) => call(PHARMACY_CHANNELS.uploadStatus, { uploadId }),
    listBranches: (input) => call(PHARMACY_CHANNELS.branchesList, input),
    createBranch: (input) => call(PHARMACY_CHANNELS.branchCreate, input),
    getBranch: (input) => call(PHARMACY_CHANNELS.branchGet, input),
    updateBranch: (input) => call(PHARMACY_CHANNELS.branchUpdate, input),
    inviteOperator: (input) => call(PHARMACY_CHANNELS.branchInviteOperator, input),
    listMemberships: (input) => call(PHARMACY_CHANNELS.branchMemberships, input),
    revokeMembership: (input) => call(PHARMACY_CHANNELS.branchRevokeMembership, input),
  },
};

contextBridge.exposeInMainWorld('clinic', bridge);
