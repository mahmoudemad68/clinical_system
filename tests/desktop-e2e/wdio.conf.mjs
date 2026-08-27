import { existsSync } from 'node:fs';

const binary = process.env.CLINIC_DESKTOP_BINARY;
const origin = process.env.CLINIC_DESKTOP_ORIGIN;
const productName = process.env.CLINIC_DESKTOP_PRODUCT;
const userData = process.env.CLINIC_DESKTOP_USER_DATA;

if (!binary || !existsSync(binary)) {
  throw new Error(
    'CLINIC_DESKTOP_BINARY must point at a Forge-packaged executable. Packaged E2E must not launch electron from node_modules or a Vite/Forge dev server.',
  );
}

if (!origin || origin.startsWith('file:') || origin.startsWith('http:')) {
  throw new Error('CLINIC_DESKTOP_ORIGIN must be the privileged custom application origin.');
}

if (!productName) {
  throw new Error('CLINIC_DESKTOP_PRODUCT is required.');
}

if (!userData) {
  throw new Error('CLINIC_DESKTOP_USER_DATA is required so the packaged app does not touch the developer profile.');
}

export const config = {
  runner: 'local',
  specs: ['./specs/packaged-runtime.spec.mjs'],
  maxInstances: 1,
  specFileRetries: 0,
  logLevel: 'info',
  waitforTimeout: 20_000,
  connectionRetryTimeout: 120_000,
  connectionRetryCount: 2,
  framework: 'mocha',
  reporters: ['spec'],
  autoXvfb: true,
  mochaOpts: {
    ui: 'bdd',
    timeout: 60_000,
  },
  services: [
    [
      'electron',
      {
        apparmorAutoInstall: 'sudo',
      },
    ],
  ],
  capabilities: [
    {
      browserName: 'electron',
      browserVersion: '44.0.0',
      'wdio:electronServiceOptions': {
        appBinaryPath: binary,
        appArgs: [`--user-data-dir=${userData}`],
        apparmorAutoInstall: 'sudo',
        captureRendererLogs: true,
      },
    },
  ],
  autoCompileOpts: {
    autoCompile: false,
  },
};
