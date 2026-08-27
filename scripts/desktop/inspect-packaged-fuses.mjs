#!/usr/bin/env node
/**
 * Read Electron fuses from a packaged binary.
 *
 * Configuration in forge.config.ts is intent. This inspects the flipped
 * fuse wire in the artifact G-02-10 actually launches.
 */
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const requireFromRoot = createRequire(pathToFileURL(join(repoRoot, 'package.json')));
const { FuseV1Options, getCurrentFuseWire } = requireFromRoot('@electron/fuses');
const { FuseState } = requireFromRoot('@electron/fuses/dist/constants.js');

const REQUIRED = {
  RunAsNode: 'DISABLE',
  EnableCookieEncryption: 'ENABLE',
  EnableNodeOptionsEnvironmentVariable: 'DISABLE',
  EnableNodeCliInspectArguments: 'DISABLE',
  EnableEmbeddedAsarIntegrityValidation: 'ENABLE',
  OnlyLoadAppFromAsar: 'ENABLE',
  GrantFileProtocolExtraPrivileges: 'DISABLE',
};

function stateName(value) {
  if (value === true) {
    return 'ENABLE';
  }
  if (value === false) {
    return 'DISABLE';
  }
  return (
    Object.entries(FuseState).find(([, code]) => code === value)?.[0] ??
    String(value)
  );
}

export async function inspectPackagedFuses(binaryPath) {
  const wire = await getCurrentFuseWire(binaryPath);
  const observed = {};
  const failures = [];

  for (const [name, expected] of Object.entries(REQUIRED)) {
    const index = FuseV1Options[name];
    const actual = stateName(wire[index]);
    observed[name] = actual;
    if (actual !== expected) {
      failures.push(`${name}: expected ${expected}, found ${actual}`);
    }
  }

  return {
    binary: binaryPath,
    passed: failures.length === 0,
    observed,
    failures,
  };
}

const invokedDirectly =
  Boolean(process.argv[1]) && import.meta.url === pathToFileURL(process.argv[1]).href;
if (invokedDirectly) {
  const binary = process.argv[2];
  if (!binary) {
    console.error('Usage: node scripts/desktop/inspect-packaged-fuses.mjs <electron-binary>');
    process.exit(2);
  }
  const result = await inspectPackagedFuses(binary);
  console.log(JSON.stringify(result, null, 2));
  process.exit(result.passed ? 0 : 1);
}
