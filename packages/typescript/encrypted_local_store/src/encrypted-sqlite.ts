import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { KeyMaterialMissingError, KeystoreUnavailableError, WrongDatabaseKeyError } from './errors';

interface Statement {
  run(...params: unknown[]): unknown;
  get(...params: unknown[]): unknown;
}

interface MultipleCiphersDatabase {
  pragma(source: string): unknown;
  key(key: Buffer): this;
  rekey(key: Buffer): this;
  exec(source: string): this;
  prepare(source: string): Statement;
  close(): this;
}

interface DatabaseConstructor {
  new (filename: string): MultipleCiphersDatabase;
}

// The published package's `exports` omit `types`, so a static import fails
// TypeScript under `moduleResolution: bundler`. The native addon is loaded at
// runtime from Node, never from a renderer bundle.
const require = createRequire(import.meta.url);
const Database = require('better-sqlite3-multiple-ciphers') as DatabaseConstructor;

const KEY_BYTES = 32;

export interface WrappedKeyVault {
  isStrongProtectionAvailable(): boolean;
  wrap(plain: Buffer): Buffer;
  unwrap(wrapped: Buffer): Buffer;
}

/** Test double. Production wrapping is Electron `safeStorage` in the main process. */
export class MemoryKeyVault implements WrappedKeyVault {
  isStrongProtectionAvailable(): boolean {
    return true;
  }

  wrap(plain: Buffer): Buffer {
    return Buffer.from(plain);
  }

  unwrap(wrapped: Buffer): Buffer {
    return Buffer.from(wrapped);
  }
}

function applyCipher(db: MultipleCiphersDatabase, key: Buffer): void {
  db.pragma("cipher='sqlcipher'");
  db.pragma('legacy = 4');
  db.key(key);
}

function assertReadable(db: MultipleCiphersDatabase): void {
  try {
    db.prepare('SELECT count(*) AS n FROM sqlite_master').get();
  } catch {
    db.close();
    throw new WrongDatabaseKeyError();
  }
}

function writeAtomic(filePath: string, contents: Buffer): void {
  const tmp = `${filePath}.tmp`;
  fs.writeFileSync(tmp, contents, { mode: 0o600 });
  fs.renameSync(tmp, filePath);
  try {
    fs.chmodSync(filePath, 0o600);
  } catch {
    // Windows has no POSIX modes.
  }
}

/**
 * Encrypted SQLite file using SQLite3MultipleCiphers in SQLCipher-compat mode.
 *
 * Intent-named get/put only. Callers never receive SQL, the raw key, or a
 * statement handle — that is the same boundary the Electron main process must
 * keep from the renderer.
 */
export class EncryptedSqliteStore {
  private constructor(
    private readonly db: MultipleCiphersDatabase,
    readonly databaseFilePath: string,
    private readonly keyFilePath: string,
    private readonly vault: WrappedKeyVault,
  ) {}

  static open(options: {
    directory: string;
    namespace: string;
    vault: WrappedKeyVault;
    createIfMissing: boolean;
  }): EncryptedSqliteStore {
    if (!options.vault.isStrongProtectionAvailable()) {
      throw new KeystoreUnavailableError();
    }

    fs.mkdirSync(options.directory, { recursive: true });

    const filePath = path.join(options.directory, `${options.namespace}.sqlite`);
    const keyFilePath = path.join(options.directory, `${options.namespace}.key`);
    const dbExists = fs.existsSync(filePath);
    const keyExists = fs.existsSync(keyFilePath);

    if (dbExists && !keyExists) {
      throw new KeyMaterialMissingError();
    }

    if (!dbExists && !options.createIfMissing) {
      throw new KeyMaterialMissingError();
    }

    let key: Buffer;
    if (keyExists) {
      key = options.vault.unwrap(fs.readFileSync(keyFilePath));
    } else {
      key = randomBytes(KEY_BYTES);
      writeAtomic(keyFilePath, options.vault.wrap(key));
    }

    const db = new Database(filePath);
    applyCipher(db, key);

    if (dbExists) {
      assertReadable(db);
    } else {
      db.exec(`
        CREATE TABLE IF NOT EXISTS spike_kv (
          k TEXT PRIMARY KEY NOT NULL,
          v TEXT NOT NULL
        );
      `);
      try {
        fs.chmodSync(filePath, 0o600);
      } catch {
        // Windows has no POSIX modes.
      }
    }

    return new EncryptedSqliteStore(db, filePath, keyFilePath, options.vault);
  }

  put(key: string, value: string): void {
    this.db.prepare('INSERT OR REPLACE INTO spike_kv (k, v) VALUES (?, ?)').run(key, value);
  }

  get(key: string): string | undefined {
    const row = this.db.prepare('SELECT v FROM spike_kv WHERE k = ?').get(key) as { v: string } | undefined;

    return row?.v;
  }

  /**
   * Re-encrypt the file in place and replace the wrapped key.
   *
   * Existing rows must remain readable after close/reopen. A failed wrap-file
   * rename after a successful rekey is a residual; the previous wrap is kept
   * as `.key.prev` so an operator can recover rather than minting a new key.
   */
  rotateKey(): void {
    const next = randomBytes(KEY_BYTES);
    const nextWrap = this.vault.wrap(next);
    const nextPath = `${this.keyFilePath}.new`;
    const prevPath = `${this.keyFilePath}.prev`;

    writeAtomic(nextPath, nextWrap);
    this.db.rekey(next);

    if (fs.existsSync(this.keyFilePath)) {
      fs.renameSync(this.keyFilePath, prevPath);
    }

    fs.renameSync(nextPath, this.keyFilePath);
  }

  close(): void {
    this.db.close();
  }
}
