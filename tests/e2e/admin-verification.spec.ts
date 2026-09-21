import { existsSync, readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { totpCode } from './totp';

type FixtureFile = {
  reviewer: { phone: string; password: string; totp_secret: string };
  unauthorized: { phone: string; password: string; totp_secret: string };
  case: { professional_display_name: string };
  pharmacy_case: { public_name: string };
  canaries: {
    legal_name: string;
    registration: string;
    address: string;
    phone: string;
  };
};

function loadFixture(): FixtureFile {
  const path = process.env.CLINIC_E2E_ADMIN_FIXTURE || '/tmp/clinic-e2e-admin-verification.json';
  if (!existsSync(path)) {
    throw new Error('Admin verification browser fixture is missing.');
  }

  return JSON.parse(readFileSync(path, 'utf8')) as FixtureFile;
}

async function waitForSessionReady(page: Page): Promise<void> {
  await page.getByText(/Checking your session|جارٍ التحقق من جلستك/).waitFor({ state: 'hidden', timeout: 20_000 }).catch(() => undefined);
}

async function ensureSignedOut(page: Page): Promise<void> {
  await waitForSessionReady(page);
  const signOut = page.getByRole('button', { name: /Sign out|خروج/ });
  if (await signOut.isVisible().catch(() => false)) {
    const logoutWait = page.waitForResponse(
      (response) => response.request().method() === 'POST' && response.url().includes('/api/v1/auth/logout'),
      { timeout: 20_000 },
    );
    await signOut.click();
    const logoutResponse = await logoutWait;
    expect(logoutResponse.ok(), `logout HTTP ${String(logoutResponse.status())}`).toBeTruthy();
  }
  await page.context().clearCookies();
  await page.goto('/');
  await waitForSessionReady(page);
  await page.getByRole('heading', { name: /Admin sign in|دخول المسؤول/ }).waitFor({ timeout: 20_000 });
  await page.getByRole('textbox', { name: /Mobile number|رقم الجوال/ }).waitFor({ timeout: 20_000 });
}

async function signIn(page: Page, phone: string, password: string, totpSecret: string): Promise<void> {
  await page.goto('/');
  await ensureSignedOut(page);
  await page.getByRole('textbox', { name: /Mobile number|رقم الجوال/ }).fill(phone);
  await page.getByRole('textbox', { name: /Password|كلمة المرور/ }).fill(password);
  await page.getByRole('button', { name: /Sign in|دخول/ }).click();

  const mfa = page.getByRole('textbox', { name: /Authenticator code|رمز التحقق/ });
  const unauthorized = page.getByRole('heading', { name: /Verification review is not available|مراجعة التحقق غير متاحة/ });
  const queue = page.getByRole('heading', { name: /Pending doctor verification|تحقق الأطباء المعلّق/ });
  await Promise.race([
    mfa.waitFor({ timeout: 20_000 }),
    unauthorized.waitFor({ timeout: 20_000 }),
    queue.waitFor({ timeout: 20_000 }),
  ]);

  if (await mfa.isVisible()) {
    await mfa.fill(totpCode(totpSecret));
    await page.getByRole('button', { name: /Sign in|دخول/ }).click();
  }
}

test.describe('admin verification review', () => {
  test('unauthorized actor cannot fetch the queue; reviewer can claim, grant, decide, and log out', async ({
    page,
  }) => {
    const fixture = loadFixture();
    const queueHits: string[] = [];
    const accessPosts: string[] = [];
    const decisionPosts: string[] = [];

    page.on('request', (request) => {
      const url = request.url();
      if (request.method() === 'GET' && url.includes('/api/v1/admin/verification-cases')) {
        queueHits.push('queue');
      }
      if (request.method() === 'POST' && url.includes('/documents/') && url.includes('/access')) {
        accessPosts.push('access');
      }
      if (request.method() === 'POST' && url.includes('/decisions')) {
        decisionPosts.push(request.headers()['idempotency-key'] ?? '');
      }
    });

    await signIn(page, fixture.unauthorized.phone, fixture.unauthorized.password, fixture.unauthorized.totp_secret);
    await expect(page.getByRole('heading', { name: /Verification review is not available|مراجعة التحقق غير متاحة/ })).toBeVisible();
    expect(queueHits).toEqual([]);
    await expect(page.locator('body')).not.toContainText('verification/c/');
    await expect(page.locator('body')).not.toContainText('verification/q/');
    await page.getByRole('button', { name: /Sign out|خروج/ }).click();
    await expect(page.getByRole('heading', { name: /Admin sign in|دخول المسؤول/ })).toBeVisible();

    await signIn(page, fixture.reviewer.phone, fixture.reviewer.password, fixture.reviewer.totp_secret);
    await expect(page.getByRole('heading', { name: /Pending doctor verification|تحقق الأطباء المعلّق/ })).toBeVisible();
    await expect(page.getByText(fixture.case.professional_display_name)).toBeVisible();
    expect(queueHits.length).toBeGreaterThan(0);

    await page.getByRole('link', { name: /Open case|فتح الحالة/ }).click();
    await expect(page.getByRole('heading', { name: /Verification case|حالة التحقق/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /View \/ download document|عرض \/ تنزيل المستند/ })).toHaveCount(0);
    expect(accessPosts).toEqual([]);

    const claimWait = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        /\/api\/v1\/admin\/verification-cases\/[^/]+\/claim$/.test(new URL(response.url()).pathname),
      { timeout: 20_000 },
    );
    await page.getByRole('button', { name: /Claim case|ادّعاء الحالة/ }).click();
    const claimResponse = await claimWait;
    const claimHeaders = claimResponse.request().headers();
    const claimPayload = (await claimResponse.json().catch(() => null)) as {
      errors?: { code?: string }[];
    } | null;
    const cookieNames = (await page.context().cookies()).map((cookie) => cookie.name).sort().join(',');
    expect(
      claimResponse.ok(),
      `claim HTTP ${String(claimResponse.status())} code=${claimPayload?.errors?.[0]?.code ?? 'unknown'} cookie=${claimHeaders.cookie ? '1' : '0'} xsrf=${claimHeaders['x-xsrf-token'] ? '1' : '0'} authorization=${claimHeaders.authorization ? '1' : '0'} names=${cookieNames}`,
    ).toBeTruthy();
    const viewButton = page.getByRole('button', { name: /View \/ download document|عرض \/ تنزيل المستند/ });
    await expect(viewButton).toBeVisible({ timeout: 20_000 });
    expect(accessPosts).toEqual([]);

    const downloadPromise = page.waitForEvent('download', { timeout: 15_000 }).catch(() => null);
    await viewButton.click();
    await expect(page.getByText(/Document access was recorded|تم تسجيل الوصول إلى المستند/)).toBeVisible();
    expect(accessPosts).toHaveLength(1);
    await downloadPromise;
    const html = await page.content();
    expect(html).not.toContain('signature=');
    expect(html).not.toContain('X-Amz-');
    expect(html).not.toContain('verification/c/');
    expect(html).not.toContain('verification/q/');

    await page.getByRole('button', { name: /Submit decision|إرسال القرار/ }).click();
    await expect(page.getByRole('dialog', { name: /Confirm verification decision|تأكيد قرار التحقق/ })).toBeVisible();
    await expect(page.getByText(/Approval verifies this profile status only|الموافقة تتحقق من حالة هذا الملف فقط/)).toBeVisible();
    await page.getByRole('button', { name: /Record decision|تسجيل القرار/ }).click();
    await expect(page.getByText(/This case is no longer pending review|هذه الحالة لم تعد معلّقة للمراجعة/)).toBeVisible();
    await expect(page.getByRole('button', { name: /Submit decision|إرسال القرار/ })).toHaveCount(0);
    expect(decisionPosts).toHaveLength(1);
    expect(decisionPosts[0]?.length ?? 0).toBeGreaterThan(16);

    await page.getByRole('button', { name: /Sign out|خروج/ }).click();
    await expect(page.getByRole('heading', { name: /Admin sign in|دخول المسؤول/ })).toBeVisible();
    await expect(page.getByText(fixture.case.professional_display_name)).toHaveCount(0);
  });

  test('reviewer can switch to the pharmacy queue, claim, access evidence, and decide', async ({ page }) => {
    const fixture = loadFixture();
    const accessPosts: string[] = [];
    const decisionPosts: string[] = [];

    page.on('request', (request) => {
      const url = request.url();
      if (request.method() === 'POST' && url.includes('/documents/') && url.includes('/access')) {
        accessPosts.push('access');
      }
      if (request.method() === 'POST' && url.includes('/decisions')) {
        decisionPosts.push(request.headers()['idempotency-key'] ?? '');
      }
    });

    await signIn(page, fixture.reviewer.phone, fixture.reviewer.password, fixture.reviewer.totp_secret);
    await expect(page.getByRole('heading', { name: /Pending doctor verification|تحقق الأطباء المعلّق/ })).toBeVisible();

    await page.getByRole('button', { name: /Pharmacy verification|تحقق الصيدلية/ }).click();
    await expect(page.getByRole('heading', { name: /Pending pharmacy verification|تحقق الصيدليات المعلّق/ })).toBeVisible();
    await expect(page.getByText(fixture.pharmacy_case.public_name)).toBeVisible();
    await expect(page.getByText(fixture.case.professional_display_name)).toHaveCount(0);

    const body = await page.locator('body').innerText();
    expect(body).not.toContain(fixture.canaries.legal_name);
    expect(body).not.toContain(fixture.canaries.registration);
    expect(body).not.toContain(fixture.canaries.address);
    expect(body).not.toContain(fixture.canaries.phone);
    expect(body).not.toContain('30.0444');
    expect(body).not.toContain('31.2357');

    await page.getByRole('link', { name: /Open case|فتح الحالة/ }).click();
    await expect(page.getByRole('heading', { name: /Verification case|حالة التحقق/ })).toBeVisible();
    await expect(page.getByText(fixture.pharmacy_case.public_name)).toBeVisible();
    await expect(
      page.getByText(/Displayed organization, branch, and membership statuses come from the server|حالات المنظمة والفرع والعضوية المعروضة صادرة من الخادم/),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: /View \/ download document|عرض \/ تنزيل المستند/ })).toHaveCount(0);

    const claimWait = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        /\/api\/v1\/admin\/verification-cases\/[^/]+\/claim$/.test(new URL(response.url()).pathname),
      { timeout: 20_000 },
    );
    await page.getByRole('button', { name: /Claim case|ادّعاء الحالة/ }).click();
    const claimResponse = await claimWait;
    expect(claimResponse.ok(), `pharmacy claim HTTP ${String(claimResponse.status())}`).toBeTruthy();
    const viewButton = page.getByRole('button', { name: /View \/ download document|عرض \/ تنزيل المستند/ });
    await expect(viewButton).toBeVisible({ timeout: 20_000 });

    const downloadPromise = page.waitForEvent('download', { timeout: 15_000 }).catch(() => null);
    await viewButton.click();
    await expect(page.getByText(/Document access was recorded|تم تسجيل الوصول إلى المستند/)).toBeVisible();
    expect(accessPosts).toHaveLength(1);
    await downloadPromise;
    const html = await page.content();
    expect(html).not.toContain(fixture.canaries.legal_name);
    expect(html).not.toContain(fixture.canaries.registration);
    expect(html).not.toContain('signature=');
    expect(html).not.toContain('X-Amz-');
    expect(html).not.toContain('verification/c/');
    expect(html).not.toContain('verification/q/');

    await page.getByRole('button', { name: /Submit decision|إرسال القرار/ }).click();
    await expect(page.getByRole('dialog', { name: /Confirm verification decision|تأكيد قرار التحقق/ })).toBeVisible();
    await expect(
      page.getByText(/Approval records the server decision only|الموافقة تسجّل قرار الخادم فقط/),
    ).toBeVisible();
    await page.getByRole('button', { name: /Record decision|تسجيل القرار/ }).click();
    await expect(page.getByText(/This case is no longer pending review|هذه الحالة لم تعد معلّقة للمراجعة/)).toBeVisible();
    expect(decisionPosts).toHaveLength(1);
    expect(decisionPosts[0]?.length ?? 0).toBeGreaterThan(16);

    await page.getByRole('button', { name: /Sign out|خروج/ }).click();
    await expect(page.getByRole('heading', { name: /Admin sign in|دخول المسؤول/ })).toBeVisible();
  });
});
