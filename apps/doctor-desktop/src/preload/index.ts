import { contextBridge, ipcRenderer } from 'electron';
import {
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  DOCTOR_CAPABILITY_REGISTRY,
  DOCTOR_CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  withinSizeBound,
  type BridgeResult,
  type DoctorClinicBridge,
  type DoctorRegisteredChannelName,
} from '@clinic/desktop-bridge-contracts';

/**
 * The bridge. Deliberately tiny.
 *
 * Doctor domain methods are listed explicitly. There is no generic invoke,
 * no channel string parameter, and no way to name a Pharmacy-only or unknown
 * channel from this renderer.
 */

const DOCTOR_BRIDGE_REGISTRY = {
  ...CAPABILITY_REGISTRY,
  ...DOCTOR_CAPABILITY_REGISTRY,
} as const;

async function call<T>(
  channel: DoctorRegisteredChannelName,
  payload: unknown = {},
): Promise<BridgeResult<T>> {
  if (!withinSizeBound(payload, MAX_IPC_PAYLOAD_BYTES)) {
    return {
      ok: false,
      error: { code: 'PAYLOAD_TOO_LARGE', message: 'Request exceeded the maximum payload size.' },
    };
  }

  const contract = DOCTOR_BRIDGE_REGISTRY[channel];
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

const bridge: DoctorClinicBridge = {
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
  doctor: {
    getOwnProfile: () => call(DOCTOR_CHANNELS.profileGetOwn),
    listSpecialties: () => call(DOCTOR_CHANNELS.specialtiesList),
    onboard: (input) => call(DOCTOR_CHANNELS.profileOnboard, input),
    openVerificationCase: () => call(DOCTOR_CHANNELS.verificationOpenCase),
    verificationStatus: () => call(DOCTOR_CHANNELS.verificationStatus),
    submitVerification: (input) => call(DOCTOR_CHANNELS.verificationSubmit, input),
    selectEvidence: () => call(DOCTOR_CHANNELS.evidenceSelect),
    clearEvidence: (handleId) => call(DOCTOR_CHANNELS.evidenceClear, { handleId }),
    uploadEvidence: (input) => call(DOCTOR_CHANNELS.evidenceUpload, input),
    uploadStatus: (uploadId) => call(DOCTOR_CHANNELS.uploadStatus, { uploadId }),
  },
};

contextBridge.exposeInMainWorld('clinic', bridge);
