import type { components } from '@clinic/api-client/schema';

export const REVIEW_CAPABILITY = 'verification.case.review';

export const CANARIES = {
  nationalId: 'NID-CANARY-DO-NOT-RENDER',
  syndicate: 'SYN-SECRET-9911',
  phone: '01099998888',
  canonicalLocator: 'verification/c/canonical-object-key',
  ingressLocator: 'verification/q/quarantine-object-key',
  signedUrl: 'http://127.0.0.1:8080/api/v1/verification-review-files/0199a5c8-0000-7000-8000-000000000021/0199a5c8-0000-7000-8000-000000000041?expires=1&signature=secret',
  notes: 'reviewer-private-notes-must-not-render',
  amz: 'X-Amz-Signature=should-never-render',
  legalName: 'CANARY-LEGAL-PHARMACY-NAME',
  registration: 'CANARY-REG-CR-998877',
  address: 'CANARY-BRANCH-ADDRESS-99 Nile St',
  coordinates: '30.04441234,31.23571234',
} as const;

export function envelope<T>(data: T, extra: Record<string, unknown> = {}) {
  return {
    data,
    meta: { locale: 'en' },
    errors: [],
    request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
    ...extra,
  };
}

export function errorEnvelope(code: string, message: string, status: number, field?: string) {
  return {
    status,
    body: {
      data: null,
      meta: { locale: 'en' },
      errors: [{ code, message, ...(field ? { field } : {}) }],
      request_id: '0199a5c8-eeee-7c3a-9b41-2f6d0c5e7a10',
    },
  };
}

export function meBody(overrides: Partial<components['schemas']['MeResult']> = {}) {
  return envelope({
    user_id: '0199a5c8-0000-7000-8000-000000000001',
    account_type: 'admin',
    status: 'active',
    language: 'en',
    assurance_level: 'aal2_totp',
    profile_links: [],
    ...overrides,
  });
}

export function capabilitiesBody(capabilities: string[]) {
  return envelope({ capabilities });
}

export function specialty() {
  return {
    specialty_id: '0199a5c8-0000-7000-8000-000000000010',
    code: 'general_practice',
    label_ar: 'طب الأسرة',
    label_en: 'General Practice',
  };
}

export function queueItem(
  overrides: Partial<components['schemas']['AdminVerificationQueueItem']> = {},
): components['schemas']['AdminVerificationQueueItem'] {
  return {
    case_id: '0199a5c8-0000-7000-8000-000000000021',
    case_type: 'doctor_verification',
    case_status: 'pending_review',
    case_version: 2,
    submitted_at: '2026-09-19T08:00:00Z',
    assignment: 'unassigned',
    assigned_to_me: false,
    doctor_id: '0199a5c8-0000-7000-8000-000000000031',
    professional_display_name: 'Dr Synthetic Review',
    specialty: specialty(),
    doctor_verification_status: 'pending_review',
    doctor_public_status: 'hidden',
    profile_version: 2,
    ...overrides,
  };
}

export function caseDetail(
  overrides: Partial<components['schemas']['AdminVerificationCase']> = {},
): components['schemas']['AdminVerificationCase'] {
  return {
    ...queueItem(),
    decided_at: null,
    decision: null,
    reason_code: null,
    documents: [],
    ...overrides,
  };
}

export function reviewDocument(
  overrides: Partial<components['schemas']['AdminVerificationReviewDocument']> = {},
): components['schemas']['AdminVerificationReviewDocument'] {
  return {
    document_id: '0199a5c8-0000-7000-8000-000000000041',
    requirement_code: 'professional_id',
    sha256: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    detected_mime: 'application/pdf',
    size_bytes: 2048,
    scan_status: 'clean',
    status: 'available',
    uploaded_at: '2026-09-19T07:50:00Z',
    ...overrides,
  };
}

export function pharmacyQueueItem(
  overrides: Partial<components['schemas']['AdminPharmacyVerificationQueueItem']> = {},
): components['schemas']['AdminPharmacyVerificationQueueItem'] {
  return {
    case_id: '0199a5c8-0000-7000-8000-000000000121',
    case_type: 'pharmacy_verification',
    case_status: 'pending_review',
    case_version: 2,
    submitted_at: '2026-09-19T08:00:00Z',
    assignment: 'unassigned',
    assigned_to_me: false,
    applicant_type: 'pharmacy',
    organization_id: '0199a5c8-0000-7000-8000-000000000131',
    public_name: 'Synthetic Pharmacy Review',
    verification_status: 'pending_review',
    status: 'pending',
    version: 2,
    initial_branch: {
      branch_id: '0199a5c8-0000-7000-8000-000000000132',
      public_name: 'Main Branch',
      country_code: 'EG',
      status: 'pending',
    },
    ...overrides,
  };
}

export function pharmacyCaseDetail(
  overrides: Partial<components['schemas']['AdminPharmacyVerificationCase']> = {},
): components['schemas']['AdminPharmacyVerificationCase'] {
  return {
    ...pharmacyQueueItem(),
    decided_at: null,
    decision: null,
    reason_code: null,
    documents: [],
    ...overrides,
  };
}

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

export function pdfAttachmentResponse(bytes = new Uint8Array([1, 2, 3, 4])): Response {
  return new Response(bytes, {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Length': String(bytes.byteLength),
      'Content-Disposition': 'attachment; filename="verification-document.pdf"',
    },
  });
}
