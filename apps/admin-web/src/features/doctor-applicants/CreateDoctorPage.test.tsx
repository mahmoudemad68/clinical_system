import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { requestUrl, stubApi } from '@/test/apiStub';
import {
  CANARIES,
  CREATE_DOCTOR_CAPABILITY,
  REVIEW_CAPABILITY,
  capabilitiesBody,
  envelope,
  jsonResponse,
  meBody,
  specialty,
} from '@/test/fixtures';
import i18n from '@/i18n';

const doctorId = '0199a5c8-0000-7000-8000-000000000201';
const caseId = '0199a5c8-0000-7000-8000-000000000202';
const uploadId = '0199a5c8-0000-7000-8000-000000000203';

function health() {
  return jsonResponse(
    envelope({
      status: 'operational',
      message: 'ok',
      components: { core: 'operational', realtime: 'operational', ai: 'operational' },
      version: '0.1.0-test',
      server_time: '2026-09-20T00:00:00Z',
    }),
  );
}

describe('create doctor applicant', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
    localStorage.clear();
    sessionStorage.clear();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('creates an applicant through the closed Admin API and does not persist identifiers', async () => {
    const createBodies: unknown[] = [];
    stubApi({
      'GET /api/v1/me': () => jsonResponse(meBody()),
      'GET /api/v1/me/capabilities': () =>
        jsonResponse(capabilitiesBody([REVIEW_CAPABILITY, CREATE_DOCTOR_CAPABILITY])),
      'GET /api/v1/health': () => health(),
      'GET /api/v1/admin/verification-cases': () =>
        jsonResponse(envelope([], { meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } } })),
      'GET /api/v1/admin/doctor-applicants/specialties': () =>
        jsonResponse(envelope({ specialties: [specialty()] })),
      'POST /api/v1/admin/doctor-applicants': async (request) => {
        createBodies.push(await request.json());
        return jsonResponse(
          envelope({
            status: 'created',
            doctor_id: doctorId,
            profile_version: 1,
            case_id: caseId,
            case_version: 1,
            case_status: 'draft',
          }),
          201,
        );
      },
      [`POST /api/v1/admin/doctor-applicants/${doctorId}/verification-uploads`]: () =>
        jsonResponse(
          envelope({
            upload_id: uploadId,
            state: 'uploading',
            requirement_code: 'professional_id',
            upload_target: {
              method: 'PUT',
              url: 'http://localhost/upload-grant',
              headers: { 'Content-Type': 'application/pdf' },
              expires_at: '2026-09-22T00:00:00Z',
            },
          }),
          201,
        ),
      'PUT /upload-grant': () => new Response(null, { status: 200 }),
      [`POST /api/v1/verification-uploads/${uploadId}/complete`]: () =>
        jsonResponse(envelope({ upload_id: uploadId, state: 'uploaded' })),
      [`GET /api/v1/verification-uploads/${uploadId}`]: () =>
        jsonResponse(envelope({ upload_id: uploadId, state: 'available' })),
      [`POST /api/v1/admin/doctor-applicants/${doctorId}/verification-submissions`]: () =>
        jsonResponse(
          envelope({
            doctor_id: doctorId,
            case_id: caseId,
            case_status: 'pending_review',
            case_version: 2,
            profile_version: 2,
            profile_verification_status: 'pending_review',
          }),
        ),
    });

    const user = userEvent.setup();
    renderApp('/doctor-applicants/new');
    expect(await screen.findByRole('heading', { name: 'Create doctor applicant' })).toBeInTheDocument();
    await user.type(screen.getByLabelText('Professional display name'), 'Dr Admin Created UI');
    await user.type(screen.getByLabelText('Mobile number'), CANARIES.phone);
    await user.type(screen.getByLabelText('National ID'), CANARIES.nationalId);
    await user.type(screen.getByLabelText('Syndicate number (optional)'), CANARIES.syndicate);
    await user.click(screen.getByLabelText('Specialty'));
    await user.click(await screen.findByRole('option', { name: 'General Practice' }));
    await user.type(screen.getByLabelText('Initial password'), 'correct-horse-battery');
    await user.click(screen.getByRole('button', { name: 'Create applicant' }));

    await screen.findByText(/A draft verification case is ready/);
    expect(createBodies).toHaveLength(1);
    expect(createBodies[0]).toMatchObject({
      professional_display_name: 'Dr Admin Created UI',
      phone: CANARIES.phone,
      national_id: CANARIES.nationalId,
      evidence_source: 'in_person_originals',
    });
    expect(createBodies[0]).not.toHaveProperty('verification_status');
    expect(createBodies[0]).not.toHaveProperty('public_status');
    expect(createBodies[0]).not.toHaveProperty('bootstrap');
    expect(window.location.href).not.toContain(CANARIES.nationalId);
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(document.body.textContent ?? '').not.toContain(CANARIES.nationalId);
    expect(document.body.textContent ?? '').not.toContain(CANARIES.syndicate);
    expect(document.body.textContent ?? '').not.toContain('correct-horse-battery');

    const file = new File([new Uint8Array([37, 80, 68, 70])], 'id.pdf', { type: 'application/pdf' });
    const input = document.querySelector('input[type="file"]');
    expect(input).toBeInstanceOf(HTMLInputElement);
    await user.upload(input as HTMLInputElement, file);
    await user.click(screen.getByRole('button', { name: 'Upload evidence' }));
    expect(await screen.findByText('Evidence is ready for review.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Submit for review' }));
    expect(await screen.findByText(/The case is in the verification queue/)).toBeInTheDocument();
  });

  it('does not expose the create form without doctors.admin.create', async () => {
    const fetchMock = stubApi({
      'GET /api/v1/me': () => jsonResponse(meBody()),
      'GET /api/v1/me/capabilities': () => jsonResponse(capabilitiesBody([REVIEW_CAPABILITY])),
      'GET /api/v1/health': () => health(),
      'GET /api/v1/admin/verification-cases': () =>
        jsonResponse(envelope([], { meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } } })),
    });

    renderApp('/doctor-applicants/new');
    expect(await screen.findByRole('heading', { name: 'Verification review is not available' })).toBeInTheDocument();
    expect(fetchMock.mock.calls.some((call) => requestUrl(call).includes('/admin/doctor-applicants'))).toBe(false);
  });
});
