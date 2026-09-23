import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { totpCode } from './totp';

type FixtureFile = {
  reviewer: { phone: string; password: string; totp_secret: string };
  creator: { phone: string; password: string; totp_secret: string };
  applicant: {
    phone: string;
    national_id: string;
    password: string;
    professional_display_name: string;
    evidence_source: string;
  };
};

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '../..');
const coreApi = join(repoRoot, 'apps/core-api');

function loadFixture(): FixtureFile {
  const path = process.env.CLINIC_E2E_ADMIN_FIXTURE || '/tmp/clinic-e2e-admin-verification.json';
  if (!existsSync(path)) {
    throw new Error('Admin verification browser fixture is missing.');
  }

  return JSON.parse(readFileSync(path, 'utf8')) as FixtureFile;
}

function artisan(args: string[]): string {
  return execFileSync('php', [join(coreApi, 'artisan'), ...args], {
    cwd: coreApi,
    env: process.env,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

function probeCapability(doctorId: string, writePath: string): {
  http_status: number;
  error_code: string | null;
  verification_status: string;
  public_status: string;
} {
  artisan([
    'e2e:probe-doctor-clinic-capability',
    `--doctor-id=${doctorId}`,
    `--write=${writePath}`,
  ]);
  return JSON.parse(readFileSync(writePath, 'utf8')) as {
    http_status: number;
    error_code: string | null;
    verification_status: string;
    public_status: string;
  };
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
}

async function signIn(page: Page, phone: string, password: string, totpSecret: string): Promise<void> {
  await page.goto('/');
  await ensureSignedOut(page);
  await page.getByRole('textbox', { name: /Mobile number|رقم الجوال/ }).fill(phone);
  await page.getByRole('textbox', { name: /Password|كلمة المرور/ }).fill(password);
  await page.getByRole('button', { name: /Sign in|دخول/ }).click();

  const mfa = page.getByRole('textbox', { name: /Authenticator code|رمز التحقق/ });
  const queue = page.getByRole('heading', { name: /Pending doctor verification|تحقق الأطباء المعلّق/ });
  await Promise.race([
    mfa.waitFor({ timeout: 20_000 }),
    queue.waitFor({ timeout: 20_000 }),
  ]);

  if (await mfa.isVisible()) {
    await mfa.fill(totpCode(totpSecret));
    await page.getByRole('button', { name: /Sign in|دخول/ }).click();
  }
}

test.describe('admin-created doctor', () => {
  test('privileged admin creates, submits evidence, and a different reviewer approves', async ({ page }) => {
    const fixture = loadFixture();
    let capturedDoctorId = '';
    let capturedUploadId = '';

    await page.route(/objects\.invalid/, async (route) => {
      if (route.request().method() === 'PUT') {
        const deadline = Date.now() + 10_000;
        while (capturedUploadId === '' && Date.now() < deadline) {
          await new Promise((resolve) => {
            setTimeout(resolve, 50);
          });
        }
        expect(capturedUploadId).not.toBe('');
        artisan(['e2e:write-verification-upload', capturedUploadId]);
        await route.fulfill({ status: 200, body: '' });
        return;
      }
      await route.continue();
    });

    page.on('response', (response) => {
      if (
        response.request().method() === 'POST' &&
        /\/api\/v1\/admin\/doctor-applicants\/[^/]+\/verification-uploads$/.test(new URL(response.url()).pathname)
      ) {
        void response
          .json()
          .then((body: { data?: { upload_id?: string } }) => {
            capturedUploadId = body.data?.upload_id ?? capturedUploadId;
          })
          .catch(() => undefined);
      }
    });

    await signIn(page, fixture.creator.phone, fixture.creator.password, fixture.creator.totp_secret);
    await expect(page.getByRole('heading', { name: /Pending doctor verification|تحقق الأطباء المعلّق/ })).toBeVisible();
    await page.getByRole('link', { name: /Create doctor applicant|إنشاء طالب طبيب/ }).first().click();
    await expect(page.getByRole('heading', { name: /Create doctor applicant|إنشاء طالب طبيب/ })).toBeVisible();

    await page.getByLabel(/Professional display name|الاسم المهني الظاهر/).fill(fixture.applicant.professional_display_name);
    await page.getByLabel(/Mobile number|رقم الجوال/).fill(fixture.applicant.phone);
    await page.getByLabel(/National ID|الرقم القومي/).fill(fixture.applicant.national_id);
    await page.getByLabel(/Initial password|كلمة المرور الأولية/).fill(fixture.applicant.password);
    await page.getByLabel(/Specialty|التخصص/).click();
    await page.getByRole('option').first().click();

    const createWait = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        new URL(response.url()).pathname === '/api/v1/admin/doctor-applicants',
      { timeout: 20_000 },
    );
    await page.getByRole('button', { name: /Create applicant|إنشاء الطالب/ }).click();
    const created = await createWait;
    expect(created.ok(), `create HTTP ${String(created.status())}`).toBeTruthy();
    const createdJson = (await created.json()) as { data?: { doctor_id?: string } };
    capturedDoctorId = createdJson.data?.doctor_id ?? '';
    expect(capturedDoctorId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    await expect(page.getByText(/A draft verification case is ready|مسودة حالة التحقق جاهزة/)).toBeVisible();
    await expect(page.locator('body')).not.toContainText(fixture.applicant.national_id);

    const pdf = Buffer.from(
      '%PDF-1.4\n'
        + '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n'
        + '2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n'
        + '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>endobj\n'
        + 'trailer<< /Root 1 0 R >>\n'
        + '%%EOF\n',
      'utf8',
    );
    await page.locator('input[type="file"]').setInputFiles({
      name: 'professional-id.pdf',
      mimeType: 'application/pdf',
      buffer: pdf,
    });

    const uploadWait = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        /\/api\/v1\/admin\/doctor-applicants\/[^/]+\/verification-uploads$/.test(new URL(response.url()).pathname),
      { timeout: 20_000 },
    );
    const completeWait = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        /\/api\/v1\/verification-uploads\/[^/]+\/complete$/.test(new URL(response.url()).pathname),
      { timeout: 30_000 },
    );
    await page.getByRole('button', { name: /Upload evidence|رفع الدليل/ }).click();
    const uploadResponse = await uploadWait;
    expect(uploadResponse.ok(), `upload HTTP ${String(uploadResponse.status())}`).toBeTruthy();
    const uploadJson = (await uploadResponse.json()) as { data?: { upload_id?: string } };
    capturedUploadId = uploadJson.data?.upload_id ?? '';
    expect(capturedUploadId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    const completeResponse = await completeWait;
    expect(completeResponse.ok(), `complete HTTP ${String(completeResponse.status())}`).toBeTruthy();
    artisan(['e2e:process-verification-upload', capturedUploadId]);
    await expect(page.getByText(/Evidence is ready for review|الدليل جاهز للمراجعة/)).toBeVisible({ timeout: 45_000 });

    await page.getByRole('button', { name: /Submit for review|إرسال للمراجعة/ }).click();
    await expect(page.getByText(/The case is in the verification queue|الحالة في قائمة التحقق/)).toBeVisible();

    const pendingPath = '/tmp/clinic-e2e-admin-created-pending.json';
    const pending = probeCapability(capturedDoctorId, pendingPath);
    expect(pending.http_status).toBe(404);
    expect(pending.verification_status).toBe('pending_review');
    expect(pending.public_status).toBe('hidden');

    await signIn(page, fixture.reviewer.phone, fixture.reviewer.password, fixture.reviewer.totp_secret);
    await expect(page.getByRole('heading', { name: /Pending doctor verification|تحقق الأطباء المعلّق/ })).toBeVisible();
    const row = page.locator('tr', { hasText: fixture.applicant.professional_display_name });
    await expect(row).toBeVisible();
    await row.getByRole('link', { name: /Open case|فتح الحالة/ }).click();
    await expect(page.getByRole('heading', { name: /Verification case|حالة التحقق/ })).toBeVisible();

    const claimWait = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        /\/api\/v1\/admin\/verification-cases\/[^/]+\/claim$/.test(new URL(response.url()).pathname),
      { timeout: 20_000 },
    );
    await page.getByRole('button', { name: /Claim case|ادّعاء الحالة/ }).click();
    const claim = await claimWait;
    expect(claim.ok(), `claim HTTP ${String(claim.status())}`).toBeTruthy();
    await page.getByRole('button', { name: /Submit decision|إرسال القرار/ }).click();
    await expect(page.getByRole('dialog', { name: /Confirm verification decision|تأكيد قرار التحقق/ })).toBeVisible();
    await page.getByRole('button', { name: /Record decision|تسجيل القرار/ }).click();
    await expect(page.getByText(/This case is no longer pending review|هذه الحالة لم تعد معلّقة للمراجعة/)).toBeVisible();

    const approvedPath = '/tmp/clinic-e2e-admin-created-approved.json';
    const approved = probeCapability(capturedDoctorId, approvedPath);
    expect(approved.http_status).toBe(201);
    expect(approved.verification_status).toBe('approved');
    expect(approved.public_status).toBe('hidden');
  });
});
