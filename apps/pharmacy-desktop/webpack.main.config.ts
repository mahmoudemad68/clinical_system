import type { Configuration } from 'webpack';
import { rules } from './webpack.rules';

/**
 * Main-process bundle.
 *
 * Targets Electron's Node side. The renderer bundle is a separate
 * configuration on purpose: sharing one would let a renderer import resolve to
 * a Node builtin, which is exactly the boundary this app is built around.
 */
export const mainConfig: Configuration = {
  entry: './src/main/index.ts',
  module: { rules },
  resolve: {
    extensions: ['.js', '.ts', '.jsx', '.tsx', '.css', '.json'],
  },
};
