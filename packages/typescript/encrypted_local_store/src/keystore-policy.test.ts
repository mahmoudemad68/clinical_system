import { describe, expect, it } from 'vitest';
import { assessOsKeystore } from './keystore-policy';

describe('assessOsKeystore', () => {
  it('refuses Linux basic_text even when encryptionAvailable is true', () => {
    // Electron reports isEncryptionAvailable() for the reversible fallback.
    // That is not strong protection.
    const decision = assessOsKeystore({
      platform: 'linux',
      encryptionAvailable: true,
      linuxBackend: 'basic_text',
    });

    expect(decision).toEqual({
      allowed: false,
      backend: 'basic_text',
      reason: 'linux_basic_text',
    });
  });

  it('refuses Linux unknown, which is what Electron returns before app ready', () => {
    const decision = assessOsKeystore({
      platform: 'linux',
      encryptionAvailable: true,
      linuxBackend: 'unknown',
    });

    expect(decision.allowed).toBe(false);
    if (!decision.allowed) {
      expect(decision.reason).toBe('linux_unknown_backend');
    }
  });

  it('allows a Secret Service or KWallet backend on Linux', () => {
    for (const backend of ['gnome_libsecret', 'kwallet', 'kwallet5', 'kwallet6']) {
      expect(
        assessOsKeystore({
          platform: 'linux',
          encryptionAvailable: true,
          linuxBackend: backend,
        }).allowed,
      ).toBe(true);
    }
  });

  it('refuses Windows and macOS when the OS API reports encryption unavailable', () => {
    expect(
      assessOsKeystore({ platform: 'win32', encryptionAvailable: false }).allowed,
    ).toBe(false);
    expect(
      assessOsKeystore({ platform: 'darwin', encryptionAvailable: false }).allowed,
    ).toBe(false);
  });

  it('allows Windows and macOS when the OS API reports encryption available', () => {
    expect(assessOsKeystore({ platform: 'win32', encryptionAvailable: true }).allowed).toBe(true);
    expect(assessOsKeystore({ platform: 'darwin', encryptionAvailable: true }).allowed).toBe(true);
  });

  it('fails closed on an unrecognised platform', () => {
    const decision = assessOsKeystore({ platform: 'freebsd', encryptionAvailable: true });

    expect(decision.allowed).toBe(false);
    if (!decision.allowed) {
      expect(decision.reason).toBe('unsupported_platform');
    }
  });
});
