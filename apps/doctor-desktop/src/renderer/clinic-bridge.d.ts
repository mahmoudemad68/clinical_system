import type { ClinicBridge } from '@clinic/desktop-bridge-contracts';

/**
 * The only Electron-adjacent declaration renderer code may reference.
 *
 * A *type*, not an import of anything executable. The dependency-boundary lint
 * rule permits this one file to name the bridge contract package and forbids
 * every other renderer file from importing `electron` or a Node builtin.
 */
declare global {
  interface Window {
    readonly clinic: ClinicBridge;
  }
}

export {};
