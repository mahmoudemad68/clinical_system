import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  ALL_CHANNELS,
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  authSessionViewSchema,
  localeSetRequestSchema,
  platformHealthResponseSchema,
  withinSizeBound,
} from '@clinic/desktop-bridge-contracts';

import { APP_CONFIG } from './app-config';

const appRoot = join(__dirname, '..', '..');
const read = (relative: string): string => readFileSync(join(appRoot, relative), 'utf8');

/**
 * Source with comments removed.
 *
 * Needed because a comment warning *against* a dangerous flag would otherwise
 * satisfy a naive `toContain` check — and did, on the first run of this suite.
 * A security assertion that a comment can pass is not an assertion.
 */
const readCode = (relative: string): string =>
  read(relative)
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

/**
 * Trust-boundary regression tests for Clinic Pharmacy.
 *
 * Phase 00 "Security tests" requires proving that a renderer cannot reach Node
 * or Electron, that arbitrary IPC is denied, and that CSP, permissions, and
 * fuses hold. The packaged-window half of that needs WebdriverIO against a real
 * installed artifact and is gate G-02-09.
 *
 * These are the half that can run on every commit without packaging, and they
 * are the half that catches the mistake a person actually makes: adding an
 * import, relaxing a webPreference, or exposing one more thing from preload.
 */
describe('Clinic Pharmacy — renderer isolation', () => {
  it('renderer source imports no Node or Electron module', () => {
    // Ambient Window.clinic types live in clinic-bridge.d.ts (tsconfig include).
    // A runtime import of that file is not a module webpack can resolve.
    const renderer = read('src/renderer/index.tsx');

    for (const forbidden of [
      "from 'electron'",
      'require(',
      "from 'node:",
      "from 'fs'",
      "from 'path'",
      "from 'child_process'",
      '__dirname',
      'process.env',
    ]) {
      expect(renderer).not.toContain(forbidden);
    }
  });

  it('renderer never performs its own network or storage access', () => {
    const renderer = read('src/renderer/index.tsx');

    // Every byte in and out goes through window.clinic. A fetch here would be
    // an unauthenticated request outside the main-process transport, and a
    // localStorage write would put data outside the encrypted boundary.
    for (const forbidden of ['fetch(', 'XMLHttpRequest', 'localStorage', 'sessionStorage', 'indexedDB', 'new WebSocket', 'access_token', 'refresh_token']) {
      expect(renderer).not.toContain(forbidden);
    }
  });

  it('keeps the encrypted store and native sqlite out of the renderer', () => {
    const renderer = read('src/renderer/index.tsx');
    const preload = read('src/preload/index.ts');

    for (const source of [renderer, preload]) {
      expect(source).not.toContain('encrypted-local-store');
      expect(source).not.toContain('better-sqlite3');
      expect(source).not.toContain('EncryptedSqliteStore');
    }
  });

  it('preload exposes no raw ipcRenderer and no generic invoke', () => {
    const preload = read('src/preload/index.ts');

    // Exposing ipcRenderer, or a generic invoke(channel, payload), hands a
    // compromised renderer the whole main-process surface. This is the single
    // most common Electron vulnerability.
    expect(preload).toContain('contextBridge.exposeInMainWorld');
    expect(preload).not.toMatch(/exposeInMainWorld\([^)]*ipcRenderer/);
    expect(preload).not.toMatch(/invoke\s*:\s*\(\s*channel/);
  });
});

describe('Clinic Pharmacy — local encryption', () => {
  it('fail-closes Linux basic_text in the main process before opening a store', () => {
    const probe = read('src/main/local-encryption.ts');
    const main = read('src/main/index.ts');

    expect(probe).toContain('assessOsKeystore');
    expect(probe).toContain('getSelectedStorageBackend');
    expect(main).toContain('assessLocalEncryption');
    expect(main).toContain('localEncryption.allowed');
  });

  it('does not expose SQL or draft storage through IPC', () => {
    const capabilities = read('src/main/capabilities.ts');
    expect(capabilities).not.toContain('EncryptedSqliteStore');
    expect(capabilities).not.toContain('better-sqlite3');
    expect(capabilities).not.toMatch(/ipcMain\.handle\(\s*['"`]/);
  });
});

describe('Clinic Pharmacy — window security configuration', () => {
  const main = read('src/main/index.ts');

  it('fixes the three non-negotiable webPreferences', () => {
    expect(main).toContain('nodeIntegration: false');
    expect(main).toContain('contextIsolation: true');
    expect(main).toContain('sandbox: true');
    expect(main).toContain('webSecurity: true');
    expect(main).toContain('webviewTag: false');
  });

  it('never disables the Chromium sandbox', () => {
    // Checked against code with comments stripped: the source deliberately
    // *mentions* --no-sandbox in a warning comment, and an earlier version of
    // this test failed on its own documentation.
    const code = readCode('src/main/index.ts');

    expect(code).not.toContain('sandbox: false');
    expect(code).not.toContain('--no-sandbox');
    expect(code).not.toMatch(/appendSwitch\(\s*['"`]no-sandbox/);
    expect(code).not.toMatch(/disableHardwareAcceleration|allowRendererProcessReuse:\s*false/);
  });

  it('configures the Linux SUID sandbox helper instead of disabling Chromium sandboxing', () => {
    const pkg = read('package.json');
    const helper = readCode('../../scripts/desktop/ensure-linux-chromium-sandbox.mjs');

    expect(pkg).toContain('ensure-linux-chromium-sandbox.mjs');
    expect(pkg).not.toContain('--no-sandbox');
    expect(helper).not.toContain('--no-sandbox');
    expect(helper).not.toContain('no-sandbox');
  });

  it('denies navigation, child windows, permissions, and downloads', () => {
    expect(main).toContain("contents.on('will-navigate'");
    expect(main).toContain('setWindowOpenHandler');
    expect(main).toContain('setPermissionRequestHandler');
    expect(main).toContain('setPermissionCheckHandler');
    expect(main).toContain("on('will-download'");
  });

  it('serves renderer assets from a privileged custom scheme, not file://', () => {
    // main references the scheme through APP_CONFIG rather than a literal, so
    // assert the wiring here and the value on the config it reads.
    expect(main).toContain('registerSchemesAsPrivileged');
    expect(main).toContain('APP_CONFIG.assetProtocolScheme');
    expect(main).toContain('loadURL');
    expect(main).not.toContain('loadFile(');

    // The literal itself is owned by app-config and must be app-specific.
    expect(APP_CONFIG.assetProtocolScheme).toBe('clinic-pharmacy-app');
    expect(read('src/shared/app-config.ts')).toContain("'clinic-pharmacy-app'");
  });

  it('contains asset path traversal', () => {
    // Without the containment check a crafted ../.. path reads arbitrary files
    // with the application's privileges.
    expect(main).toContain('path.relative');
    expect(main).toContain("startsWith('..')");
  });

  it('declares a CSP that forbids remote script and any renderer connection', () => {
    expect(main).toContain("default-src 'none'");
    expect(main).toContain("connect-src 'none'");
    expect(main).toContain("frame-ancestors 'none'");
    expect(main).not.toContain("script-src 'unsafe-inline'");
  });
});

describe('Clinic Pharmacy — Electron fuses', () => {
  const forge = read('forge.config.ts');

  it('disables the fuses that permit arbitrary code execution in a signed binary', () => {
    // RunAsNode turns the signed application into a general-purpose Node
    // interpreter; NODE_OPTIONS and --inspect inject into an installed app.
    expect(forge).toContain('[FuseV1Options.RunAsNode]: false');
    expect(forge).toContain('[FuseV1Options.EnableNodeOptionsEnvironmentVariable]: false');
    expect(forge).toContain('[FuseV1Options.EnableNodeCliInspectArguments]: false');
  });

  it('enforces packaged-code integrity', () => {
    expect(forge).toContain('[FuseV1Options.EnableEmbeddedAsarIntegrityValidation]: true');
    expect(forge).toContain('[FuseV1Options.OnlyLoadAppFromAsar]: true');
    expect(forge).toContain('asar: true');
  });

  it('carries no signing credentials in the Phase 00 configuration', () => {
    // Signing is Phase 23 and owned by production/DR. A fork pull request must
    // never be able to reach a certificate.
    // Absent, not set to undefined: `exactOptionalPropertyTypes` rejects an
    // explicit undefined, and omission states the intent more plainly.
    expect(forge).not.toMatch(/osxSign\s*:/);
    expect(forge).not.toMatch(/osxNotarize\s*:/);
    expect(forge).not.toMatch(/CSC_LINK|CERTIFICATE|APPLE_ID|signingIdentity/i);
  });
});

describe('Clinic Pharmacy — IPC contract', () => {
  it('every registered channel has a schema, a response schema, and a timeout', () => {
    // Driving registration from the registry makes a schema-less channel
    // structurally impossible.
    for (const channel of ALL_CHANNELS) {
      const contract = CAPABILITY_REGISTRY[channel];

      expect(contract.request).toBeDefined();
      expect(contract.response).toBeDefined();
      expect(contract.timeoutMs).toBeGreaterThan(0);
    }
  });

  it('the main process registers handlers only from the shared registry', () => {
    const capabilities = read('src/main/capabilities.ts');

    // A hand-rolled ipcMain.handle with a string literal would bypass
    // validation entirely.
    expect(capabilities).not.toMatch(/ipcMain\.handle\(\s*['"`]/);
    expect(capabilities).toContain('CAPABILITY_REGISTRY[channel]');
  });

  it('validates sender, size, request schema, and response schema', () => {
    const capabilities = read('src/main/capabilities.ts');

    expect(capabilities).toContain('isTrustedSender');
    expect(capabilities).toContain('withinSizeBound');
    expect(capabilities).toContain('contract.request.safeParse');
    expect(capabilities).toContain('contract.response.safeParse');
  });

  it('rejects a subframe sender', () => {
    const capabilities = read('src/main/capabilities.ts');

    // Nothing in this app uses a frame, so a call from one means something is
    // embedding content that should not exist.
    expect(capabilities).toContain('frame.parent !== null');
  });

  it('rejects an oversized payload before parsing it', () => {
    const huge = { blob: 'x'.repeat(MAX_IPC_PAYLOAD_BYTES + 1) };

    expect(withinSizeBound(huge)).toBe(false);
    expect(withinSizeBound({ locale: 'ar' })).toBe(true);
  });

  it('rejects a cyclic payload rather than hanging on it', () => {
    const cyclic: Record<string, unknown> = {};
    cyclic['self'] = cyclic;

    expect(withinSizeBound(cyclic)).toBe(false);
  });

  it('rejects an unexpected property on a strict request schema', () => {
    // additionalProperties equivalent. Silently dropping a field would let a
    // caller believe it took effect.
    expect(localeSetRequestSchema.safeParse({ locale: 'ar' }).success).toBe(true);
    expect(localeSetRequestSchema.safeParse({ locale: 'ar', admin: true }).success).toBe(false);
    expect(localeSetRequestSchema.safeParse({ locale: 'fr' }).success).toBe(false);
  });

  it('rejects a malformed capability response', () => {
    expect(
      platformHealthResponseSchema.safeParse({
        status: 'operational',
        message: 'ok',
        components: { core: 'operational', realtime: 'operational', ai: 'degraded' },
        version: '0.1.0',
        serverTime: '2026-08-25T10:00:00Z',
      }).success,
    ).toBe(true);

    expect(platformHealthResponseSchema.safeParse({ status: 'exploded' }).success).toBe(false);
  });

  it('exposes exactly the Phase 01 capability set and nothing more', () => {
    // A new channel is a new piece of attack surface and must be a deliberate,
    // reviewed change rather than something that appears quietly.
    expect([...ALL_CHANNELS].sort()).toEqual(
      [
        CHANNELS.appMetadata,
        CHANNELS.authLogin,
        CHANNELS.authLogout,
        CHANNELS.authMe,
        CHANNELS.authRevokeSession,
        CHANNELS.authSecureStatus,
        CHANNELS.authSessions,
        CHANNELS.authVerifyMfa,
        CHANNELS.localeGet,
        CHANNELS.localeSet,
        CHANNELS.platformHealth,
        CHANNELS.platformVersion,
      ].sort(),
    );
  });

  it('auth IPC responses never include tokens', () => {
    expect(
      authSessionViewSchema.safeParse({
        status: 'active',
        mfaRequired: false,
        access_token: 'not-allowed',
      }).success,
    ).toBe(false);
  });
});

describe('Clinic Pharmacy — application identity', () => {
  it('uses namespaces distinct from the sibling desktop application', () => {
    // Phase 00 §2.3: a shared pure TypeScript package must not collapse the two
    // applications' security contexts.
    expect(APP_CONFIG.appId).toBe('eg.clinic.pharmacy.desktop');
    expect(APP_CONFIG.userDataDirectory).toBe('clinic-pharmacy');
    expect(APP_CONFIG.protocolScheme).toBe('clinic-pharmacy');
    expect(APP_CONFIG.assetProtocolScheme).toBe('clinic-pharmacy-app');
    expect(APP_CONFIG.deviceCredentialNamespace).toBe('eg.clinic.pharmacy.device');
    expect(APP_CONFIG.encryptedDatabaseNamespace).toBe('pharmacy.encrypted.v1');
    expect(APP_CONFIG.updateChannel).toBe('pharmacy-stable');

    // Nothing may carry the sibling's identity.
    const serialized = JSON.stringify(APP_CONFIG);
    expect(serialized).not.toContain('doctor');
  });

  it('exposes only narrow identity to the renderer', () => {
    const contracts = read('../../packages/typescript/desktop_bridge_contracts/src/index.ts');

    // User-data paths, protocol schemes, and update URLs are reconnaissance for
    // anyone who achieves script execution, and the UI has no use for them.
    expect(contracts).toContain('appMetadataResponseSchema');
    expect(contracts).not.toMatch(/appMetadataResponseSchema[\s\S]{0,400}userDataDirectory/);
    expect(contracts).not.toMatch(/appMetadataResponseSchema[\s\S]{0,400}updateChannel/);
  });

  it('pins the bridge contract version', () => {
    expect(BRIDGE_CONTRACT_VERSION).toBe(1);
  });
});
