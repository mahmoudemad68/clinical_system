import { defineConfig } from '@playwright/test';

const laravelBaseURL = process.env.CLINIC_WEB_BASE_URL || 'http://127.0.0.1:8080';
const adminWebBaseURL = process.env.CLINIC_ADMIN_WEB_BASE_URL || 'http://127.0.0.1:4173';

export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  retries: 0,
  fullyParallel: false,
  workers: 1,
  projects: [
    {
      name: 'csrf',
      testMatch: 'csrf-session.spec.ts',
      use: {
        baseURL: laravelBaseURL,
        extraHTTPHeaders: {
          Accept: 'text/html,application/json',
        },
      },
    },
    {
      name: 'admin-verification',
      testMatch: 'admin-verification.spec.ts',
      timeout: 90_000,
      use: {
        baseURL: adminWebBaseURL,
        extraHTTPHeaders: {
          Accept: 'text/html,application/json',
        },
      },
    },
  ],
});
