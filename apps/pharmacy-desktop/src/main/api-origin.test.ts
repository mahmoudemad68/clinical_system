import { describe, expect, it } from 'vitest';
import {
  DEVELOPMENT_API_BASE_URL,
  parsePackagedAllowlistEntries,
  resolveApiBaseUrl,
} from './api-origin';

/**
 * Clinic Pharmacy packaged API origin policy.
 *
 * These hand the resolver hostile URLs. A substring assertion cannot tell a
 * real allowlist from a comment that mentions one.
 */
describe('Clinic Pharmacy — packaged API exact-origin allowlist', () => {
  const APPROVED = 'https://pharmacy.example.com';
  const SIBLING = 'https://api.example.com';
  const PACKAGED = true;
  const UNPACKAGED = false;

  function resolve(
    configuredUrl: string | undefined,
    isPackaged: boolean,
    packagedAllowedOrigins: readonly string[] = [APPROVED],
  ): string {
    return resolveApiBaseUrl({
      configuredUrl,
      isPackaged,
      packagedAllowedOrigins,
    });
  }

  it('A. accepts the approved exact HTTPS origin', () => {
    expect(resolve(APPROVED, PACKAGED)).toBe(APPROVED);
    expect(resolve(`${APPROVED}/`, PACKAGED)).toBe(APPROVED);
    expect(resolve('https://pharmacy.example.com:443', PACKAGED)).toBe(APPROVED);
  });

  it('B. denies an arbitrary HTTPS origin', () => {
    expect(() => resolve('https://evil.example', PACKAGED)).toThrow('ORIGIN_REFUSED');
  });

  it('C. denies HTTP even when the hostname is the approved host', () => {
    expect(() => resolve('http://pharmacy.example.com', PACKAGED)).toThrow('INSECURE_TRANSPORT');
  });

  it('D. denies localhost HTTP when packaged', () => {
    expect(() => resolve(DEVELOPMENT_API_BASE_URL, PACKAGED)).toThrow('INSECURE_TRANSPORT');
    expect(() => resolve('http://127.0.0.1:8080', PACKAGED)).toThrow('INSECURE_TRANSPORT');
  });

  it('E. denies localhost HTTPS unless that origin is explicitly baked', () => {
    expect(() => resolve('https://localhost', PACKAGED)).toThrow('ORIGIN_REFUSED');
    expect(resolve('https://localhost', PACKAGED, ['https://localhost'])).toBe('https://localhost');
  });

  it('F. denies a lookalike hostname', () => {
    expect(() => resolve('https://pharmacy.example.com.evil.test', PACKAGED)).toThrow('ORIGIN_REFUSED');
  });

  it('G. denies a credentialed URL even when the host would match', () => {
    expect(() => resolve('https://user:pass@pharmacy.example.com', PACKAGED)).toThrow('ORIGIN_REFUSED');
  });

  it('H. denies a wrong explicit port', () => {
    expect(() => resolve('https://pharmacy.example.com:444', PACKAGED)).toThrow('ORIGIN_REFUSED');
  });

  it('I. fails closed when the packaged allowlist is missing', () => {
    expect(() => resolve(APPROVED, PACKAGED, [])).toThrow('PACKAGED_ALLOWLIST_MISSING');
    expect(() => resolve(undefined, PACKAGED, [])).toThrow('PACKAGED_ALLOWLIST_MISSING');
  });

  it('J. keeps development localhost HTTP usable when unpackaged', () => {
    expect(resolve(undefined, UNPACKAGED, [])).toBe(DEVELOPMENT_API_BASE_URL);
    expect(resolve(DEVELOPMENT_API_BASE_URL, UNPACKAGED, [])).toBe(DEVELOPMENT_API_BASE_URL);
  });

  it('does not trust the sibling desktop packaged origin', () => {
    expect(() => resolve(SIBLING, PACKAGED, [APPROVED])).toThrow('ORIGIN_REFUSED');
    expect(resolve(SIBLING, PACKAGED, [SIBLING])).toBe(SIBLING);
  });

  it('rejects unexpected path, query, hash, and malformed values', () => {
    expect(() => resolve('https://pharmacy.example.com/v1', PACKAGED)).toThrow('ORIGIN_REFUSED');
    expect(() => resolve('https://pharmacy.example.com?x=1', PACKAGED)).toThrow('ORIGIN_REFUSED');
    expect(() => resolve('https://pharmacy.example.com#frag', PACKAGED)).toThrow('ORIGIN_REFUSED');
    expect(() => resolve('not a url', PACKAGED)).toThrow('ORIGIN_REFUSED');
    expect(() => resolve('https://pharmacy.example.com', PACKAGED)).not.toThrow();
  });

  it('does not let runtime env redefine the baked packaged allowlist', () => {
    process.env['CLINIC_API_BASE_URL'] = 'https://evil.example';
    process.env['CLINIC_API_ALLOWED_ORIGINS'] = 'https://evil.example';
    process.env['CLINIC_PHARMACY_PACKAGED_API_ALLOWED_ORIGINS'] = 'https://evil.example';
    process.env['CLINIC_PACKAGED_API_ALLOWED_ORIGINS'] = 'https://evil.example';

    expect(() =>
      resolveApiBaseUrl({
        configuredUrl: process.env['CLINIC_API_BASE_URL'],
        isPackaged: true,
        packagedAllowedOrigins: [],
      }),
    ).toThrow('PACKAGED_ALLOWLIST_MISSING');

    expect(() =>
      resolveApiBaseUrl({
        configuredUrl: process.env['CLINIC_API_BASE_URL'],
        isPackaged: true,
        packagedAllowedOrigins: [APPROVED],
      }),
    ).toThrow('ORIGIN_REFUSED');
  });

  it('treats an empty build-time env as an empty allowlist and rejects invalid bake entries', () => {
    expect(parsePackagedAllowlistEntries('')).toEqual([]);
    expect(parsePackagedAllowlistEntries('https://pharmacy.example.com')).toEqual([
      'https://pharmacy.example.com',
    ]);
    expect(() => parsePackagedAllowlistEntries('http://pharmacy.example.com')).toThrow(
      'PACKAGED_ALLOWLIST_INVALID',
    );
    expect(() => parsePackagedAllowlistEntries('https://user:pass@pharmacy.example.com')).toThrow(
      'PACKAGED_ALLOWLIST_INVALID',
    );
  });
});
