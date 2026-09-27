/**
 * Phase 02 Verification Policy v1.0.1-phase02 typed catalogues.
 *
 * Runtime clients import these constants. They must not fetch the repository
 * JSON artifact. Exact correspondence is proven by tests against
 * docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.json.
 *
 * This is not government, syndicate, licensing-authority, or registry-provider
 * approval. The platform does not perform automated government or
 * commercial-register verification.
 */

export const PHASE02_VERIFICATION_POLICY_VERSION = 'v1.0.1-phase02' as const;
export const PHASE02_VERIFICATION_POLICY_SUPERSEDES = 'v1.0.0-phase02' as const;
export const PHASE02_VERIFICATION_POLICY_RELEASE_DATE = '2026-09-24' as const;
export const PHASE02_VERIFICATION_POLICY_STATUS = 'APPROVED_PRODUCTION_POLICY' as const;
export const PHASE02_VERIFICATION_POLICY_SHA256 =
  'a5cdab95e446224b57407fb017a7c5b32a8f494a7cade6b0d0e274ceb1ea281d' as const;

export const PHASE02_VERIFICATION_POLICY_APPROVED_BY = {
  product: 'prod-gov-lead@system.internal',
  privacy: 'privacy-dpo@system.internal',
  security: 'ciso-gov@system.internal',
} as const;

export const PHASE02_VERIFICATION_POLICY_SUPERSESSION_REASON =
  'alignment with Phase 02 append-only architecture and existing authorization/retention boundaries' as const;

export const QUARANTINE_CLEANUP_DELAY_SECONDS = 86_400 as const;

export type DoctorRequirementCode = 'medical_license' | 'national_id_or_passport' | 'syndicate_card';
export type PharmacyRequirementCode =
  | 'pharmacy_facility_license'
  | 'commercial_register'
  | 'responsible_pharmacist_license';
export type VerificationRequirementCode = DoctorRequirementCode | PharmacyRequirementCode;

export type VerificationDecisionCode = 'approved' | 'rejected' | 'changes_requested';

export type ApprovedReasonCode =
  | 'approved'
  | 'docs_blurry_or_illegible'
  | 'missing_required_docs'
  | 'identity_mismatch'
  | 'license_expired'
  | 'fraudulent_or_altered_doc'
  | 'unauthorized_entity';

export interface DoctorRequirement {
  readonly code: DoctorRequirementCode;
  readonly label_ar: string;
  readonly label_en: string;
  readonly required: boolean;
  readonly proof_description: string;
  readonly visibility: 'both';
}

export interface PharmacyRequirement {
  readonly code: PharmacyRequirementCode;
  readonly label_ar: string;
  readonly label_en: string;
  readonly required: boolean;
  readonly purpose: string;
}

export interface DecisionReason {
  readonly code: ApprovedReasonCode;
  readonly allowed_decisions: readonly VerificationDecisionCode[];
  readonly label_ar: string;
  readonly label_en: string;
  readonly applicant_safe_explanation: string;
  readonly visible_to_applicant: true;
  readonly reviewer_notes_allowed: true;
}

export const DOCTOR_REQUIREMENTS: readonly DoctorRequirement[] = [
  {
    code: 'medical_license',
    label_ar: 'ترخيص ممارسة المهنة الطبية',
    label_en: 'Professional Medical License',
    required: true,
    proof_description: 'Proves valid medical practice authorization.',
    visibility: 'both',
  },
  {
    code: 'national_id_or_passport',
    label_ar: 'إثبات الهوية الشخصية (هوية/جواز)',
    label_en: 'Government Photo ID',
    required: true,
    proof_description: 'Proves legal identity and matches identity details.',
    visibility: 'both',
  },
  {
    code: 'syndicate_card',
    label_ar: 'بطاقة عضوية النقابة الطبية',
    label_en: 'Medical Syndicate Membership Card',
    required: false,
    proof_description: 'Optional proof of active syndicate enrollment.',
    visibility: 'both',
  },
];

export const PHARMACY_REQUIREMENTS: readonly PharmacyRequirement[] = [
  {
    code: 'pharmacy_facility_license',
    label_ar: 'ترخيص المنشأة الصيدلانية',
    label_en: 'Pharmacy Operating License',
    required: true,
    purpose: 'Proves authorization to operate a pharmacy business.',
  },
  {
    code: 'commercial_register',
    label_ar: 'السجل التجاري للمؤسسة',
    label_en: 'Commercial Registration Certificate',
    required: true,
    purpose: 'Proves legal business entity registration.',
  },
  {
    code: 'responsible_pharmacist_license',
    label_ar: 'ترخيص الصيدلي المسؤول',
    label_en: 'Responsible Pharmacist License',
    required: true,
    purpose: 'Links establishment to a licensed pharmacist in charge.',
  },
];

export const DECISION_REASONS: readonly DecisionReason[] = [
  {
    code: 'approved',
    allowed_decisions: ['approved'],
    label_ar: 'تم الاعتماد',
    label_en: 'Verification Approved',
    applicant_safe_explanation: 'تم التحقق من جميع المستندات بنجاح واعتماد الحساب.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
  {
    code: 'docs_blurry_or_illegible',
    allowed_decisions: ['changes_requested'],
    label_ar: 'المستندات غير واضحة',
    label_en: 'Documents Illegible',
    applicant_safe_explanation:
      'صورة المستند المقدم غير واضحة أو يتعذر قراءة البيانات منها. يرجى إعادة رفع نسخة جيدة.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
  {
    code: 'missing_required_docs',
    allowed_decisions: ['changes_requested'],
    label_ar: 'نقص في المستندات المطلوبة',
    label_en: 'Required Documents Missing',
    applicant_safe_explanation:
      'يرجى إرفاق كافة المستندات الإلزامية المطلوبة لإتمام عملية التقييم.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
  {
    code: 'identity_mismatch',
    allowed_decisions: ['changes_requested'],
    label_ar: 'عدم مطابقة بيانات الهوية',
    label_en: 'Identity Details Mismatch',
    applicant_safe_explanation:
      'البيانات المدخلة في الطلب لا تتطابق مع البيانات الموجودة في المستندات المرفقة.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
  {
    code: 'license_expired',
    allowed_decisions: ['changes_requested'],
    label_ar: 'الترخيص أو الهوية منتهية',
    label_en: 'Document Expired',
    applicant_safe_explanation:
      'المستند المرفق (الترخيص أو الهوية) منتهي الصلاحية. يرجى إرفاق مستند ساري.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
  {
    code: 'fraudulent_or_altered_doc',
    allowed_decisions: ['rejected'],
    label_ar: 'مستندات غير صالحة أو معدلة',
    label_en: 'Invalid or Fraudulent Document',
    applicant_safe_explanation:
      'تعذر قبول الطلب بسبب وجود تلاعب أو عدم صحة البيانات في المستندات المرفقة.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
  {
    code: 'unauthorized_entity',
    allowed_decisions: ['rejected'],
    label_ar: 'منشأة غير مؤهلة',
    label_en: 'Unauthorized Entity',
    applicant_safe_explanation:
      'المنشأة أو المتقدم لا يستوفي الشروط التنظيمية للتسجيل في المنصة.',
    visible_to_applicant: true,
    reviewer_notes_allowed: true,
  },
];

export const DOCTOR_REQUIREMENT_CODES = [
  'medical_license',
  'national_id_or_passport',
  'syndicate_card',
] as const;
export const PHARMACY_REQUIREMENT_CODES = [
  'pharmacy_facility_license',
  'commercial_register',
  'responsible_pharmacist_license',
] as const;
export const APPROVED_REASON_CODES = [
  'approved',
  'docs_blurry_or_illegible',
  'missing_required_docs',
  'identity_mismatch',
  'license_expired',
  'fraudulent_or_altered_doc',
  'unauthorized_entity',
] as const;

export const VERIFICATION_DECISIONS = ['approved', 'rejected', 'changes_requested'] as const;

export const APPROVED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'] as const;
export const MAX_ACTIVE_UPLOADS_PER_REQUIREMENT = 3 as const;

export const WITHDRAWN_REASON_CODES = [
  'evidence_incomplete',
  'documents_illegible',
] as const;

export function isDoctorRequirementCode(code: string): code is DoctorRequirementCode {
  return (DOCTOR_REQUIREMENT_CODES as readonly string[]).includes(code);
}

export function isPharmacyRequirementCode(code: string): code is PharmacyRequirementCode {
  return (PHARMACY_REQUIREMENT_CODES as readonly string[]).includes(code);
}

export function isApprovedReasonCode(code: string): code is ApprovedReasonCode {
  return (APPROVED_REASON_CODES as readonly string[]).includes(code);
}

export function reasonsForDecision(decision: VerificationDecisionCode): readonly ApprovedReasonCode[] {
  return DECISION_REASONS.filter((reason) => reason.allowed_decisions.includes(decision)).map(
    (reason) => reason.code,
  );
}

export function isAllowedDecisionPair(decision: string, reasonCode: string): boolean {
  if (decision !== 'approved' && decision !== 'rejected' && decision !== 'changes_requested') {
    return false;
  }

  return reasonsForDecision(decision).includes(reasonCode as ApprovedReasonCode);
}

export function applicantSafeExplanation(reasonCode: string | null | undefined): string | null {
  if (reasonCode === null || reasonCode === undefined || reasonCode === '') {
    return null;
  }
  const reason = DECISION_REASONS.find((item) => item.code === reasonCode);
  if (reason === undefined || reason.visible_to_applicant !== true) {
    return null;
  }
  return reason.applicant_safe_explanation;
}

export function reasonLabel(reasonCode: string, locale: 'ar' | 'en'): string | null {
  const reason = DECISION_REASONS.find((item) => item.code === reasonCode);
  if (reason === undefined) {
    return null;
  }
  return locale === 'ar' ? reason.label_ar : reason.label_en;
}

export function requirementLabel(
  requirement: DoctorRequirement | PharmacyRequirement,
  locale: 'ar' | 'en',
): string {
  return locale === 'ar' ? requirement.label_ar : requirement.label_en;
}

export function requiredDocumentsReady(
  documents: readonly { requirementCode: string; status: string; scanStatus: string }[],
  requirements: readonly { code: string; required: boolean }[],
): boolean {
  return requirements
    .filter((requirement) => requirement.required)
    .every((requirement) =>
      documents.some(
        (document) =>
          document.requirementCode === requirement.code &&
          document.status === 'available' &&
          document.scanStatus === 'clean',
      ),
    );
}

export function historicalApplicantReasonCopy(locale: 'ar' | 'en'): string {
  if (locale === 'ar') {
    return 'تم تسجيل قرار التحقق. يرجى اتباع حالة الطلب الحالية للمتابعة.';
  }
  return 'A verification decision was recorded. Follow the current case status to continue.';
}

export function applicantVisibleReasonCopy(
  reasonCode: string | null | undefined,
  locale: 'ar' | 'en',
): string | null {
  if (reasonCode === null || reasonCode === undefined || reasonCode === '') {
    return null;
  }
  const approved = applicantSafeExplanation(reasonCode);
  if (approved !== null) {
    return approved;
  }
  return historicalApplicantReasonCopy(locale);
}
