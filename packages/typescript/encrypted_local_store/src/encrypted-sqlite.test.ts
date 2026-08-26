import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { SYNTHETIC_SPIKE_CANARY } from './canary';
import {
  EncryptedSqliteStore,
  MemoryKeyVault,
  type WrappedKeyVault,
} from './encrypted-sqlite';
import { KeyMaterialMissingError, KeystoreUnavailableError, WrongDatabaseKeyError } from './errors';

class WeakVault implements WrappedKeyVault {
  isStrongProtectionAvailable(): boolean {
    return false;
  }

  wrap(): Buffer {
    throw new Error('unreachable');
  }

  unwrap(): Buffer {
    throw new Error('unreachable');
  }
}

const dirs: string[] = [];

function scratch(): string {
  const dir = mkdtempSync(join(tmpdir(), 'clinic-enc-'));
  dirs.push(dir);
  return dir;
}

afterEach(() => {
  for (const dir of dirs.splice(0)) {
    rmSync(dir, { recursive: true, force: true });
  }
});

describe('EncryptedSqliteStore', () => {
  it('refuses to persist when the key vault is weak', () => {
    expect(() =>
      EncryptedSqliteStore.open({
        directory: scratch(),
        namespace: 'doctor.encrypted.v1',
        vault: new WeakVault(),
        createIfMissing: true,
      }),
    ).toThrow(KeystoreUnavailableError);
  });

  it('hides a known canary from the database file bytes', () => {
    const directory = scratch();
    const control = join(directory, 'plaintext-control.txt');
    writeFileSync(control, SYNTHETIC_SPIKE_CANARY);

    // The assertion is only meaningful if the canary would be found in a
    // plaintext file. Without this control, a broken includes() would pass.
    expect(readFileSync(control).includes(Buffer.from(SYNTHETIC_SPIKE_CANARY))).toBe(true);

    const store = EncryptedSqliteStore.open({
      directory,
      namespace: 'spike',
      vault: new MemoryKeyVault(),
      createIfMissing: true,
    });

    store.put('canary', SYNTHETIC_SPIKE_CANARY);
    expect(store.get('canary')).toBe(SYNTHETIC_SPIKE_CANARY);

    const fileBytes = readFileSync(store.databaseFilePath);
    expect(fileBytes.includes(Buffer.from(SYNTHETIC_SPIKE_CANARY))).toBe(false);
    expect(fileBytes.includes(Buffer.from('SQLite format 3'))).toBe(false);

    store.close();
  });

  it('preserves existing rows across key rotation', () => {
    const directory = scratch();
    const store = EncryptedSqliteStore.open({
      directory,
      namespace: 'spike',
      vault: new MemoryKeyVault(),
      createIfMissing: true,
    });

    store.put('draft', SYNTHETIC_SPIKE_CANARY);
    store.rotateKey();
    store.close();

    const reopened = EncryptedSqliteStore.open({
      directory,
      namespace: 'spike',
      vault: new MemoryKeyVault(),
      createIfMissing: false,
    });

    expect(reopened.get('draft')).toBe(SYNTHETIC_SPIKE_CANARY);
    expect(readFileSync(reopened.databaseFilePath).includes(Buffer.from(SYNTHETIC_SPIKE_CANARY))).toBe(
      false,
    );
    reopened.close();
  });

  it('fails closed on a wrong key rather than returning empty rows', () => {
    const directory = scratch();
    const store = EncryptedSqliteStore.open({
      directory,
      namespace: 'spike',
      vault: new MemoryKeyVault(),
      createIfMissing: true,
    });
    store.put('draft', SYNTHETIC_SPIKE_CANARY);
    store.close();

    writeFileSync(join(directory, 'spike.key'), Buffer.alloc(32, 7));

    expect(() =>
      EncryptedSqliteStore.open({
        directory,
        namespace: 'spike',
        vault: new MemoryKeyVault(),
        createIfMissing: true,
      }),
    ).toThrow(WrongDatabaseKeyError);
  });

  it('does not mint a new key over an existing ciphertext whose wrap is missing', () => {
    const directory = scratch();
    const store = EncryptedSqliteStore.open({
      directory,
      namespace: 'spike',
      vault: new MemoryKeyVault(),
      createIfMissing: true,
    });
    store.put('draft', SYNTHETIC_SPIKE_CANARY);
    store.close();

    rmSync(join(directory, 'spike.key'));

    expect(() =>
      EncryptedSqliteStore.open({
        directory,
        namespace: 'spike',
        vault: new MemoryKeyVault(),
        createIfMissing: true,
      }),
    ).toThrow(KeyMaterialMissingError);
  });

  it('uses distinct files per namespace so doctor and pharmacy cannot collide', () => {
    const directory = scratch();
    const doctor = EncryptedSqliteStore.open({
      directory,
      namespace: 'doctor.encrypted.v1',
      vault: new MemoryKeyVault(),
      createIfMissing: true,
    });
    const pharmacy = EncryptedSqliteStore.open({
      directory,
      namespace: 'pharmacy.encrypted.v1',
      vault: new MemoryKeyVault(),
      createIfMissing: true,
    });

    doctor.put('draft', 'doctor-only');
    pharmacy.put('draft', 'pharmacy-only');

    expect(doctor.get('draft')).toBe('doctor-only');
    expect(pharmacy.get('draft')).toBe('pharmacy-only');
    expect(doctor.databaseFilePath).not.toBe(pharmacy.databaseFilePath);

    doctor.close();
    pharmacy.close();
  });
});
