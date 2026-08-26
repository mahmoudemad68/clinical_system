/**
 * Identity of this application.
 *
 * Phase 00 §2.3 requires the doctor and pharmacy desktops to differ across
 * every security-relevant namespace. These constants are what keep the two
 * applications from sharing a credential store, a database, a protocol
 * registration, or an update channel — and a shared pure TypeScript package
 * must never collapse them.
 *
 * Read by the main process only. The renderer receives a deliberately narrow
 * subset through `app.metadata()`: user-data paths, protocol schemes, and
 * update channels are reconnaissance for anyone who achieves script execution
 * in the renderer, and the UI has no use for them.
 */
export const APP_CONFIG = {
  appId: 'eg.clinic.pharmacy.desktop',
  productName: 'Clinic Pharmacy',
  executableName: 'clinic-pharmacy',

  /** OS user-data directory name. Distinct so the two apps never share state. */
  userDataDirectory: 'clinic-pharmacy',

  /** Deep-link scheme. Registered exclusively; a foreign scheme is refused. */
  protocolScheme: 'clinic-pharmacy',

  /**
   * Privileged standard scheme for packaged renderer assets.
   *
   * ADR 0010 requires an exact production origin instead of inheriting broad
   * `file://` privileges, which would give the renderer read access to the
   * whole filesystem through relative paths.
   */
  assetProtocolScheme: 'clinic-pharmacy-app',

  /** Phase 05 encrypted-database namespace. Spike-only synthetic data until G-06-01 closes. */
  encryptedDatabaseNamespace: 'pharmacy.encrypted.v1',

  /** OS keystore account namespace for the device token. */
  deviceCredentialNamespace: 'eg.clinic.pharmacy.device',

  /** Phase 01 Auth `client_class` sent from the main process only. */
  apiClientClass: 'pharmacy_desktop',

  /** Name of this app's IPC capability registry, for diagnostics. */
  capabilityRegistry: 'pharmacyCapabilities',

  /**
   * The exact origin packaged renderer content is served from.
   *
   * Pinned rather than derived, because `protocol === 'scheme:'` alone accepts
   * ANY host under that scheme. An IPC sender check that only compares the
   * protocol would admit `scheme://anything`, which is not the window we
   * serve. Electron's guidance is to validate the full sender origin.
   */
  packagedOrigin: 'clinic-pharmacy-app://-',

  /** Update channel. Publication is Phase 23 and owned by production/DR. */
  updateChannel: 'pharmacy-stable',
} as const;

export type AppConfig = typeof APP_CONFIG;
