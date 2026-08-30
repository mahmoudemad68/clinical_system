/**
 * Compile-time packaged API exact-origin allowlist.
 *
 * Webpack `DefinePlugin` replaces `__CLINIC_PACKAGED_API_ALLOWED_ORIGINS__`
 * when the main-process bundle is built. That value comes from the
 * Doctor-only build-time env `CLINIC_DOCTOR_PACKAGED_API_ALLOWED_ORIGINS`.
 *
 * This module must not read `process.env`. Runtime
 * `CLINIC_API_BASE_URL` / `CLINIC_API_ALLOWED_ORIGINS` cannot add a trusted
 * origin: they never appear here. An empty list fails closed when packaged.
 */

declare const __CLINIC_PACKAGED_API_ALLOWED_ORIGINS__: readonly string[] | undefined;

export const PACKAGED_API_ALLOWED_ORIGINS: readonly string[] =
  typeof __CLINIC_PACKAGED_API_ALLOWED_ORIGINS__ === 'undefined'
    ? []
    : __CLINIC_PACKAGED_API_ALLOWED_ORIGINS__;
