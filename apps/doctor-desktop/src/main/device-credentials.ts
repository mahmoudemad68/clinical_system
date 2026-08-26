import { app, safeStorage } from 'electron';
import { chmodSync, existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { APP_CONFIG } from '../shared/app-config';
import { assessLocalEncryption } from './local-encryption';

export class SecureStorageUnavailableError extends Error {
  readonly code = 'CAPABILITY_NOT_AVAILABLE' as const;

  constructor() {
    super('Secure storage is not available on this device.');
    this.name = 'SecureStorageUnavailableError';
  }
}

export type DeviceTokens = {
  access: string;
  refresh: string;
};

function credentialPath(): string {
  return path.join(app.getPath('userData'), `${APP_CONFIG.deviceCredentialNamespace}.bin`);
}

export function secureStorageStatus(): { available: boolean; backend: string } {
  const decision = assessLocalEncryption();
  return {
    available: decision.allowed,
    backend: decision.backend,
  };
}

export function persistDeviceTokens(tokens: DeviceTokens): void {
  const decision = assessLocalEncryption();
  if (!decision.allowed) {
    throw new SecureStorageUnavailableError();
  }

  const payload = Buffer.from(JSON.stringify(tokens), 'utf8');
  const encrypted = safeStorage.encryptString(payload.toString('base64'));
  const target = credentialPath();
  writeFileSync(target, encrypted, { flag: 'w' });
  chmodSync(target, 0o600);
}

export function loadDeviceTokens(): DeviceTokens | null {
  const decision = assessLocalEncryption();
  if (!decision.allowed) {
    return null;
  }

  const target = credentialPath();
  if (!existsSync(target)) {
    return null;
  }

  try {
    const decrypted = safeStorage.decryptString(readFileSync(target));
    const parsed: unknown = JSON.parse(Buffer.from(decrypted, 'base64').toString('utf8'));
    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      'access' in parsed &&
      'refresh' in parsed &&
      typeof parsed.access === 'string' &&
      typeof parsed.refresh === 'string'
    ) {
      return { access: parsed.access, refresh: parsed.refresh };
    }
  } catch {
    return null;
  }

  return null;
}

export function clearDeviceTokens(): void {
  const target = credentialPath();
  if (existsSync(target)) {
    unlinkSync(target);
  }
}
