import { safeStorage } from 'electron';
import { assessOsKeystore, type OsKeystoreDecision } from '@clinic/encrypted-local-store';

/**
 * Decide whether this process may wrap a database key with the OS account facility.
 *
 * Must run after `app.whenReady()`. On Linux, `getSelectedStorageBackend()` is
 * `unknown` before that, and `unknown` is treated as weak.
 *
 * Phase 00 never writes clinical content. A strong keystore is necessary for a
 * later encrypted store, not permission to start writing drafts.
 */
export function assessLocalEncryption(): OsKeystoreDecision {
  const linuxBackend =
    process.platform === 'linux' && typeof safeStorage.getSelectedStorageBackend === 'function'
      ? safeStorage.getSelectedStorageBackend()
      : undefined;

  return assessOsKeystore({
    platform: process.platform,
    encryptionAvailable: safeStorage.isEncryptionAvailable(),
    linuxBackend,
  });
}
