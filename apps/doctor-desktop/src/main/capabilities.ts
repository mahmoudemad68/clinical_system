import { app, BrowserWindow, ipcMain, type IpcMainInvokeEvent } from 'electron';
import {
  ALL_CHANNELS,
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  withinSizeBound,
  type BridgeResult,
  type ChannelName,
} from '@clinic/desktop-bridge-contracts';
import { APP_CONFIG } from '../shared/app-config';
import { isTrustedFrameOrigin } from '../shared/sender-policy';
import { platformGateway, SecureStorageUnavailableError } from './platform-gateway';

/**
 * The privileged side of the IPC boundary.
 *
 * Every handler is registered from the shared registry, so a channel cannot
 * exist without a schema. Registering by hand and forgetting the validation is
 * structurally impossible.
 *
 * Validation happens here even though the preload also validates. The preload
 * runs in the renderer's process: a compromised renderer can call it with
 * anything, or bypass it entirely. Only this side is a real control.
 */

let localeState: 'ar' | 'en' = 'en';

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
  handle(CHANNELS.authLogout, async () => platformGateway.logout(localeState));
  handle(CHANNELS.authMe, async () => platformGateway.me(localeState));
  handle(CHANNELS.authSessions, async () => platformGateway.sessions(localeState));
  handle(CHANNELS.authRevokeSession, async (payload: { sessionId: string }) =>
    platformGateway.revokeSession(localeState, payload.sessionId),
  );
}

/**
 * Register one validated handler.
 *
 * Order matters and is not arbitrary:
 *   1. sender check   — reject a frame that should not be calling at all
 *   2. size check     — before parsing, because parsing is the DoS
 *   3. schema check   — reject a malformed or unexpected shape
 *   4. execute        — under a deadline
 *   5. response check — a handler bug must not leak an unexpected shape
 */
function handle<T>(
  channel: ChannelName,
  execute: (payload: never) => Promise<T>,
): void {
  const contract = CAPABILITY_REGISTRY[channel];

  ipcMain.handle(channel, async (event: IpcMainInvokeEvent, rawPayload: unknown): Promise<BridgeResult<unknown>> => {
    // 1. Sender validation. Only the app's own top-level frame may invoke.
    if (!isTrustedSender(event)) {
      return fail('PERMISSION_DENIED', 'Caller is not permitted to use this capability.');
    }

    // 2. Size before parse.
    if (!withinSizeBound(rawPayload, MAX_IPC_PAYLOAD_BYTES)) {
      return fail('PAYLOAD_TOO_LARGE', 'The request exceeded the maximum IPC payload size.');
    }

    // 3. Schema.
    const parsed = contract.request.safeParse(rawPayload ?? {});
    if (!parsed.success) {
      // The Zod issue list is not returned: it echoes the input, and the input
      // came from a process we treat as hostile.
      return fail('INVALID_REQUEST', 'The request did not match the expected shape.');
    }

    try {
      // 4. Deadline. A capability that hangs blocks the UI awaiting it.
      const value = await withTimeout(execute(parsed.data as never), contract.timeoutMs);

      // 5. Response validation. A handler returning an unexpected shape is a
      //    bug here, not there, and must not cross the boundary.
      const validated = contract.response.safeParse(value);
      if (!validated.success) {
        return fail('INTERNAL_ERROR', 'The capability produced an unexpected result.');
      }

      return { ok: true, value: validated.data };
    } catch (error) {
      if (error instanceof TimeoutError) {
        return fail('TIMEOUT', 'The operation took too long.');
      }

      if (error instanceof SecureStorageUnavailableError) {
        return fail('CAPABILITY_NOT_AVAILABLE', error.message);
      }

      if (error instanceof Error && error.message === 'UNAUTHENTICATED') {
        return fail('UNAUTHENTICATED', 'The session is no longer valid.');
      }

      // Never serialize the thrown error: main-process stacks name filesystem
      // paths and adapter internals.
      return fail('UPSTREAM_FAILED', 'The operation could not be completed.');
    }
  });
}

/**
 * Windows permitted to invoke a capability.
 *
 * Registered by the main process when it creates a window. Validating against
 * this set is the check Electron's security guidance actually asks for:
 * comparing a URL is not enough, because a URL says nothing about which
 * WebContents sent the message.
 */
const trustedWebContentsIds = new Set<number>();

export function trustWindow(window: BrowserWindow): void {
  const id = window.webContents.id;
  trustedWebContentsIds.add(id);
  window.on('closed', () => trustedWebContentsIds.delete(id));
}

/**
 * Only this application's own top-level window may invoke a capability.
 *
 * Three independent checks, because each catches something the others miss:
 *
 *   1. the sender is a WebContents we created and still own — a URL comparison
 *      alone cannot establish this;
 *   2. the sender frame is top-level — nothing here uses a subframe, so a call
 *      from one means something is embedding content that should not exist;
 *   3. the frame's origin is exactly the packaged origin.
 *
 * An earlier version compared only `url.protocol`, which admitted any host
 * under the scheme, and allowed localhost unconditionally with a comment
 * asserting it was "never reachable in a packaged build" — an assertion nothing
 * enforced. The localhost branch is now gated on `!app.isPackaged`, so it
 * cannot exist in a shipped artifact regardless of how the app is launched.
 */
function isTrustedSender(event: IpcMainInvokeEvent): boolean {
  // 1. Known, still-open window of ours.
  if (!trustedWebContentsIds.has(event.sender.id)) {
    return false;
  }

  const frame = event.senderFrame;

  // 2. Top-level frame only.
  if (frame === null || frame.parent !== null) {
    return false;
  }

  // 3. Exact origin, via the shared pure policy that the behavioural tests
  //    exercise directly with hostile inputs.
  return isTrustedFrameOrigin(frame.url, APP_CONFIG.packagedOrigin, app.isPackaged);
}

class TimeoutError extends Error {}

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => reject(new TimeoutError()), ms);

    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error: unknown) => {
        clearTimeout(timer);
        reject(error instanceof Error ? error : new Error('unknown'));
      },
    );
  });
}

function fail(code: Parameters<typeof buildError>[0], message: string): BridgeResult<never> {
  return { ok: false, error: buildError(code, message) };
}

function buildError(
  code:
    | 'INVALID_REQUEST'
    | 'PAYLOAD_TOO_LARGE'
    | 'UNSUPPORTED_CONTRACT_VERSION'
    | 'CAPABILITY_NOT_AVAILABLE'
    | 'UNAUTHENTICATED'
    | 'PERMISSION_DENIED'
    | 'TIMEOUT'
    | 'CANCELLED'
    | 'UPSTREAM_FAILED'
    | 'INTERNAL_ERROR',
  message: string,
) {
  return { code, message };
}

/** Exported for the test that asserts no channel exists outside the registry. */
export const REGISTERED_CHANNELS = ALL_CHANNELS;
