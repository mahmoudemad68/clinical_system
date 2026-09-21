import { afterEach, describe, expect, it } from 'vitest';
import { mkdtempSync, writeFileSync, unlinkSync, mkdirSync, symlinkSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import {
  EvidenceFileError,
  PharmacyEvidenceHandleStore,
  inspectCandidateFile,
} from './evidence-handles';

const MIN_PDF = Buffer.from(
  '%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n',
  'utf8',
);
const MIN_PNG = Buffer.from([
  0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d, 0x49, 0x48, 0x44, 0x52,
  0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01, 0x08, 0x02, 0x00, 0x00, 0x00, 0x90, 0x77, 0x53,
  0xde, 0x00, 0x00, 0x00, 0x00, 0x49, 0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82,
]);

const CANARY_PATH = '/tmp/clinic-canary-evidence.pdf';
const CANARY_URL = 'https://objects.example/upload?X-Amz-Signature=CANARY-SIGNATURE';

describe('pharmacy evidence handles', () => {
  const roots: string[] = [];

  afterEach(() => {
    for (const root of roots.splice(0)) {
      rmSync(root, { recursive: true, force: true });
    }
  });

  function scratch(): string {
    const dir = mkdtempSync(path.join(tmpdir(), 'clinic-evidence-'));
    roots.push(dir);
    return dir;
  }

  it('returns an opaque handle and never includes the absolute path', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file);
    expect(selected.selected).toBe(true);
    if (!selected.selected) {
      return;
    }
    expect(selected.displayName).toBe('registration.pdf');
    expect(selected.candidateMediaType).toBe('application/pdf');
    expect(JSON.stringify(selected)).not.toContain(file);
    expect(JSON.stringify(selected)).not.toContain(dir);
    expect(JSON.stringify(selected)).not.toContain(CANARY_PATH);
    expect(JSON.stringify(selected)).not.toContain(CANARY_URL);
  });

  it('rejects a deleted selected file on upload revalidation', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file);
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    unlinkSync(file);
    expect(() => store.readForUpload(selected.handleId)).toThrow(EvidenceFileError);
    try {
      store.readForUpload(selected.handleId);
    } catch (error) {
      expect(error).toBeInstanceOf(EvidenceFileError);
      expect((error as EvidenceFileError).code).toBe('FILE_MISSING');
    }
  });

  it('rejects a changed size after selection', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file);
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    writeFileSync(file, Buffer.concat([MIN_PDF, Buffer.from('\n%changed\n')]));
    expect(() => store.readForUpload(selected.handleId)).toThrowError(/FILE_CHANGED/);
  });

  it('rejects an unsupported text file', () => {
    const dir = scratch();
    const file = path.join(dir, 'notes.txt');
    writeFileSync(file, 'hello');
    expect(() => inspectCandidateFile(file)).toThrowError(/UNSUPPORTED_FILE/);
  });

  it('rejects a directory', () => {
    const dir = scratch();
    mkdirSync(path.join(dir, 'folder'));
    expect(() => inspectCandidateFile(path.join(dir, 'folder'))).toThrowError(/UNSUPPORTED_FILE/);
  });

  it('rejects an oversize file', () => {
    const dir = scratch();
    const file = path.join(dir, 'huge.pdf');
    writeFileSync(file, Buffer.concat([MIN_PDF, Buffer.alloc(20 * 1024 * 1024 + 1)]));
    expect(() => inspectCandidateFile(file)).toThrowError(/UNSUPPORTED_FILE/);
  });

  it('rejects a symlink substitution after selection when the platform supports it', () => {
    const dir = scratch();
    const original = path.join(dir, 'registration.pdf');
    const decoy = path.join(dir, 'decoy.pdf');
    writeFileSync(original, MIN_PDF);
    writeFileSync(decoy, Buffer.concat([MIN_PDF, Buffer.from('%decoy')]));
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(original);
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    unlinkSync(original);
    try {
      symlinkSync(decoy, original);
    } catch {
      return;
    }
    expect(() => store.readForUpload(selected.handleId)).toThrowError(/FILE_CHANGED|FILE_MISSING|UNSUPPORTED_FILE/);
  });

  it('accepts a png evidence candidate', () => {
    const dir = scratch();
    const file = path.join(dir, 'stamp.png');
    writeFileSync(file, MIN_PNG);
    const inspected = inspectCandidateFile(file);
    expect(inspected.candidateMediaType).toBe('image/png');
    expect(inspected.displayName).toBe('stamp.png');
  });

  it('expires a stale handle', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file, 1);
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    expect(() => store.readForUpload(selected.handleId, 1 + 16 * 60 * 1000)).toThrowError(/FILE_MISSING/);
  });

  it('invalidates a handle after use so a second read fails', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file);
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    store.readForUpload(selected.handleId);
    store.invalidate(selected.handleId);
    expect(() => store.readForUpload(selected.handleId)).toThrowError(/FILE_MISSING/);
  });
});
