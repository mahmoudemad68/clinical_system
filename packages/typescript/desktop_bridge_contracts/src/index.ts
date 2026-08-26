/**
 * IPC contracts for the Electron desktop trust boundary.
 *
 * This package is the boundary. It is imported by three places with three very
 * different privilege levels — the renderer (for types only), the preload (to
 * validate outbound), and the main process (to validate inbound) — so it must
 * contain **schemas and types only**: no Electron import, no Node import, no
 * transport, no storage, no side effect. Anything else here would be reachable
 * from the renderer's dependency graph.
 *
 * ADR 0010 requires every IPC request and response to have a schema, a maximum
 * size, a caller check, a stable safe error, and deadline/cancellation
 * behaviour. The first four live here; the caller check and deadline are
 * enforced by the main-process handler, which cannot be expressed in a schema.
 *
 * **Validate at both ends.** The preload validating outbound is a developer
 * convenience. The main process validating inbound is the actual control: a
 * compromised renderer can call the preload with anything, and a preload
 * running in the renderer's process cannot be trusted to have validated it.
 */

import { z } from 'zod';

/**
 * Contract version. Bumped when a channel's shape changes incompatibly.
 *
 * The main process rejects a mismatched version rather than guessing, which
 * matters because a packaged desktop app and its update can be different
 * versions on the same machine during a partial upgrade.
 */
export const BRIDGE_CONTRACT_VERSION = 1 as const;

/**
 * Hard ceiling on any single IPC payload, in bytes.
 *
 * IPC messages are structured-cloned across a process boundary; an unbounded
 * one is a memory-exhaustion vector reachable from a compromised renderer, and
 * the renderer is exactly the process most likely to be compromised.
 */
export const MAX_IPC_PAYLOAD_BYTES = 256 * 1024;

/** Default deadline. A capability that can hang blocks the UI thread that awaits it. */
export const DEFAULT_IPC_TIMEOUT_MS = 15_000;

// ---------------------------------------------------------------- primitives

export const uuidV7Schema = z
  .string()
  .regex(
    /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'must be a lowercase UUIDv7',
  );

export const localeSchema = z.enum(['ar', 'en']);

export const componentStatusSchema = z.enum(['operational', 'degraded', 'unavailable']);

/**
 * Safe error crossing the boundary.
 *
 * Carries a stable code and a safe message only. A raw Error would serialize
 * its stack and message straight into the renderer, where an XSS payload could
 * read it — and main-process stacks name filesystem paths and adapter
 * internals.
 */
export const bridgeErrorSchema = z.object({
  code: z.enum([
    'INVALID_REQUEST',
    'PAYLOAD_TOO_LARGE',
    'UNSUPPORTED_CONTRACT_VERSION',
    'CAPABILITY_NOT_AVAILABLE',
    'UNAUTHENTICATED',
    'PERMISSION_DENIED',
    'TIMEOUT',
    'CANCELLED',
    'UPSTREAM_FAILED',
    'INTERNAL_ERROR',
  ]),
  message: z.string().max(500),
  requestId: uuidV7Schema.optional(),
});

export type BridgeError = z.infer<typeof bridgeErrorSchema>;

/** Every capability answers with this discriminated union. */
export const bridgeResultSchema = <T extends z.ZodTypeAny>(value: T) =>
  z.discriminatedUnion('ok', [
    z.object({ ok: z.literal(true), value }),
    z.object({ ok: z.literal(false), error: bridgeErrorSchema }),
  ]);

export type BridgeResult<T> = { ok: true; value: T } | { ok: false; error: BridgeError };

// -------------------------------------------------------------- capabilities

/**
 * Channel names.
 *
 * A closed registry, not a string parameter. The renderer can never name a
 * channel: it calls `bridge.platform.health()`, and the preload maps that to a
 * constant. A generic `invoke(channel, payload)` would hand a compromised
 * renderer the entire main-process surface, which is the single most common
 * Electron vulnerability.
 */
export const CHANNELS = {
  platformHealth: 'clinic:platform.health',
  platformVersion: 'clinic:platform.version',
  appMetadata: 'clinic:app.metadata',
  localeGet: 'clinic:locale.get',
  localeSet: 'clinic:locale.set',
  authSecureStatus: 'clinic:auth.secureStatus',
  authLogin: 'clinic:auth.login',
  authVerifyMfa: 'clinic:auth.verifyMfa',
  authLogout: 'clinic:auth.logout',
  authMe: 'clinic:auth.me',
  authSessions: 'clinic:auth.sessions',
  authRevokeSession: 'clinic:auth.revokeSession',
} as const;

export type ChannelName = (typeof CHANNELS)[keyof typeof CHANNELS];

export const ALL_CHANNELS: readonly ChannelName[] = Object.values(CHANNELS);

/** Phase 00 capabilities. Each is one intent, not a generic passthrough. */

export const emptyRequestSchema = z.object({}).strict();

export const platformHealthResponseSchema = z.object({
  status: componentStatusSchema,
  message: z.string().max(300),
  components: z.object({
    core: componentStatusSchema,
    realtime: componentStatusSchema,
    ai: componentStatusSchema,
  }),
  version: z.string().max(64),
  serverTime: z.string().datetime({ offset: true }),
});

export type PlatformHealth = z.infer<typeof platformHealthResponseSchema>;

export const platformVersionResponseSchema = z.object({
  service: z.string().max(64),
  version: z.string().max(64),
  apiVersion: z.literal('v1'),
  environment: z.enum(['local', 'development', 'staging', 'production']),
});

export type PlatformVersion = z.infer<typeof platformVersionResponseSchema>;

/**
 * Identity of the running desktop application.
 *
 * Exposed so the renderer can render its own name and confirm which app it is.
 * Deliberately excludes user-data paths, protocol schemes, and update URLs: the
 * renderer has no use for them and they are reconnaissance for an attacker who
 * has achieved script execution.
 */
export const appMetadataResponseSchema = z.object({
  appId: z.enum(['eg.clinic.doctor.desktop', 'eg.clinic.pharmacy.desktop']),
  productName: z.string().max(64),
  appVersion: z.string().max(64),
  contractVersion: z.literal(BRIDGE_CONTRACT_VERSION),
});

export type AppMetadata = z.infer<typeof appMetadataResponseSchema>;

export const localeSetRequestSchema = z.object({ locale: localeSchema }).strict();
export const localeResponseSchema = z.object({ locale: localeSchema });

export const authSecureStatusResponseSchema = z
  .object({
    available: z.boolean(),
    backend: z.string().max(64),
  })
  .strict();

export type AuthSecureStatus = z.infer<typeof authSecureStatusResponseSchema>;

export const authLoginRequestSchema = z
  .object({
    phone: z.string().min(8).max(32),
    password: z.string().min(1).max(128),
    deviceLabel: z.string().min(1).max(120),
  })
  .strict();

export const authSessionViewSchema = z
  .object({
    status: z.string().max(32),
    mfaRequired: z.boolean(),
    challengeId: z.string().uuid().optional(),
    sessionKind: z.enum(['device', 'admin_cookie']).optional(),
    userId: z.string().uuid().optional(),
    accountType: z.string().max(16).optional(),
  })
  .strict();

export type AuthSessionView = z.infer<typeof authSessionViewSchema>;

export const authVerifyMfaRequestSchema = z
  .object({
    challengeId: z.string().uuid(),
    code: z.string().regex(/^\d{6}$/),
  })
  .strict();

export const authLogoutResponseSchema = z.object({ revoked: z.literal(true) }).strict();

export const authMeResponseSchema = z
  .object({
    userId: z.string().uuid(),
    accountType: z.string().max(16),
    status: z.string().max(32),
    language: localeSchema,
    assuranceLevel: z.string().max(32),
    capabilities: z.array(z.string().max(120)).max(32),
  })
  .strict();

export type AuthMe = z.infer<typeof authMeResponseSchema>;

export const authSessionSummarySchema = z
  .object({
    sessionId: z.string().uuid(),
    sessionKind: z.enum(['device', 'admin_cookie']),
    assuranceLevel: z.string().max(32),
    lastSeenAt: z.string().max(64).optional(),
    createdAt: z.string().max(64).optional(),
  })
  .strict();

export const authSessionsResponseSchema = z
  .object({
    sessions: z.array(authSessionSummarySchema).max(50),
  })
  .strict();

export const authRevokeSessionRequestSchema = z
  .object({
    sessionId: z.string().uuid(),
  })
  .strict();

/**
 * The registry the main process iterates to register handlers.
 *
 * Driving registration from data means a channel cannot exist without a schema:
 * adding a handler by hand and forgetting its validation is not possible,
 * because the handler is only reachable through this table.
 */
export const CAPABILITY_REGISTRY = {
  [CHANNELS.platformHealth]: {
    request: emptyRequestSchema,
    response: platformHealthResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.platformVersion]: {
    request: emptyRequestSchema,
    response: platformVersionResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.appMetadata]: {
    request: emptyRequestSchema,
    response: appMetadataResponseSchema,
    timeoutMs: 1_000,
  },
  [CHANNELS.localeGet]: {
    request: emptyRequestSchema,
    response: localeResponseSchema,
    timeoutMs: 1_000,
  },
  [CHANNELS.localeSet]: {
    request: localeSetRequestSchema,
    response: localeResponseSchema,
    timeoutMs: 1_000,
  },
  [CHANNELS.authSecureStatus]: {
    request: emptyRequestSchema,
    response: authSecureStatusResponseSchema,
    timeoutMs: 1_000,
  },
  [CHANNELS.authLogin]: {
    request: authLoginRequestSchema,
    response: authSessionViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.authVerifyMfa]: {
    request: authVerifyMfaRequestSchema,
    response: authSessionViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.authLogout]: {
    request: emptyRequestSchema,
    response: authLogoutResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.authMe]: {
    request: emptyRequestSchema,
    response: authMeResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.authSessions]: {
    request: emptyRequestSchema,
    response: authSessionsResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [CHANNELS.authRevokeSession]: {
    request: authRevokeSessionRequestSchema,
    response: authLogoutResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
} as const satisfies Record<
  ChannelName,
  { request: z.ZodTypeAny; response: z.ZodTypeAny; timeoutMs: number }
>;

/**
 * The typed surface `contextBridge` exposes on `window.clinic`.
 *
 * One method per capability, each returning a discriminated result. There is no
 * `invoke`, no `send`, no channel string, and no way to reach anything not
 * listed here.
 */
export interface ClinicBridge {
  readonly contractVersion: typeof BRIDGE_CONTRACT_VERSION;
  readonly app: {
    metadata(): Promise<BridgeResult<AppMetadata>>;
  };
  readonly platform: {
    health(): Promise<BridgeResult<PlatformHealth>>;
    version(): Promise<BridgeResult<PlatformVersion>>;
  };
  readonly locale: {
    get(): Promise<BridgeResult<{ locale: 'ar' | 'en' }>>;
    set(locale: 'ar' | 'en'): Promise<BridgeResult<{ locale: 'ar' | 'en' }>>;
  };
  readonly auth: {
    secureStatus(): Promise<BridgeResult<AuthSecureStatus>>;
    login(input: {
      phone: string;
      password: string;
      deviceLabel: string;
    }): Promise<BridgeResult<AuthSessionView>>;
    verifyMfa(input: { challengeId: string; code: string }): Promise<BridgeResult<AuthSessionView>>;
    logout(): Promise<BridgeResult<{ revoked: true }>>;
    me(): Promise<BridgeResult<AuthMe>>;
    sessions(): Promise<BridgeResult<{ sessions: Array<z.infer<typeof authSessionSummarySchema>> }>>;
    revokeSession(sessionId: string): Promise<BridgeResult<{ revoked: true }>>;
  };
}

/**
 * Reject an oversized payload before parsing it.
 *
 * Size is checked first, on purpose: `JSON.stringify` on a hostile structure is
 * itself the denial of service, so the guard has to run before any schema does.
 */
export function withinSizeBound(payload: unknown, maxBytes = MAX_IPC_PAYLOAD_BYTES): boolean {
  try {
    const serialized = JSON.stringify(payload ?? null);

    if (typeof serialized !== 'string') {
      return false;
    }

    // TextEncoder, not Buffer.byteLength: this package is on the renderer's
    // dependency graph, and a Node API here would be exactly the coupling the
    // trust boundary exists to prevent. TextEncoder is universal.
    return new TextEncoder().encode(serialized).length <= maxBytes;
  } catch {
    // Cyclic or non-serializable: it cannot cross a structured-clone boundary
    // anyway, so refuse it here with a clear reason.
    return false;
  }
}

export function bridgeFailure(code: BridgeError['code'], message: string): BridgeResult<never> {
  return { ok: false, error: { code, message } };
}
