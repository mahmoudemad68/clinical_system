import type { ModuleOptions } from 'webpack';

export const rendererRules: Required<ModuleOptions>['rules'] = [
  {
    test: /\.tsx?$/,
    exclude: /(node_modules|\.webpack)/,
    use: {
      loader: 'ts-loader',
      options: { transpileOnly: true },
    },
  },
];

export const rules: Required<ModuleOptions>['rules'] = [
  {
    // Native modules. Encrypted SQLite (SQLite3MultipleCiphers) must sit
    // outside the asar; AutoUnpackNativesPlugin unpacks this output.
    test: /native_modules[/\\].+\.node$/,
    use: 'node-loader',
  },
  {
    test: /[/\\]node_modules[/\\].+\.(m?js|node)$/,
    parser: { amd: false },
    use: {
      loader: '@vercel/webpack-asset-relocator-loader',
      options: { outputAssetBase: 'native_modules' },
    },
  },
  ...rendererRules,
];
