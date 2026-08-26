/**
 * Per-platform backup-exclusion intent for an encrypted database file.
 *
 * The OS APIs that actually flip the bits only exist on the matching host.
 * Tests assert the plan; `applyBackupExclusion` executes what this host can.
 */

export interface BackupExclusionPlan {
  readonly androidAllowBackup: false;
  readonly androidMigrateWithBackup: false;
  readonly iosSynchronizable: false;
  readonly iosAccessibility: 'first_unlock_this_device';
  readonly macosXattr: 'com.apple.metadata:com_apple_backup_excludeItem';
  readonly windowsNotContentIndexed: true;
  readonly linuxNoDesktopCloudBackupApi: true;
}

export const BACKUP_EXCLUSION_PLAN: BackupExclusionPlan = {
  androidAllowBackup: false,
  androidMigrateWithBackup: false,
  iosSynchronizable: false,
  iosAccessibility: 'first_unlock_this_device',
  macosXattr: 'com.apple.metadata:com_apple_backup_excludeItem',
  windowsNotContentIndexed: true,
  linuxNoDesktopCloudBackupApi: true,
};

export function backupExclusionCommand(
  platform: string,
  filePath: string,
): { readonly argv: readonly string[] } | null {
  if (platform === 'darwin') {
    return {
      argv: ['xattr', '-w', BACKUP_EXCLUSION_PLAN.macosXattr, '1', filePath],
    };
  }

  if (platform === 'win32') {
    // +U = not content-indexed. File History / OneDrive still need the file
    // to live outside a synced Documents folder; that path choice is the
    // Electron userData directory, not this attribute.
    return { argv: ['attrib', '+U', filePath] };
  }

  return null;
}
