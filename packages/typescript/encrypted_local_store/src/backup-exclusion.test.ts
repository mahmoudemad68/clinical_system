import { describe, expect, it } from 'vitest';
import { BACKUP_EXCLUSION_PLAN, backupExclusionCommand } from './backup-exclusion';

describe('backup exclusion plan', () => {
  it('disables every platform cloud-backup flag the spike can name', () => {
    expect(BACKUP_EXCLUSION_PLAN.androidAllowBackup).toBe(false);
    expect(BACKUP_EXCLUSION_PLAN.androidMigrateWithBackup).toBe(false);
    expect(BACKUP_EXCLUSION_PLAN.iosSynchronizable).toBe(false);
    expect(BACKUP_EXCLUSION_PLAN.iosAccessibility).toBe('first_unlock_this_device');
    expect(BACKUP_EXCLUSION_PLAN.windowsNotContentIndexed).toBe(true);
    expect(BACKUP_EXCLUSION_PLAN.linuxNoDesktopCloudBackupApi).toBe(true);
  });

  it('emits a macOS xattr command for the backup-exclude item', () => {
    const command = backupExclusionCommand('darwin', '/tmp/spike.sqlite');

    expect(command?.argv).toEqual([
      'xattr',
      '-w',
      'com.apple.metadata:com_apple_backup_excludeItem',
      '1',
      '/tmp/spike.sqlite',
    ]);
  });

  it('emits a Windows not-content-indexed attrib command', () => {
    const command = backupExclusionCommand('win32', 'C:\\spike.sqlite');

    expect(command?.argv).toEqual(['attrib', '+U', 'C:\\spike.sqlite']);
  });

  it('has no desktop cloud-backup API to call on Linux', () => {
    expect(backupExclusionCommand('linux', '/tmp/spike.sqlite')).toBeNull();
  });
});
