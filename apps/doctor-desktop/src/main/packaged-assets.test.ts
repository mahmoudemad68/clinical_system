import path from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  packagedAssetRelativePath,
  resolvePackagedAsset,
} from './packaged-assets';

describe('packaged custom-scheme asset path', () => {
  const root = path.join('/tmp', 'clinic-doctor-renderer', 'main_window');

  it('does not let a leading slash discard the renderer root', () => {
    expect(packagedAssetRelativePath('/index.html')).toBe('index.html');
    expect(resolvePackagedAsset(root, '/index.html')).toBe(path.join(root, 'index.html'));
    expect(resolvePackagedAsset(root, '/index.html')).not.toBe('/index.html');
  });

  it('maps Forge webpack publicPath ../main_window/ onto the entry directory', () => {
    expect(packagedAssetRelativePath('/main_window/index.js')).toBe('index.js');
    expect(resolvePackagedAsset(root, '/main_window/index.js')).toBe(path.join(root, 'index.js'));
  });

  it('rejects path traversal out of the renderer root', () => {
    expect(resolvePackagedAsset(root, '/../../etc/passwd')).toBeNull();
    expect(resolvePackagedAsset(root, '/main_window/../../secret')).toBeNull();
  });
});
