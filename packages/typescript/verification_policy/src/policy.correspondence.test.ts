import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  APPROVED_MIME_TYPES,
  APPROVED_REASON_CODES,
  DECISION_REASONS,
  DOCTOR_REQUIREMENTS,
  MAX_ACTIVE_UPLOADS_PER_REQUIREMENT,
  PHASE02_VERIFICATION_POLICY_APPROVED_BY,
  PHASE02_VERIFICATION_POLICY_RELEASE_DATE,
  PHASE02_VERIFICATION_POLICY_SHA256,
  PHASE02_VERIFICATION_POLICY_STATUS,
  PHASE02_VERIFICATION_POLICY_SUPERSEDES,
  PHASE02_VERIFICATION_POLICY_SUPERSESSION_REASON,
  PHASE02_VERIFICATION_POLICY_VERSION,
  PHARMACY_REQUIREMENTS,
  QUARANTINE_CLEANUP_DELAY_SECONDS,
  applicantSafeExplanation,
  isAllowedDecisionPair,
  isDoctorRequirementCode,
  isPharmacyRequirementCode,
  reasonsForDecision,
  requiredDocumentsReady,
} from './index';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..');
const artifactPath = resolve(
  root,
  'docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.json',
);
const shaPath = resolve(
  root,
  'docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.sha256',
);

describe('Phase 02 verification policy v1.0.1 correspondence', () => {
  const artifactJson = readFileSync(artifactPath, 'utf8');
  const artifact = JSON.parse(artifactJson) as {
    version: string;
    supersedes: string;
    release_date: string;
    status: string;
    approved_by: { product: string; privacy: string; security: string };
    supersession_reason: string;
    doctor_requirements: typeof DOCTOR_REQUIREMENTS;
    pharmacy_requirements: typeof PHARMACY_REQUIREMENTS;
    decision_reasons: typeof DECISION_REASONS;
    appeal: { allowed: boolean; implementation_required_in_phase02: boolean; status: string };
    retention: { quarantine_abandoned_expired_failed_rejected: { cleanup_delay_seconds: number } };
    technical_upload_limits: {
      allowed_mime_types: { value: string[]; classification: string };
      max_active_uploads_per_requirement: { value: number; classification: string };
      max_document_bytes: { classification: string };
      upload_expiry_seconds: { classification: string };
      reviewer_document_access_ttl_seconds: { classification: string };
    };
  };

  it('matches the immutable artifact metadata and SHA-256', () => {
    const digest = createHash('sha256').update(artifactJson).digest('hex');
    expect(digest).toBe(PHASE02_VERIFICATION_POLICY_SHA256);
    expect(readFileSync(shaPath, 'utf8').trim()).toBe(PHASE02_VERIFICATION_POLICY_SHA256);
    expect(artifact.version).toBe(PHASE02_VERIFICATION_POLICY_VERSION);
    expect(artifact.supersedes).toBe(PHASE02_VERIFICATION_POLICY_SUPERSEDES);
    expect(artifact.release_date).toBe(PHASE02_VERIFICATION_POLICY_RELEASE_DATE);
    expect(artifact.status).toBe(PHASE02_VERIFICATION_POLICY_STATUS);
    expect(artifact.approved_by).toEqual(PHASE02_VERIFICATION_POLICY_APPROVED_BY);
    expect(artifact.supersession_reason).toBe(PHASE02_VERIFICATION_POLICY_SUPERSESSION_REASON);
    expect(artifactJson).not.toContain('ENGINEERING_DEFAULT');
    expect(artifact.appeal).toEqual({
      allowed: false,
      implementation_required_in_phase02: false,
      status: 'DEFERRED',
    });
    expect(artifact.retention.quarantine_abandoned_expired_failed_rejected.cleanup_delay_seconds).toBe(
      QUARANTINE_CLEANUP_DELAY_SECONDS,
    );
    expect(artifact.technical_upload_limits.allowed_mime_types).toEqual({
      value: [...APPROVED_MIME_TYPES],
      classification: 'APPROVED_AS_POLICY',
    });
    expect(artifact.technical_upload_limits.max_active_uploads_per_requirement).toEqual({
      value: MAX_ACTIVE_UPLOADS_PER_REQUIREMENT,
      classification: 'APPROVED_AS_POLICY',
    });
    expect(artifact.technical_upload_limits.max_document_bytes.classification).toBe('ENGINEERING_CONTROL');
    expect(artifact.technical_upload_limits.upload_expiry_seconds.classification).toBe(
      'ENGINEERING_CONTROL',
    );
    expect(artifact.technical_upload_limits.reviewer_document_access_ttl_seconds.classification).toBe(
      'ENGINEERING_CONTROL',
    );
  });

  it('mirrors doctor, pharmacy, and reason catalogues exactly', () => {
    expect(DOCTOR_REQUIREMENTS).toEqual(artifact.doctor_requirements);
    expect(PHARMACY_REQUIREMENTS).toEqual(artifact.pharmacy_requirements);
    expect(DECISION_REASONS).toEqual(artifact.decision_reasons);
    expect(APPROVED_REASON_CODES).toEqual(artifact.decision_reasons.map((reason) => reason.code));
  });

  it('filters reasons by decision and rejects withdrawn pairs', () => {
    expect(reasonsForDecision('approved')).toEqual(['approved']);
    expect(reasonsForDecision('changes_requested')).toEqual([
      'docs_blurry_or_illegible',
      'missing_required_docs',
      'identity_mismatch',
      'license_expired',
    ]);
    expect(reasonsForDecision('rejected')).toEqual([
      'fraudulent_or_altered_doc',
      'unauthorized_entity',
    ]);
    expect(isAllowedDecisionPair('rejected', 'identity_mismatch')).toBe(false);
    expect(isAllowedDecisionPair('changes_requested', 'identity_mismatch')).toBe(true);
    expect(isAllowedDecisionPair('rejected', 'evidence_incomplete')).toBe(false);
    expect(isAllowedDecisionPair('changes_requested', 'documents_illegible')).toBe(false);
  });

  it('requires every mandatory document before submit readiness', () => {
    const license = {
      requirementCode: 'medical_license',
      status: 'available',
      scanStatus: 'clean',
    };
    const identity = {
      requirementCode: 'national_id_or_passport',
      status: 'available',
      scanStatus: 'clean',
    };
    expect(requiredDocumentsReady([license], DOCTOR_REQUIREMENTS)).toBe(false);
    expect(requiredDocumentsReady([identity], DOCTOR_REQUIREMENTS)).toBe(false);
    expect(requiredDocumentsReady([license, identity], DOCTOR_REQUIREMENTS)).toBe(true);
    expect(
      requiredDocumentsReady(
        [
          license,
          identity,
          { requirementCode: 'syndicate_card', status: 'quarantined', scanStatus: 'pending' },
        ],
        DOCTOR_REQUIREMENTS,
      ),
    ).toBe(true);
    expect(
      requiredDocumentsReady(
        [
          { requirementCode: 'pharmacy_facility_license', status: 'available', scanStatus: 'clean' },
          { requirementCode: 'commercial_register', status: 'available', scanStatus: 'clean' },
        ],
        PHARMACY_REQUIREMENTS,
      ),
    ).toBe(false);
    expect(isDoctorRequirementCode('professional_id')).toBe(false);
    expect(isPharmacyRequirementCode('organization_registration_evidence')).toBe(false);
    expect(applicantSafeExplanation('docs_blurry_or_illegible')).toBe(
      'صورة المستند المقدم غير واضحة أو يتعذر قراءة البيانات منها. يرجى إعادة رفع نسخة جيدة.',
    );
    expect(applicantSafeExplanation('evidence_incomplete')).toBeNull();
  });
});
