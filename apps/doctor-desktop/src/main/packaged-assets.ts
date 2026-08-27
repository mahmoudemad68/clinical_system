import path from 'node:path';

/**
 * Map a custom-scheme URL pathname onto a file under the packaged renderer
 * entry directory.
 *
 * `path.join(root, '/index.html')` is `/index.html` on POSIX — the leading
 * slash discards `root`. Packaged E2E found that bug: every asset 403'd,
 * preload still ran, and React never mounted.
 */
export const PACKAGED_RENDERER_ENTRY = 'main_window';

export function packagedAssetRelativePath(
  pathname: string,
  entryPointName = PACKAGED_RENDERER_ENTRY,
): string {
  let relative = pathname === '/' ? 'index.html' : pathname.replace(/^\/+/, '');
  const prefix = `${entryPointName}/`;
  if (relative === entryPointName) {
    relative = 'index.html';
  } else if (relative.startsWith(prefix)) {
    relative = relative.slice(prefix.length);
  }
  return relative === '' ? 'index.html' : relative;
}

export function isPathInside(root: string, candidate: string): boolean {
  const resolvedRoot = path.resolve(root);
  const resolved = path.resolve(candidate);
  const relative = path.relative(resolvedRoot, resolved);

  return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative);
}

export function resolvePackagedAsset(root: string, pathname: string): string | null {
  const relative = packagedAssetRelativePath(pathname);
  const resolved = path.normalize(path.join(root, relative));
  return isPathInside(root, resolved) ? resolved : null;
}
