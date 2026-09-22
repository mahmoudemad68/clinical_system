import { APP_CONFIG } from './app-config';

/**
 * Content-Security-Policy helpers for Clinic Pharmacy.
 *
 * Packaged renderer content keeps the strict custom-scheme policy. Forge
 * development uses a separate header, set by Electron Forge on the webpack
 * dev server. The main process must not overwrite that development header
 * with the packaged policy — that collision blanks the renderer
 * (eval-source-map + packaged script-src without 'unsafe-eval').
 */

export function packagedContentSecurityPolicy(
  scheme: string = `${APP_CONFIG.assetProtocolScheme}:`,
): string {
  return [
    `default-src 'none'`,
    `script-src 'self' ${scheme}`,
    `style-src 'self' ${scheme} 'unsafe-inline'`,
    `img-src 'self' ${scheme} data:`,
    `font-src 'self' ${scheme} data:`,
    `connect-src 'none'`,
    `object-src 'none'`,
    `frame-src 'none'`,
    `frame-ancestors 'none'`,
    `base-uri 'none'`,
    `form-action 'none'`,
  ].join('; ');
}

/**
 * Forge webpack-dev-server CSP.
 *
 * Scripts are `'self'` only. Webpack development must not rely on eval.
 * `style-src 'unsafe-inline'` is required by current MUI/Emotion runtime
 * injection (same as packaged). WebSocket is scoped to loopback for HMR.
 */
export const DEVELOPMENT_CONTENT_SECURITY_POLICY = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data:",
  "font-src 'self' data:",
  "connect-src 'self' ws://127.0.0.1:* ws://localhost:*",
  "object-src 'none'",
  "frame-src 'none'",
  "base-uri 'none'",
  "form-action 'none'",
].join('; ');

/**
 * Extra response headers the main process may attach.
 *
 * Packaged: authoritative CSP + nosniff.
 * Development: nosniff only, so Forge's `devContentSecurityPolicy` remains.
 */
export function rendererResponseSecurityHeaders(isPackaged: boolean): Record<string, string[]> {
  const headers: Record<string, string[]> = {
    'X-Content-Type-Options': ['nosniff'],
  };

  if (isPackaged) {
    headers['Content-Security-Policy'] = [packagedContentSecurityPolicy()];
  }

  return headers;
}

export function cspContainsUnsafeEval(policy: string): boolean {
  return /(?:^|[;\s])'unsafe-eval'(?:[;\s]|$)/.test(policy);
}

export function isEvalWebpackDevtool(devtool: unknown): boolean {
  if (typeof devtool !== 'string' || devtool.length === 0) {
    return false;
  }

  return /(^|\b)eval(-|$)/.test(devtool) || devtool.includes('eval-');
}
