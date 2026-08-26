/** Strong OS encryption is unavailable; sensitive persistence must not proceed. */
export class KeystoreUnavailableError extends Error {
  constructor(message = 'OS keystore is too weak to wrap a database key.') {
    super(message);
    this.name = 'KeystoreUnavailableError';
  }
}

/**
 * Ciphertext exists but the wrapped key does not.
 *
 * Minting a replacement key would silently orphan the existing file. Recovery
 * is explicit failure, not a new empty database.
 */
export class KeyMaterialMissingError extends Error {
  constructor(message = 'Encrypted database exists but wrapped key material is missing.') {
    super(message);
    this.name = 'KeyMaterialMissingError';
  }
}

/** The file opened, but the supplied key cannot read the schema. */
export class WrongDatabaseKeyError extends Error {
  constructor(message = 'The database key does not decrypt this file.') {
    super(message);
    this.name = 'WrongDatabaseKeyError';
  }
}
