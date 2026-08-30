import webpack from 'webpack';
import type { Configuration } from 'webpack';
import { rules } from './webpack.rules';
import { parsePackagedAllowlistEntries } from './src/main/api-origin';

/**
 * Main-process bundle.
 *
 * Targets Electron's Node side. The renderer bundle is a separate
 * configuration on purpose: sharing one would let a renderer import resolve to
 * a Node builtin, which is exactly the boundary this app is built around.
 *
 * `CLINIC_PHARMACY_PACKAGED_API_ALLOWED_ORIGINS` is a build-time input only.
 * Webpack bakes the resulting exact origins into the main-process bundle.
 * Runtime selection of a base URL cannot enlarge that list. An empty bake
 * fails closed when the packaged app runs. This env name is Pharmacy-specific
 * so a Doctor bake cannot accidentally become this application's trust
 * list.
 */
const packagedApiAllowedOrigins = parsePackagedAllowlistEntries(
  process.env['CLINIC_PHARMACY_PACKAGED_API_ALLOWED_ORIGINS'] ?? '',
);

export const mainConfig: Configuration = {
  entry: './src/main/index.ts',
  module: { rules },
  resolve: {
    extensions: ['.js', '.ts', '.jsx', '.tsx', '.css', '.json'],
  },
  // Native SQLite3MultipleCiphers must stay a Node addon, not a webpack graph
  // that a renderer could resolve. The renderer config does not list this.
  externals: {
    'better-sqlite3-multiple-ciphers': 'commonjs better-sqlite3-multiple-ciphers',
  },
  plugins: [
    new webpack.DefinePlugin({
      __CLINIC_PACKAGED_API_ALLOWED_ORIGINS__: JSON.stringify(packagedApiAllowedOrigins),
    }),
  ],
};
