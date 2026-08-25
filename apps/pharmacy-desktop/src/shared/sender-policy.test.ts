import { describe, expect, it } from 'vitest';
import { isTrustedFrameOrigin } from './sender-policy';
import { APP_CONFIG } from './app-config';

/**
 * Behavioural tests for the IPC sender origin policy.
 *
 * These hand the policy hostile URLs and assert what it decides. That is the
 * difference between testing a control and testing that a file mentions a
 * control — a distinction this suite learned the hard way when an earlier
 * substring assertion matched a comment.
 */
describe('Clinic Pharmacy — IPC sender origin policy', () => {
  const ORIGIN = APP_CONFIG.packagedOrigin;
  const PACKAGED = true;
  const UNPACKAGED = false;

  it('accepts the exact packaged origin', () => {
    expect(isTrustedFrameOrigin(`${ORIGIN}/`, ORIGIN, PACKAGED)).toBe(true);
    expect(isTrustedFrameOrigin(`${ORIGIN}/index.html`, ORIGIN, PACKAGED)).toBe(true);
  });

  it('rejects a different host under the same scheme', () => {
    // The regression this replaced: comparing only `url.protocol` admits any
    // host under the scheme, which is not the window the app serves.
    const scheme = APP_CONFIG.assetProtocolScheme;

    expect(isTrustedFrameOrigin(`${scheme}://evil/`, ORIGIN, PACKAGED)).toBe(false);
    expect(isTrustedFrameOrigin(`${scheme}://attacker.example/`, ORIGIN, PACKAGED)).toBe(false);
    expect(isTrustedFrameOrigin(`${scheme}://-.evil/`, ORIGIN, PACKAGED)).toBe(false);
  });

  it('rejects the sibling application origin', () => {
    const sibling = 'clinic-doctor-app://-';

    expect(sibling).not.toBe(ORIGIN);
    expect(isTrustedFrameOrigin(`${sibling}/`, ORIGIN, PACKAGED)).toBe(false);
  });

  it('rejects localhost in a packaged build', () => {
    // The previous implementation allowed localhost unconditionally behind a
    // comment claiming it was unreachable when packaged. Nothing enforced that.
    for (const url of [
      'http://localhost:3000/',
      'https://localhost/',
      'http://127.0.0.1:9000/',
      'http://127.0.0.1/index.html',
    ]) {
      expect(isTrustedFrameOrigin(url, ORIGIN, PACKAGED)).toBe(false);
    }
  });

  it('allows only loopback dev-server origins when genuinely unpackaged', () => {
    expect(isTrustedFrameOrigin('http://localhost:3000/', ORIGIN, UNPACKAGED)).toBe(true);
    expect(isTrustedFrameOrigin('http://127.0.0.1:3000/', ORIGIN, UNPACKAGED)).toBe(true);

    // Not any http origin — only loopback.
    expect(isTrustedFrameOrigin('http://evil.example/', ORIGIN, UNPACKAGED)).toBe(false);
    expect(isTrustedFrameOrigin('https://attacker.invalid/', ORIGIN, UNPACKAGED)).toBe(false);
  });

  it('is not fooled by hostnames that merely contain localhost', () => {
    for (const url of [
      'http://localhost.evil.example/',
      'http://notlocalhost/',
      'http://127.0.0.1.evil.example/',
    ]) {
      expect(isTrustedFrameOrigin(url, ORIGIN, UNPACKAGED)).toBe(false);
    }
  });

  it('rejects dangerous and malformed schemes outright', () => {
    for (const url of [
      'file:///etc/passwd',
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      'about:blank',
      'chrome://settings',
      '',
      'not a url',
      '///',
    ]) {
      expect(isTrustedFrameOrigin(url, ORIGIN, PACKAGED)).toBe(false);
      expect(isTrustedFrameOrigin(url, ORIGIN, UNPACKAGED)).toBe(false);
    }
  });

  it('rejects a credentialed or port-shifted variant of the packaged origin', () => {
    const scheme = APP_CONFIG.assetProtocolScheme;

    expect(isTrustedFrameOrigin(`${scheme}://user:pass@-/`, ORIGIN, PACKAGED)).toBe(false);
    expect(isTrustedFrameOrigin(`${scheme}://-:8080/`, ORIGIN, PACKAGED)).toBe(false);
  });
});
