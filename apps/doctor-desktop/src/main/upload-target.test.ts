import { describe, expect, it } from 'vitest';
import { parseIssuedUploadTarget, UploadTargetError } from './upload-target';

const CANARY_SIGNATURE = 'CANARY-SIGNATURE';
const CANARY_LOCATOR = 'verification/q/canary-object-key';

describe('issued upload target validation', () => {
  it('accepts a Core-issued HTTPS PUT target', () => {
    const target = parseIssuedUploadTarget(
      {
        method: 'PUT',
        url: 'https://objects.example/upload',
        headers: { 'Content-Type': 'application/pdf' },
        expires_at: '2026-09-21T00:00:00Z',
      },
      true,
    );
    expect(target.method).toBe('PUT');
    expect(target.url).toBe('https://objects.example/upload');
  });

  it('rejects file, javascript, and credentialed schemes', () => {
    expect(() => parseIssuedUploadTarget({ method: 'PUT', url: 'file:///tmp/x' }, false)).toThrow(
      UploadTargetError,
    );
    expect(() =>
      parseIssuedUploadTarget({ method: 'PUT', url: 'javascript:alert(1)' }, false),
    ).toThrow(UploadTargetError);
    expect(() =>
      parseIssuedUploadTarget({ method: 'PUT', url: 'https://user:pass@evil.example/x' }, true),
    ).toThrow(UploadTargetError);
  });

  it('rejects packaged HTTP and renderer-looking extra fields do not leak', () => {
    expect(() =>
      parseIssuedUploadTarget({ method: 'PUT', url: 'http://objects.example/upload' }, true),
    ).toThrow(UploadTargetError);
    try {
      parseIssuedUploadTarget(
        {
          method: 'GET',
          url: `https://objects.example/upload?X-Amz-Signature=${CANARY_SIGNATURE}`,
        },
        true,
      );
    } catch (error) {
      expect(JSON.stringify(error)).not.toContain(CANARY_SIGNATURE);
      expect(JSON.stringify(error)).not.toContain(CANARY_LOCATOR);
    }
  });

  it('allows unpackaged localhost HTTP for the emulator', () => {
    const target = parseIssuedUploadTarget(
      { method: 'PUT', url: 'http://127.0.0.1:9000/bucket/object' },
      false,
    );
    expect(target.url.startsWith('http://127.0.0.1:9000/')).toBe(true);
  });
});
