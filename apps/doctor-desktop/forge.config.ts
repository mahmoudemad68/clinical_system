import type { ForgeConfig } from '@electron-forge/shared-types';
import { MakerSquirrel } from '@electron-forge/maker-squirrel';
import { MakerZIP } from '@electron-forge/maker-zip';
import { MakerDeb } from '@electron-forge/maker-deb';
import { WebpackPlugin } from '@electron-forge/plugin-webpack';
import { FusesPlugin } from '@electron-forge/plugin-fuses';
import { AutoUnpackNativesPlugin } from '@electron-forge/plugin-auto-unpack-natives';
import { FuseV1Options, FuseVersion } from '@electron/fuses';

import { mainConfig } from './webpack.main.config';
import { rendererConfig } from './webpack.renderer.config';

/**
 * Electron Forge configuration for Clinic Doctor.
 *
 * Forge's maintained Webpack/TypeScript pipeline is the Phase 00 default.
 * ADR 0010 requires a compatibility ADR before adopting an Electron Vite
 * plugin, because its official Forge integration is still experimental.
 */
const config: ForgeConfig = {
  packagerConfig: {
    name: 'Clinic Doctor',
    executableName: 'clinic-doctor',
    appBundleId: 'eg.clinic.doctor.desktop',

    // Integrity check of the packaged asar. Detects post-signing tampering.
    asar: true,

    // NOTE: `osxSign` and `osxNotarize` are deliberately ABSENT, not set to
    // undefined. Under `exactOptionalPropertyTypes` an explicit undefined is a
    // type error, and omission is the clearer statement anyway: this
    // configuration has no signing identity at all.
    //
    // Signing and notarization are Phase 23 and owned by production/DR. Phase
    // 00 CI produces unsigned verification artifacts and must never be given
    // signing credentials, least of all on a fork pull request.
  },

  rebuildConfig: {},

  makers: [
    new MakerSquirrel({ name: 'clinic-doctor' }),
    new MakerZIP({}, ['darwin']),
    new MakerDeb({ options: { name: 'clinic-doctor', productName: 'Clinic Doctor' } }),
  ],

  plugins: [
    // Native modules (the Phase 05 encrypted SQLite binding) must sit outside
    // the asar to be loadable.
    new AutoUnpackNativesPlugin({}),

    new WebpackPlugin({
      mainConfig,
      renderer: {
        config: rendererConfig,
        entryPoints: [
          {
            name: 'main_window',
            html: './src/renderer/index.html',
            js: './src/renderer/index.tsx',
            preload: { js: './src/preload/index.ts' },
          },
        ],
      },
      // The dev-server CSP still forbids remote script. A permissive
      // development policy trains the app to work in a way production forbids,
      // and the difference only surfaces after packaging.
      devContentSecurityPolicy:
        "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; connect-src 'self' ws:; img-src 'self' data:",
    }),

    /**
     * Electron fuses — flipped at package time, before signing.
     *
     * Each one removes a way to run attacker-chosen code inside a signed,
     * trusted application. They are the difference between "an attacker needs
     * to break the signature" and "an attacker sets an environment variable".
     */
    new FusesPlugin({
      version: FuseVersion.V1,

      // ELECTRON_RUN_AS_NODE turns the signed binary into a general-purpose
      // Node interpreter. This is the most important fuse in the list.
      [FuseV1Options.RunAsNode]: false,

      // Cookie encryption at rest.
      [FuseV1Options.EnableCookieEncryption]: true,

      // NODE_OPTIONS and --inspect both allow injecting code into the main
      // process of an already-installed application.
      [FuseV1Options.EnableNodeOptionsEnvironmentVariable]: false,
      [FuseV1Options.EnableNodeCliInspectArguments]: false,

      // Validate the asar against the signature, so swapping app code inside a
      // signed bundle fails.
      [FuseV1Options.EnableEmbeddedAsarIntegrityValidation]: true,
      [FuseV1Options.OnlyLoadAppFromAsar]: true,
    }),
  ],
};

export default config;
