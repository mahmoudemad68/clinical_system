import type { Configuration } from 'webpack';
import { rendererRules } from './webpack.rules';

/**
 * Renderer bundle.
 *
 * `target: 'web'` and the empty `node` block below are load-bearing security
 * configuration, not tidiness. They make a renderer import of `fs`, `path`, or
 * `electron` a build failure rather than something that silently works in
 * development and ships.
 *
 * Electron Forge's webpack plugin defaults development `devtool` to
 * `eval-source-map`, which requires `'unsafe-eval'`. This config overrides
 * that with a non-eval source-map so Forge development can run under
 * `script-src 'self'`. webpack-merge keeps this value: Forge merges our
 * renderer config after its base config.
 */
export const rendererConfig: Configuration = {
  target: 'web',
  // Must not be eval / eval-source-map / eval-cheap-module-source-map.
  devtool: 'source-map',
  module: {
    rules: [
      ...rendererRules,
      { test: /\.css$/, use: [{ loader: 'style-loader' }, { loader: 'css-loader' }] },
    ],
  },
  resolve: {
    extensions: ['.js', '.ts', '.jsx', '.tsx', '.css'],
    // No Node polyfills. A renderer that needs one is reaching for something it
    // must not have.
    fallback: {
      fs: false,
      path: false,
      crypto: false,
      os: false,
      child_process: false,
    },
  },
};
