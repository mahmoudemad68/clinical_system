/**
 * Opaque pharmacy verification evidence handles.
 *
 * The renderer receives only a short-lived handle id and safe metadata. The
 * absolute path never crosses IPC. Bytes are read in this process after a
 * TOCTOU revalidation immediately before upload.
 */

import { randomBytes } from 'node:crypto';
import {
  lstatSync,
  readFileSync,
  realpathSync,
  type Stats,
} from 'node:fs';
import path from 'node:path';
import type { PharmacyEvidenceSelectResponse } from '@clinic/desktop-bridge-contracts';

export const EVIDENCE_MAX_BYTES = 20 * 1024 * 1024;
export const EVIDENCE_HANDLE_TTL_MS = 15 * 60 * 1000;
export const EVIDENCE_REQUIREMENT_CODE = 'organization_registration_evidence' as const;

export type EvidenceMediaType = 'application/pdf' | 'image/jpeg' | 'image/png';

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

export class PharmacyEvidenceHandleStore {
  private readonly handles = new Map<string, HandleRecord>();

  clear(): void {
    this.handles.clear();
  }

  clearHandle(handleId: string): boolean {
    return this.handles.delete(handleId);
  }

  registerSelectedFile(absolutePath: string, now = Date.now()): PharmacyEvidenceSelectResponse {
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

  /**
   * Re-stat the selected file and read bytes. The absolute path stays in this
   * process; callers must not put it on an IPC payload.
   */
  readForUpload(handleId: string, now = Date.now()): {
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

    const identity = identityFromStats(stats);
    if (
      identity.size !== record.size ||
      identity.mtimeMs !== record.mtimeMs ||
      identity.dev !== record.dev ||
      identity.ino !== record.ino
    ) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_CHANGED');
    }

    if (identity.size > EVIDENCE_MAX_BYTES || identity.size <= 0) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('UNSUPPORTED_FILE');
    }

    const bytes = readFileSync(record.absolutePath);
    if (bytes.byteLength !== record.size) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_CHANGED');
    }

    const sniffed = sniffEvidenceMediaType(bytes);
    if (sniffed !== record.candidateMediaType) {
      this.handles.delete(handleId);
      throw new EvidenceFileError('FILE_CHANGED');
    }

    return {
      bytes,
      sizeBytes: record.size,
      candidateMediaType: record.candidateMediaType,
      displayName: record.displayName,
    };
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

  const fdBytes = readFileSync(absolutePath);
  const sniffed = sniffEvidenceMediaType(fdBytes);
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

  const identity = identityFromStats(stats);
  return {
    realPath,
    displayName,
    size: identity.size,
    mtimeMs: identity.mtimeMs,
    dev: identity.dev,
    ino: identity.ino,
    candidateMediaType: sniffed,
  };
}
