/**
 * Opaque doctor verification evidence handles.
 *
 * The renderer receives only a short-lived handle id and safe metadata. The
 * absolute path never crosses IPC. Bytes are read through a pinned file
 * descriptor so a pathname swap after open cannot change the uploaded object.
 */

import { randomBytes } from 'node:crypto';
import {
  closeSync,
  fstatSync,
  lstatSync,
  openSync,
  readFileSync,
  realpathSync,
  type Stats,
} from 'node:fs';
import path from 'node:path';
import type { DoctorEvidenceSelectResponse } from '@clinic/desktop-bridge-contracts';

export const EVIDENCE_MAX_BYTES = 20 * 1024 * 1024;
export const EVIDENCE_HANDLE_TTL_MS = 15 * 60 * 1000;
export const EVIDENCE_REQUIREMENT_CODE = 'professional_id' as const;

export type EvidenceMediaType = 'application/pdf' | 'image/jpeg' | 'image/png';

export type PinnedOpenHook = (context: { fd: number; absolutePath: string }) => void;

export class EvidenceFileError extends Error {
  constructor(
    readonly code: 'UNSUPPORTED_FILE' | 'FILE_MISSING' | 'FILE_CHANGED' | 'INVALID_REQUEST',
  ) {
    super(code);
    this.name = 'EvidenceFileError';
  }
}

type HandleRecord = {
  id: string;
  absolutePath: string;
  realPath: string;
  displayName: string;
  size: number;
  mtimeMs: number;
  dev: number;
  ino: number;
  candidateMediaType: EvidenceMediaType;
  createdAt: number;
};

let openDescriptorCount = 0;

export function evidenceOpenDescriptorCount(): number {
  return openDescriptorCount;
}

export function sniffEvidenceMediaType(bytes: Uint8Array): EvidenceMediaType | null {
  if (bytes.length >= 5 && bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46) {
    return 'application/pdf';
  }
  if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
    return 'image/jpeg';
  }
  if (
    bytes.length >= 8 &&
    bytes[0] === 0x89 &&
    bytes[1] === 0x50 &&
    bytes[2] === 0x4e &&
    bytes[3] === 0x47 &&
    bytes[4] === 0x0d &&
    bytes[5] === 0x0a &&
    bytes[6] === 0x1a &&
    bytes[7] === 0x0a
  ) {
    return 'image/png';
  }
  return null;
}

function extensionFor(media: EvidenceMediaType): readonly string[] {
  if (media === 'application/pdf') {
    return ['.pdf'];
  }
  if (media === 'image/jpeg') {
    return ['.jpg', '.jpeg'];
  }
  return ['.png'];
}

function identityFromStats(stats: Stats): { size: number; mtimeMs: number; dev: number; ino: number } {
  return {
    size: stats.size,
    mtimeMs: stats.mtimeMs,
    dev: stats.dev,
    ino: stats.ino,
  };
}

function identitiesMatch(
  left: { size: number; mtimeMs: number; dev: number; ino: number },
  right: { size: number; mtimeMs: number; dev: number; ino: number },
): boolean {
  return (
    left.size === right.size &&
    left.mtimeMs === right.mtimeMs &&
    left.dev === right.dev &&
    left.ino === right.ino
  );
}

function withPinnedDescriptor<T>(absolutePath: string, use: (fd: number, stats: Stats) => T): T {
  let fd: number | undefined;
  try {
    try {
      fd = openSync(absolutePath, 'r');
    } catch {
      throw new EvidenceFileError('FILE_MISSING');
    }
    openDescriptorCount += 1;
    const stats = fstatSync(fd);
    return use(fd, stats);
  } finally {
    if (fd !== undefined) {
      try {
        closeSync(fd);
      } finally {
        openDescriptorCount -= 1;
      }
    }
  }
}

function readPinnedBytes(fd: number, size: number): Buffer {
  const bytes = readFileSync(fd);
  if (bytes.byteLength !== size) {
    throw new EvidenceFileError('FILE_CHANGED');
  }
  return bytes;
}

export class DoctorEvidenceHandleStore {
  private readonly handles = new Map<string, HandleRecord>();

  clear(): void {
    this.handles.clear();
  }

  clearHandle(handleId: string): boolean {
    return this.handles.delete(handleId);
  }

  registerSelectedFile(absolutePath: string, now = Date.now()): DoctorEvidenceSelectResponse {
    const inspected = inspectCandidateFile(absolutePath);
    this.handles.clear();
    const id = randomBytes(24).toString('base64url');
    this.handles.set(id, {
      id,
      absolutePath,
      realPath: inspected.realPath,
      displayName: inspected.displayName,
      size: inspected.size,
      mtimeMs: inspected.mtimeMs,
      dev: inspected.dev,
      ino: inspected.ino,
      candidateMediaType: inspected.candidateMediaType,
      createdAt: now,
    });
    return {
      selected: true,
      handleId: id,
      displayName: inspected.displayName,
      sizeBytes: inspected.size,
      candidateMediaType: inspected.candidateMediaType,
    };
  }

  peekSafe(handleId: string, now = Date.now()): { displayName: string; sizeBytes: number; candidateMediaType: EvidenceMediaType } {
    const record = this.requireFresh(handleId, now);
    return {
      displayName: record.displayName,
      sizeBytes: record.size,
      candidateMediaType: record.candidateMediaType,
    };
  }

  hasHandle(handleId: string, now = Date.now()): boolean {
    try {
      this.requireFresh(handleId, now);
      return true;
    } catch {
      return false;
    }
  }

  /**
   * Open the selected pathname, fstat the descriptor, then read bytes from
   * that same descriptor. Callers must not put the path or fd on an IPC payload.
   */
  readForUpload(
    handleId: string,
    now = Date.now(),
    afterPinnedOpen?: PinnedOpenHook,
  ): {
    bytes: Buffer;
    sizeBytes: number;
    candidateMediaType: EvidenceMediaType;
    displayName: string;
  } {
    const record = this.requireFresh(handleId, now);
    let stats: Stats;
    try {
      stats = lstatSync(record.absolutePath);
    } catch {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_MISSING');
    }

    if (stats.isSymbolicLink() || stats.isDirectory() || !stats.isFile()) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_CHANGED');
    }

    let realPath: string;
    try {
      realPath = realpathSync(record.absolutePath);
    } catch {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_MISSING');
    }

    if (realPath !== record.realPath) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_CHANGED');
    }

    const pathIdentity = identityFromStats(stats);
    if (!identitiesMatch(pathIdentity, record)) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_CHANGED');
    }

    if (pathIdentity.size > EVIDENCE_MAX_BYTES || pathIdentity.size <= 0) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }

    try {
      return withPinnedDescriptor(record.absolutePath, (fd, opened) => {
        if (opened.isSymbolicLink() || opened.isDirectory() || !opened.isFile()) {
          throw new EvidenceFileError('FILE_CHANGED');
        }
        const openedIdentity = identityFromStats(opened);
        if (!identitiesMatch(openedIdentity, record)) {
          throw new EvidenceFileError('FILE_CHANGED');
        }
        afterPinnedOpen?.({ fd, absolutePath: record.absolutePath });
        const bytes = readPinnedBytes(fd, record.size);
        const afterRead = identityFromStats(fstatSync(fd));
        if (!identitiesMatch(afterRead, record)) {
          throw new EvidenceFileError('FILE_CHANGED');
        }
        const sniffed = sniffEvidenceMediaType(bytes);
        if (sniffed !== record.candidateMediaType) {
          throw new EvidenceFileError('FILE_CHANGED');
        }
        return {
          bytes,
          sizeBytes: record.size,
          candidateMediaType: record.candidateMediaType,
          displayName: record.displayName,
        };
      });
    } catch (error) {
      if (error instanceof EvidenceFileError) {
        this.handles.delete(handleId);
      }
      throw error;
    }
  }

  invalidate(handleId: string): void {
    this.handles.delete(handleId);
  }

  private requireFresh(handleId: string, now: number): HandleRecord {
    const record = this.handles.get(handleId);
    if (record === undefined) {
      throw new EvidenceFileError('FILE_MISSING');
    }
    if (now - record.createdAt > EVIDENCE_HANDLE_TTL_MS) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_MISSING');
    }
    return record;
  }
}

export function inspectCandidateFile(absolutePath: string): {
  realPath: string;
  displayName: string;
  size: number;
  mtimeMs: number;
  dev: number;
  ino: number;
  candidateMediaType: EvidenceMediaType;
} {
  if (typeof absolutePath !== 'string' || absolutePath.trim() === '') {
    throw new EvidenceFileError('UNSUPPORTED_FILE');
  }

  let stats: Stats;
  try {
    stats = lstatSync(absolutePath);
  } catch {
    throw new EvidenceFileError('FILE_MISSING');
  }

  if (stats.isSymbolicLink() || stats.isDirectory() || !stats.isFile()) {
    throw new EvidenceFileError('UNSUPPORTED_FILE');
  }

  if (stats.size <= 0 || stats.size > EVIDENCE_MAX_BYTES) {
    throw new EvidenceFileError('UNSUPPORTED_FILE');
  }

  let realPath: string;
  try {
    realPath = realpathSync(absolutePath);
  } catch {
    throw new EvidenceFileError('UNSUPPORTED_FILE');
  }

  return withPinnedDescriptor(absolutePath, (fd, opened) => {
    if (opened.isDirectory() || !opened.isFile()) {
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }
    const identity = identityFromStats(opened);
    if (identity.size <= 0 || identity.size > EVIDENCE_MAX_BYTES) {
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }
    const bytes = readPinnedBytes(fd, identity.size);
    const sniffed = sniffEvidenceMediaType(bytes);
    if (sniffed === null) {
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }
    const extension = path.extname(absolutePath).toLowerCase();
    if (!extensionFor(sniffed).includes(extension)) {
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }
    const displayName = path.basename(absolutePath);
    if (displayName === '' || displayName === '.' || displayName === '..') {
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }
    return {
      realPath,
      displayName,
      size: identity.size,
      mtimeMs: identity.mtimeMs,
      dev: identity.dev,
      ino: identity.ino,
      candidateMediaType: sniffed,
    };
  });
}
