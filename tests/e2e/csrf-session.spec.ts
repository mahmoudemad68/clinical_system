import { expect, test } from '@playwright/test';

/**
 * Browser CSRF / session-cookie evidence for the Inertia admin login surface.
 * Synthetic credentials only. Does not print secrets.
 */
test.describe('admin cookie csrf', () => {
  test('login page sets a session cookie and an xsrf cookie', async ({ page }) => {
    const response = await page.goto('/login');
    expect(response?.ok()).toBeTruthy();

    const cookies = await page.context().cookies();
    const names = cookies.map((cookie) => cookie.name);
    expect(names).toContain('XSRF-TOKEN');
    expect(names.some((name) => name.includes('session') || name === 'clinic_session')).toBeTruthy();
  });

  test('inertia login without a csrf token is rejected', async ({ page }) => {
    await page.goto('/login');
    const response = await page.request.post('/login', {
      form: {
        phone: '01900000001',
        password: 'correct-horse-battery',
      },
      headers: { Accept: 'text/html, application/json' },
    });
    expect(response.status()).toBe(419);
  });

  test('a csrf token from one browser session is rejected in another', async ({ browser, baseURL }) => {
    const first = await browser.newContext();
    const second = await browser.newContext();
    const pageA = await first.newPage();
    const pageB = await second.newPage();

    await pageA.goto('/login');
    await pageB.goto('/login');

    const tokenA = (await first.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;
    expect(tokenA).toBeTruthy();

    const forged = await pageB.request.post('/login', {
      form: {
        phone: '01900000001',
        password: 'correct-horse-battery',
      },
      headers: {
        Accept: 'text/html, application/json',
        'X-XSRF-TOKEN': decodeURIComponent(tokenA ?? ''),
      },
    });
    expect([419, 403]).toContain(forged.status());

    await first.close();
    await second.close();
  });

  test('api login after a browser visit without csrf is CSRF_MISMATCH', async ({ page }) => {
    await page.goto('/login');
    const response = await page.request.post('/api/v1/auth/login', {
      data: {
        phone: '01900000001',
        password: 'correct-horse-battery',
        client_class: 'admin_web',
        platform: 'web',
        device_label: 'playwright',
      },
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    });
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body?.errors?.[0]?.code).toBe('CSRF_MISMATCH');
  });
});
