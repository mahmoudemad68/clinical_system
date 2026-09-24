import { afterEach, describe, expect, it } from 'vitest';
import { mkdtempSync, writeFileSync, unlinkSync, mkdirSync, symlinkSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import {
  EvidenceFileError,
  PharmacyEvidenceHandleStore,
  evidenceOpenDescriptorCount,
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

const MIN_JPEG = Buffer.from([0xff, 0xd8, 0xff, 0xdb, 0x00, 0x43, 0x00, 0x00]);
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
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license');
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

  it('binds the handle to an approved requirement and fails closed for unknown codes', () => {
    const dir = scratch();
    const license = path.join(dir, 'license.pdf');
    const register = path.join(dir, 'register.pdf');
    writeFileSync(license, MIN_PDF);
    writeFileSync(register, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    expect(() => store.registerSelectedFile(license, 'organization_registration_evidence')).toThrowError(
      /INVALID_REQUEST/,
    );
    expect(() => store.registerSelectedFile(license, 'fabricated_licence')).toThrowError(/INVALID_REQUEST/);
    const first = store.registerSelectedFile(license, 'pharmacy_facility_license');
    const second = store.registerSelectedFile(register, 'commercial_register');
    if (!first.selected || !second.selected) {
      throw new Error('expected selections');
    }
    expect(first.requirementCode).toBe('pharmacy_facility_license');
    expect(second.requirementCode).toBe('commercial_register');
    expect(store.readForUpload(first.handleId).requirementCode).toBe('pharmacy_facility_license');
    expect(store.readForUpload(second.handleId).requirementCode).toBe('commercial_register');
  });

  it('rejects a deleted selected file on upload revalidation', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license');
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
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license');
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
    const selected = store.registerSelectedFile(original, 'pharmacy_facility_license');
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
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license', 1);
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    expect(() => store.readForUpload(selected.handleId, 1 + 16 * 60 * 1000)).toThrowError(/FILE_MISSING/);
  });

  it('accepts pdf, jpeg, and png selected files and reads the original bytes', () => {
    const dir = scratch();
    const store = new PharmacyEvidenceHandleStore();
    const pdf = path.join(dir, 'registration.pdf');
    const jpeg = path.join(dir, 'stamp.jpg');
    const png = path.join(dir, 'stamp.png');
    writeFileSync(pdf, MIN_PDF);
    writeFileSync(jpeg, MIN_JPEG);
    writeFileSync(png, MIN_PNG);

    const selectedPdf = store.registerSelectedFile(pdf, 'pharmacy_facility_license');
    if (!selectedPdf.selected) {
      throw new Error('expected pdf selection');
    }
    const pdfBytes = store.readForUpload(selectedPdf.handleId);
    expect(pdfBytes.candidateMediaType).toBe('application/pdf');
    expect(pdfBytes.bytes.equals(MIN_PDF)).toBe(true);

    const selectedJpeg = store.registerSelectedFile(jpeg, 'commercial_register');
    if (!selectedJpeg.selected) {
      throw new Error('expected jpeg selection');
    }
    expect(store.readForUpload(selectedJpeg.handleId).candidateMediaType).toBe('image/jpeg');

    const selectedPng = store.registerSelectedFile(png, 'responsible_pharmacist_license');
    if (!selectedPng.selected) {
      throw new Error('expected png selection');
    }
    expect(store.readForUpload(selectedPng.handleId).bytes.equals(MIN_PNG)).toBe(true);
    expect(evidenceOpenDescriptorCount()).toBe(0);
  });

  it('closes the descriptor after a deleted-file failure', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license');
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    unlinkSync(file);
    expect(() => store.readForUpload(selected.handleId)).toThrow(EvidenceFileError);
    expect(evidenceOpenDescriptorCount()).toBe(0);
  });

  it('reads the pinned inode when the pathname is replaced after open', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    const decoy = Buffer.from(MIN_PDF);
    decoy[decoy.byteLength - 2] = 0x41;
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license');
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    const uploaded = store.readForUpload(selected.handleId, Date.now(), ({ absolutePath }) => {
      unlinkSync(absolutePath);
      writeFileSync(absolutePath, decoy);
    });
    expect(uploaded.bytes.equals(MIN_PDF)).toBe(true);
    expect(uploaded.bytes.equals(decoy)).toBe(false);
    expect(evidenceOpenDescriptorCount()).toBe(0);
  });

  it('invalidates a handle after use so a second read fails', () => {
    const dir = scratch();
    const file = path.join(dir, 'registration.pdf');
    writeFileSync(file, MIN_PDF);
    const store = new PharmacyEvidenceHandleStore();
    const selected = store.registerSelectedFile(file, 'pharmacy_facility_license');
    if (!selected.selected) {
      throw new Error('expected selection');
    }
    store.readForUpload(selected.handleId);
    store.invalidate(selected.handleId);
    expect(() => store.readForUpload(selected.handleId)).toThrowError(/FILE_MISSING/);
    expect(evidenceOpenDescriptorCount()).toBe(0);
  });
});
