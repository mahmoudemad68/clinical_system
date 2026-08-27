#!/usr/bin/env node
/**
 * Resolve Forge `package` output binaries and installer artifacts.
 *
 * Packaged E2E must launch these files, never `electron` from node_modules
 * or the Webpack/Vite dev server.
 */
import { existsSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';

export const APPS = {
  doctor: {
    workspace: 'apps/doctor-desktop',
    productName: 'Clinic Doctor',
    executableName: 'clinic-doctor',
    packagedOrigin: 'clinic-doctor-app://-',
    assetScheme: 'clinic-doctor-app:',
  },
  pharmacy: {
    workspace: 'apps/pharmacy-desktop',
    productName: 'Clinic Pharmacy',
    executableName: 'clinic-pharmacy',
    packagedOrigin: 'clinic-pharmacy-app://-',
    assetScheme: 'clinic-pharmacy-app:',
  },
};

const SKIP_BINARIES = new Set([
  'chrome-sandbox',
  'chrome_crashpad_handler',
  'libEGL.so',
  'libGLESv2.so',
  'libvk_swiftshader.so',
  'libvulkan.so.1',
]);

function walkFiles(dir, acc = []) {
  if (!existsSync(dir)) {
    return acc;
  }
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    let stat;
    try {
      stat = statSync(full);
    } catch {
      continue;
    }
    if (stat.isDirectory()) {
      walkFiles(full, acc);
    } else {
      acc.push(full);
    }
  }
  return acc;
}

function isLikelyBinary(file, executableName, productName) {
  const base = file.split(/[/\\]/).pop() ?? '';
  if (SKIP_BINARIES.has(base)) {
    return false;
  }
  if (file.includes(`${join('Contents', 'Frameworks')}`)) {
    return false;
  }
  if (process.platform === 'win32') {
    return base.toLowerCase() === `${executableName.toLowerCase()}.exe`;
  }
  if (process.platform === 'darwin') {
    return (
      file.includes(`${join('.app', 'Contents', 'MacOS')}`) &&
      (base === executableName || base === productName) &&
      !base.includes('Helper')
    );
  }
  return base === executableName;
}

export function findPackagedBinary(repoRoot, appKey) {
  const app = APPS[appKey];
  const outDir = join(repoRoot, app.workspace, 'out');
  const files = walkFiles(outDir).filter((file) => isLikelyBinary(file, app.executableName, app.productName));
  const preferred = files.find((file) => file.includes(`${app.productName}-`) && !file.includes(`${join('out', 'make')}`));
  return preferred ?? files[0] ?? null;
}

export function listPackagedArtifacts(repoRoot, appKey) {
  const app = APPS[appKey];
  const outDir = join(repoRoot, app.workspace, 'out');
  const files = walkFiles(outDir);
  const binary = findPackagedBinary(repoRoot, appKey);
  const asars = files.filter((file) => file.endsWith('.asar'));
  const makers = files.filter((file) =>
    file.includes(`${join('out', 'make')}`) &&
    /\.(deb|rpm|exe|nupkg|zip|dmg|appimage)$/i.test(file),
  );
  return {
    app: appKey,
    productName: app.productName,
    executableName: app.executableName,
    packagedOrigin: app.packagedOrigin,
    binary,
    asars: asars.map((file) => file.slice(repoRoot.length + 1)),
    makers: makers.map((file) => file.slice(repoRoot.length + 1)),
    binaryRelative: binary ? binary.slice(repoRoot.length + 1) : null,
  };
}

export function chromeSandboxPath(binaryPath) {
  if (!binaryPath || process.platform !== 'linux') {
    return null;
  }
  return join(dirname(binaryPath), 'chrome-sandbox');
}
