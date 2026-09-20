#!/usr/bin/env node
/**
 * Copy the current process environment into apps/core-api/.env for keys the
 * Admin browser E2E job already exported. `php artisan serve` only forwards a
 * short whitelist to its PHP child, so SESSION_DRIVER/DB_* from GitHub Actions
 * otherwise never reach the HTTP process. Values are never printed.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const envPath = resolve('apps/core-api/.env');
const keys = [
  'APP_ENV',
  'APP_URL',
  'SESSION_DRIVER',
  'SESSION_COOKIE',
  'SESSION_SAME_SITE',
  'CACHE_STORE',
  'QUEUE_CONNECTION',
  'AUTH_RATE_LIMIT_DRIVER',
  'DB_HOST',
  'DB_DATABASE',
  'DB_USERNAME',
  'DB_PASSWORD',
  'DB_MIGRATION_USERNAME',
  'DB_MIGRATION_PASSWORD',
  'DB_WORKER_USERNAME',
  'DB_WORKER_PASSWORD',
  'REDIS_HOST',
  'IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS',
  'FEATURE_AUTH_REGISTRATION',
  'CLINIC_REQUIRE_OBJECT_STORE',
  'CLINIC_REQUIRE_CLAMAV',
  'CLAMAV_HOST',
  'SANCTUM_STATEFUL_DOMAINS',
  'CORS_ALLOWED_ORIGINS',
];

function quote(value) {
  if (/^[A-Za-z0-9_./:@-]+$/.test(value)) {
    return value;
  }

  return `"${value.replaceAll('\\', '\\\\').replaceAll('"', '\\"')}"`;
}

const overrides = {};
for (const key of keys) {
  const value = process.env[key];
  if (typeof value === 'string' && value !== '') {
    overrides[key] = value;
  }
}

const existing = readFileSync(envPath, 'utf8').split(/\r?\n/);
const seen = new Set();
const next = [];

for (const line of existing) {
  const match = /^([A-Z][A-Z0-9_]*)=/.exec(line);
  if (match && Object.hasOwn(overrides, match[1])) {
    next.push(`${match[1]}=${quote(overrides[match[1]])}`);
    seen.add(match[1]);
    continue;
  }
  next.push(line);
}

for (const key of keys) {
  if (Object.hasOwn(overrides, key) && !seen.has(key)) {
    next.push(`${key}=${quote(overrides[key])}`);
  }
}

writeFileSync(envPath, `${next.join('\n').replace(/\n*$/, '\n')}`);
process.stderr.write(`Synced ${String(Object.keys(overrides).length)} Core API E2E env keys into .env\n`);
