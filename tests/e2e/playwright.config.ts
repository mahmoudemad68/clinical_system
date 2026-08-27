import { defineConfig } from '@playwright/test';

const baseURL = process.env.CLINIC_WEB_BASE_URL || 'http://127.0.0.1:8080';

export default defineConfig({
  testDir: '.',
  testMatch: 'csrf-session.spec.ts',
  timeout: 30_000,
  retries: 0,
  use: {
    baseURL,
    extraHTTPHeaders: {
      Accept: 'text/html,application/json',
    },
  },
});
