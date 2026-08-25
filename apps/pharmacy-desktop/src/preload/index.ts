import { contextBridge, ipcRenderer } from 'electron';
import {
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  withinSizeBound,
  type BridgeResult,
  type ChannelName,
  type ClinicBridge,
} from '@clinic/desktop-bridge-contracts';

/**
 * The bridge. Deliberately tiny.
 *
 * This file runs with Node access in an isolated world alongside a renderer we
 * treat as hostile. Every line added here widens the attack surface, so it does
 * exactly two things: map an intent-named method to a constant channel, and
 * shape-check what comes back.
 *
 * What is never exposed, and why:
 *
 *   ipcRenderer      would let the renderer name any channel, reaching every
 *                    main-process handler. This is the single most common
 *                    Electron vulnerability.
 *   a generic invoke same problem wearing a different hat.
 *   Node globals     process, require, Buffer, __dirname all give a compromised
 *                    renderer a path to the filesystem.
 *   raw URLs/paths   the renderer must not choose what main talks to.
 *
 * The renderer calls `window.clinic.platform.health()`. It cannot express
 * anything else.
 */

async function call<T>(channel: ChannelName, payload: unknown = {}): Promise<BridgeResult<T>> {
  // Bound outbound size here too. This is a convenience, not the control — the
  // main process checks again, because a compromised renderer can bypass this
  // whole file by talking to the isolated world directly.
  if (!withinSizeBound(payload, MAX_IPC_PAYLOAD_BYTES)) {
    return {
      ok: false,
      error: { code: 'PAYLOAD_TOO_LARGE', message: 'Request exceeded the maximum payload size.' },
    };
  }

  const contract = CAPABILITY_REGISTRY[channel];
  const outbound = contract.request.safeParse(payload);

  if (!outbound.success) {
    return {
      ok: false,
      error: { code: 'INVALID_REQUEST', message: 'Request did not match the expected shape.' },
    };
  }

  try {
    const result: unknown = await ipcRenderer.invoke(channel, outbound.data);

    // Shape-check the response before handing it to renderer code, so a main
    // process bug cannot put an unexpected object into the React tree.
    if (typeof result !== 'object' || result === null || !('ok' in result)) {
      return { ok: false, error: { code: 'INTERNAL_ERROR', message: 'Malformed capability response.' } };
    }

    return result as BridgeResult<T>;
  } catch {
    // An IPC rejection means no handler, or main threw before the handler
    // could answer. Either way the renderer learns nothing about why.
    return {
      ok: false,
      error: { code: 'CAPABILITY_NOT_AVAILABLE', message: 'The capability is not available.' },
    };
  }
}

const bridge: ClinicBridge = {
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
};

// contextBridge, not window assignment: the object is deep-frozen and copied
// across the isolated-world boundary, so renderer code cannot monkey-patch a
// method and cannot reach this file's scope.
contextBridge.exposeInMainWorld('clinic', bridge);
