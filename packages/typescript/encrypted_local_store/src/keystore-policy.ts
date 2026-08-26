/**
 * Fail-closed policy for wrapping a database key with the OS account facility.
 *
 * Electron `safeStorage` on Linux can fall back to `basic_text`, which stores
 * the "encrypted" payload in a reversible form. ADR 0010 forbids that for a
 * device token, database key, or clinical draft.
 *
 * This module has no Electron import on purpose: renderers must not be able to
 * reach it through a shared graph, and unit tests must not need a BrowserWindow.
 */

export type OsKeystoreReason =
  | 'encryption_unavailable'
  | 'linux_basic_text'
  | 'linux_unknown_backend'
  | 'unsupported_platform';

export type OsKeystoreDecision =
  | { readonly allowed: true; readonly backend: string }
  | { readonly allowed: false; readonly backend: string; readonly reason: OsKeystoreReason };

const STRONG_LINUX_BACKENDS = new Set(['gnome_libsecret', 'kwallet', 'kwallet5', 'kwallet6']);

export function assessOsKeystore(input: {
  platform: string;
  encryptionAvailable: boolean;
  linuxBackend?: string | undefined;
}): OsKeystoreDecision {
  if (input.platform === 'linux') {
    const backend = input.linuxBackend ?? 'unknown';

    if (!input.encryptionAvailable) {
      return { allowed: false, backend, reason: 'encryption_unavailable' };
    }

    if (backend === 'basic_text') {
      return { allowed: false, backend, reason: 'linux_basic_text' };
    }

    if (STRONG_LINUX_BACKENDS.has(backend)) {
      return { allowed: true, backend };
    }

    // `unknown` is what Electron returns before app ready. Treating it as
    // strong would persist a key with no protection.
    return { allowed: false, backend, reason: 'linux_unknown_backend' };
  }

  if (input.platform === 'win32' || input.platform === 'darwin') {
    if (!input.encryptionAvailable) {
      return { allowed: false, backend: input.platform, reason: 'encryption_unavailable' };
    }

    return { allowed: true, backend: input.platform };
  }

  return { allowed: false, backend: input.platform, reason: 'unsupported_platform' };
}
