/**
 * Encrypted local store for Electron main processes.
 *
 * SQLite3MultipleCiphers in SQLCipher compatibility mode. Key wrapping is a
 * port so unit tests can use a memory vault and production can use
 * `safeStorage`. This package must never be imported from a renderer bundle.
 */

export { SYNTHETIC_SPIKE_CANARY } from './canary';
export {
  KeyMaterialMissingError,
  KeystoreUnavailableError,
  WrongDatabaseKeyError,
} from './errors';
export { assessOsKeystore, type OsKeystoreDecision, type OsKeystoreReason } from './keystore-policy';
export { BACKUP_EXCLUSION_PLAN, backupExclusionCommand, type BackupExclusionPlan } from './backup-exclusion';
export { EncryptedSqliteStore, MemoryKeyVault, type WrappedKeyVault } from './encrypted-sqlite';
