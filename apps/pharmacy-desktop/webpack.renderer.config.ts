import type { Configuration } from 'webpack';
import { rules } from './webpack.rules';

/**
 * Renderer bundle.
 *
 * `target: 'web'` and the empty `node` block below are load-bearing security
 * configuration, not tidiness. They make a renderer import of `fs`, `path`, or
 * `electron` a build failure rather than something that silently works in
 * development and ships.
 */
export const rendererConfig: Configuration = {
  target: 'web',
  module: {
    rules: [
      ...rules,
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
