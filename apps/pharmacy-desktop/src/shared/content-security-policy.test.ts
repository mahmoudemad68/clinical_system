import { describe, expect, it } from 'vitest';
import { APP_CONFIG } from './app-config';
import {
  DEVELOPMENT_CONTENT_SECURITY_POLICY,
  cspContainsUnsafeEval,
  isEvalWebpackDevtool,
  packagedContentSecurityPolicy,
  rendererResponseSecurityHeaders,
} from './content-security-policy';
import { rendererConfig } from '../../webpack.renderer.config';
import { DEVELOPMENT_API_BASE_URL, resolveApiBaseUrl } from '../main/api-origin';

describe('Clinic Pharmacy — packaged vs development CSP', () => {
  const packaged = packagedContentSecurityPolicy();

  it('packaged CSP forbids eval, renderer connections, and remote script', () => {
    expect(packaged).toContain("default-src 'none'");
    expect(packaged).toContain("connect-src 'none'");
    expect(packaged).toContain("frame-ancestors 'none'");
    expect(packaged).toContain(`script-src 'self' ${APP_CONFIG.assetProtocolScheme}:`);
    expect(packaged).not.toContain("script-src 'unsafe-inline'");
    expect(cspContainsUnsafeEval(packaged)).toBe(false);
    expect(packaged).not.toContain('*');
    expect(packaged).not.toContain('blob:');
  });

  it('development CSP allows self scripts without unsafe-eval', () => {
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).toContain("script-src 'self'");
    expect(cspContainsUnsafeEval(DEVELOPMENT_CONTENT_SECURITY_POLICY)).toBe(false);
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).not.toContain("script-src 'unsafe-inline'");
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).not.toMatch(/script-src[^;]*data:/);
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).not.toMatch(/script-src[^;]*blob:/);
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).not.toMatch(/script-src[^;]*\*/);
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).toContain("style-src 'self' 'unsafe-inline'");
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).toContain('ws://127.0.0.1:*');
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).toContain('ws://localhost:*');
    expect(DEVELOPMENT_CONTENT_SECURITY_POLICY).not.toMatch(/(?:^|[;\s])ws:(?:[;\s]|$)/);
  });

  it('does not inject packaged CSP over Forge development responses', () => {
    const development = rendererResponseSecurityHeaders(false);
    const packagedHeaders = rendererResponseSecurityHeaders(true);

    expect(development['X-Content-Type-Options']).toEqual(['nosniff']);
    expect(development).not.toHaveProperty('Content-Security-Policy');

    expect(packagedHeaders['X-Content-Type-Options']).toEqual(['nosniff']);
    expect(packagedHeaders['Content-Security-Policy']).toEqual([packaged]);
  });
});

describe('Clinic Pharmacy — non-eval renderer webpack', () => {
  it('uses a non-eval source-map variant so script-src self can load', () => {
    expect(rendererConfig.devtool).toBe('source-map');
    expect(isEvalWebpackDevtool(rendererConfig.devtool)).toBe(false);
    expect(isEvalWebpackDevtool('eval')).toBe(true);
    expect(isEvalWebpackDevtool('eval-source-map')).toBe(true);
    expect(isEvalWebpackDevtool('eval-cheap-module-source-map')).toBe(true);
    expect(isEvalWebpackDevtool('cheap-module-source-map')).toBe(false);
    expect(isEvalWebpackDevtool('source-map')).toBe(false);
  });
});

describe('Clinic Pharmacy — local HTTP is development-only', () => {
  it('unpackaged development may use localhost HTTP; packaged cannot', () => {
    expect(
      resolveApiBaseUrl({
        configuredUrl: undefined,
        isPackaged: false,
        packagedAllowedOrigins: [],
      }),
    ).toBe(DEVELOPMENT_API_BASE_URL);

    expect(
      resolveApiBaseUrl({
        configuredUrl: DEVELOPMENT_API_BASE_URL,
        isPackaged: false,
        packagedAllowedOrigins: [],
      }),
    ).toBe(DEVELOPMENT_API_BASE_URL);

    expect(() =>
      resolveApiBaseUrl({
        configuredUrl: DEVELOPMENT_API_BASE_URL,
        isPackaged: true,
        packagedAllowedOrigins: ['https://api.example.com'],
      }),
    ).toThrow('INSECURE_TRANSPORT');
  });
});
