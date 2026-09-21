import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  ALL_CHANNELS,
  BRIDGE_CONTRACT_VERSION,
  CAPABILITY_REGISTRY,
  CHANNELS,
  DOCTOR_CHANNEL_LIST,
  MAX_IPC_PAYLOAD_BYTES,
  PHARMACY_ALL_CHANNELS,
  PHARMACY_CHANNEL_LIST,
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
    const rendererFiles = [
      'src/renderer/index.tsx',
      'src/renderer/App.tsx',
      'src/renderer/strings.ts',
      'src/renderer/theme.ts',
    ];

    for (const relative of rendererFiles) {
      const renderer = read(relative);
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
    }
  });

  it('renderer never performs its own network or storage access', () => {
    for (const relative of ['src/renderer/index.tsx', 'src/renderer/App.tsx']) {
      const renderer = read(relative);

      // Every byte in and out goes through window.clinic. A fetch here would be
      // an unauthenticated request outside the main-process transport, and a
      // localStorage write would put data outside the encrypted boundary.
      // Word-boundary so `refetch(` (TanStack Query) is not treated as `fetch(`.
      for (const forbidden of [
        'XMLHttpRequest',
        'localStorage',
        'sessionStorage',
        'indexedDB',
        'new WebSocket',
        'access_token',
        'refresh_token',
      ]) {
        expect(renderer).not.toContain(forbidden);
      }
      expect(renderer).not.toMatch(/(?<![A-Za-z])fetch\(/);
    }
  });

  it('keeps the encrypted store and native sqlite out of the renderer', () => {
    const renderer = read('src/renderer/App.tsx') + read('src/renderer/index.tsx');
    const preload = read('src/preload/index.ts');

    for (const source of [renderer, preload]) {
      expect(source).not.toContain('encrypted-local-store');
      expect(source).not.toContain('better-sqlite3');
      expect(source).not.toContain('EncryptedSqliteStore');
    }
  });

  it('does not run the native-module relocator against the renderer webpack graph', () => {
    // The relocator injects `__dirname`. In a `target: 'web'` renderer that is
    // an immediate ReferenceError and React never mounts.
    const rendererWebpack = read('webpack.renderer.config.ts');
    expect(rendererWebpack).toContain('rendererRules');
    expect(rendererWebpack).not.toContain('webpack-asset-relocator');
    expect(read('webpack.rules.ts')).toContain('export const rendererRules');
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

  it('clears device credentials fail-closed before dropping in-memory tokens', () => {
    const credentials = readCode('src/main/device-credentials.ts');
    const gateway = readCode('src/main/platform-gateway.ts');

    expect(credentials).toContain('unlinkSync(target)');
    expect(credentials).toContain("throw new Error('CAPABILITY_NOT_AVAILABLE')");
    expect(gateway.indexOf('clearDeviceTokens()')).toBeLessThan(gateway.indexOf('memoryAccess = null'));
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
    // with the application's privileges. The join/relative logic lives in
    // packaged-assets.ts so a leading-slash URL cannot discard the renderer root.
    const assets = read('src/main/packaged-assets.ts');
    expect(main).toContain('resolvePackagedAsset');
    expect(assets).toContain('path.relative');
    expect(assets).toContain("startsWith('..')");
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
  it('every registered shared channel has a schema, a response schema, and a timeout', () => {
    for (const channel of ALL_CHANNELS) {
      const contract = CAPABILITY_REGISTRY[channel];

      expect(contract.request).toBeDefined();
      expect(contract.response).toBeDefined();
      expect(contract.timeoutMs).toBeGreaterThan(0);
    }
  });

  it('the main process registers handlers only from the pharmacy app registry', () => {
    const capabilities = read('src/main/capabilities.ts');

    // A hand-rolled ipcMain.handle with a string literal would bypass
    // validation entirely. Shared Auth/Platform schemas are spread into the
    // Pharmacy registry; Doctor never imports this file.
    expect(capabilities).not.toMatch(/ipcMain\.handle\(\s*['"`]/);
    expect(capabilities).toContain('PHARMACY_REGISTRY[channel]');
    expect(capabilities).toContain('...CAPABILITY_REGISTRY');
    expect(capabilities).toContain('...PHARMACY_CAPABILITY_REGISTRY');
  });

  it('validates sender, size, request schema, and response schema', () => {
    const capabilities = read('src/main/capabilities.ts');

    expect(capabilities).toContain('isTrustedSender');
    expect(capabilities).toContain('withinSizeBound');
    expect(capabilities).toContain('contract.request.safeParse');
    expect(capabilities).toContain('contract.response.safeParse');
  });

  it('acknowledges delivery only after the response schema is accepted', () => {
    const delivery = readCode('src/main/ipc-delivery.ts');
    const capabilities = readCode('src/main/capabilities.ts');
    const deliverIdx = delivery.indexOf('accepted = deliver(value)');
    const ackIdx = delivery.indexOf('intents.acknowledge(ticket)');
    const abandonIdx = delivery.indexOf('intents.abandon(ticket)');
    const runIdx = capabilities.indexOf('runIpcDelivered');
    const invalidateIdx = capabilities.indexOf('evidenceHandles.invalidate');

    expect(deliverIdx).toBeGreaterThan(-1);
    expect(ackIdx).toBeGreaterThan(deliverIdx);
    expect(abandonIdx).toBeGreaterThan(-1);
    expect(abandonIdx).toBeLessThan(ackIdx);
    expect(capabilities).toContain('contract.response.safeParse');
    expect(capabilities).toContain('ResponseContractError');
    expect(runIdx).toBeGreaterThan(-1);
    expect(invalidateIdx).toBeGreaterThan(runIdx);
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

  it('exposes exactly the Phase 01 capability set on the shared ALL_CHANNELS list', () => {
    // Shared Auth/Platform channels stay on ALL_CHANNELS so Doctor remains
    // unchanged. Pharmacy domain channels are a separate registry.
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

  it('registers pharmacy domain channels only on the Pharmacy app registry', () => {
    const capabilities = read('src/main/capabilities.ts');
    const preload = read('src/preload/index.ts');

    expect(capabilities).toContain('REGISTERED_CHANNELS = PHARMACY_ALL_CHANNELS');
    expect(capabilities).toContain('runIpcDelivered');
    expect(capabilities).toContain('pharmacyIntentKeys');
    expect(capabilities).toContain('timeoutDeadline');
    expect(capabilities).toContain('ResponseContractError');
    expect(capabilities).toContain("fail('INTERNAL_ERROR'");
    expect(capabilities).toContain('PHARMACY_CHANNELS');
    expect(capabilities).toContain('PHARMACY_CAPABILITY_REGISTRY');
    expect(preload).toContain('pharmacy:');
    expect(preload).toContain('PHARMACY_CHANNELS');
    expect(preload).not.toMatch(/invoke\s*:\s*\(\s*channel/);
    expect(capabilities).not.toContain('error.stack');
    expect(readCode('src/main/pharmacy-gateway.ts')).not.toMatch(/console\.(log|info|debug|error|warn)/);
    expect(readCode('src/main/upload-target.ts')).not.toMatch(/console\.(log|info|debug|error|warn)/);
    expect(readCode('src/main/ipc-delivery.ts')).not.toMatch(/console\.(log|info|debug|error|warn)/);

    expect([...PHARMACY_ALL_CHANNELS]).toEqual(expect.arrayContaining([...ALL_CHANNELS]));
    for (const channel of PHARMACY_CHANNEL_LIST) {
      expect(ALL_CHANNELS).not.toContain(channel);
      expect(PHARMACY_ALL_CHANNELS).toContain(channel);
    }
  });

  it('does not register doctor domain channels on the Pharmacy bridge', () => {
    const capabilities = read('src/main/capabilities.ts');
    const preload = read('src/preload/index.ts');
    const renderer = read('src/renderer/App.tsx') + read('src/renderer/index.tsx');

    expect(capabilities).not.toContain('DOCTOR_CHANNELS');
    expect(capabilities).not.toContain('DOCTOR_CAPABILITY_REGISTRY');
    expect(capabilities).not.toContain('clinic:doctor.');
    expect(preload).not.toContain('clinic:doctor.');
    expect(preload).not.toContain('DOCTOR_CHANNELS');
    expect(renderer).not.toContain('window.clinic.doctor');
    expect(PHARMACY_ALL_CHANNELS).not.toEqual(expect.arrayContaining([...DOCTOR_CHANNEL_LIST]));

    for (const channel of DOCTOR_CHANNEL_LIST) {
      expect(ALL_CHANNELS).not.toContain(channel);
      expect(PHARMACY_ALL_CHANNELS).not.toContain(channel);
      expect(capabilities).not.toContain(channel);
      expect(preload).not.toContain(channel);
    }
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

describe('Clinic Pharmacy — packaged API origin trust boundary', () => {
  it('bakes the allowlist at compile time from a Pharmacy-only build env', () => {
    const allowlist = readCode('src/main/packaged-api-allowlist.ts');
    const webpackMain = readCode('webpack.main.config.ts');
    const gateway = readCode('src/main/platform-gateway.ts');
    const origin = readCode('src/main/api-origin.ts');

    expect(allowlist).toContain('__CLINIC_PACKAGED_API_ALLOWED_ORIGINS__');
    expect(allowlist).not.toContain('process.env');
    expect(allowlist).not.toContain('CLINIC_API_BASE_URL');
    expect(allowlist).not.toContain('CLINIC_API_ALLOWED_ORIGINS');

    expect(webpackMain).toContain('DefinePlugin');
    expect(webpackMain).toContain('CLINIC_PHARMACY_PACKAGED_API_ALLOWED_ORIGINS');
    expect(webpackMain).not.toContain('CLINIC_API_BASE_URL');
    expect(webpackMain).not.toContain('CLINIC_API_ALLOWED_ORIGINS');
    expect(webpackMain).not.toContain('CLINIC_DOCTOR_PACKAGED_API_ALLOWED_ORIGINS');

    expect(gateway).toContain('PACKAGED_API_ALLOWED_ORIGINS');
    expect(gateway).toContain("process.env['CLINIC_API_BASE_URL']");
    expect(gateway).not.toContain('CLINIC_PHARMACY_PACKAGED_API_ALLOWED_ORIGINS');
    expect(gateway).not.toContain('CLINIC_API_ALLOWED_ORIGINS');

    expect(origin).toContain('url.origin');
    expect(origin).not.toContain('endsWith(');
    expect(origin).not.toMatch(/hostname\s*\.\s*includes\(/);
  });

  it('clears the refresh Idempotency-Key only after durable token replacement', () => {
    const refresh = readCode('src/main/token-refresh.ts');
    const gateway = readCode('src/main/platform-gateway.ts');

    const persistAt = refresh.indexOf('input.persist(tokens)');
    const rememberAt = refresh.indexOf('input.remember(tokens)');
    const clearAfterPersist = refresh.indexOf('this.key = null', persistAt);

    expect(persistAt).toBeGreaterThan(-1);
    expect(rememberAt).toBeGreaterThan(persistAt);
    expect(clearAfterPersist).toBeGreaterThan(rememberAt);
    expect(gateway).toContain('tokenRefresh.run');
    expect(gateway).toContain('persist: persistDeviceTokens');
  });
});
