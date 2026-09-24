<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Phase 02 Verification Policy v1.0.1-phase02.
 *
 * Operational catalogues are mirrored from the immutable governance artifact.
 * Runtime code does not load repository JSON. Correspondence is proven by tests.
 *
 * This is an approved production policy for Phase 02 verification requirements,
 * reasons, MIME types, and max active uploads. It is not government, syndicate,
 * licensing-authority, or registry-provider approval. The platform does not
 * perform automated government or commercial-register verification.
 *
 * @phpstan-type DoctorRequirement array{
 *     code: string,
 *     label_ar: string,
 *     label_en: string,
 *     required: bool,
 *     proof_description: string,
 *     visibility: string
 * }
 * @phpstan-type PharmacyRequirement array{
 *     code: string,
 *     label_ar: string,
 *     label_en: string,
 *     required: bool,
 *     purpose: string
 * }
 * @phpstan-type DecisionReason array{
 *     code: string,
 *     allowed_decisions: list<string>,
 *     label_ar: string,
 *     label_en: string,
 *     applicant_safe_explanation: string,
 *     visible_to_applicant: bool,
 *     reviewer_notes_allowed: bool
 * }
 * @phpstan-type RequirementConfig array{
 *     case_type: string,
 *     required: bool,
 *     label_ar: string,
 *     label_en: string,
 *     proof_description?: string,
 *     purpose?: string,
 *     visibility?: string
 * }
 * @phpstan-type ReasonConfig array{
 *     allowed_decisions: list<string>,
 *     label_ar: string,
 *     label_en: string,
 *     applicant_safe_explanation: string,
 *     visible_to_applicant: bool,
 *     reviewer_notes_allowed: bool
 * }
 */
final class ApprovedVerificationPolicyV1
{
    public const VERSION = 'v1.0.1-phase02';

    public const SUPERSEDES = 'v1.0.0-phase02';

    public const RELEASE_DATE = '2026-09-24';

    public const STATUS = 'APPROVED_PRODUCTION_POLICY';

    public const APPROVED_BY_PRODUCT = 'prod-gov-lead@system.internal';

    public const APPROVED_BY_PRIVACY = 'privacy-dpo@system.internal';

    public const APPROVED_BY_SECURITY = 'ciso-gov@system.internal';

    public const SUPERSESSION_REASON = 'alignment with Phase 02 append-only architecture and existing authorization/retention boundaries';

    public const ARTIFACT_RELATIVE_PATH = 'docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.json';

    public const ARTIFACT_SHA256_RELATIVE_PATH = 'docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.sha256';

    public const ARTIFACT_SHA256 = 'a5cdab95e446224b57407fb017a7c5b32a8f494a7cade6b0d0e274ceb1ea281d';

    public const QUARANTINE_CLEANUP_DELAY_SECONDS = 86_400;

    /**
     * Historical requirement codes that may still exist on in-flight or
     * already-submitted rows. New grants and new submissions do not accept them.
     */
    public const LEGACY_DOCTOR_REQUIREMENT = 'professional_id';

    public const LEGACY_PHARMACY_REQUIREMENT = 'organization_registration_evidence';

    public static function repositoryRoot(): string
    {
        return dirname(base_path(), 2);
    }

    public static function artifactPath(): string
    {
        return self::repositoryRoot().DIRECTORY_SEPARATOR.self::ARTIFACT_RELATIVE_PATH;
    }

    public static function artifactSha256Path(): string
    {
        return self::repositoryRoot().DIRECTORY_SEPARATOR.self::ARTIFACT_SHA256_RELATIVE_PATH;
    }

    /**
     * @return list<DoctorRequirement>
     */
    public static function doctorRequirements(): array
    {
        return [
            [
                'code' => 'medical_license',
                'label_ar' => 'ترخيص ممارسة المهنة الطبية',
                'label_en' => 'Professional Medical License',
                'required' => true,
                'proof_description' => 'Proves valid medical practice authorization.',
                'visibility' => 'both',
            ],
            [
                'code' => 'national_id_or_passport',
                'label_ar' => 'إثبات الهوية الشخصية (هوية/جواز)',
                'label_en' => 'Government Photo ID',
                'required' => true,
                'proof_description' => 'Proves legal identity and matches identity details.',
                'visibility' => 'both',
            ],
            [
                'code' => 'syndicate_card',
                'label_ar' => 'بطاقة عضوية النقابة الطبية',
                'label_en' => 'Medical Syndicate Membership Card',
                'required' => false,
                'proof_description' => 'Optional proof of active syndicate enrollment.',
                'visibility' => 'both',
            ],
        ];
    }

    /**
     * @return list<PharmacyRequirement>
     */
    public static function pharmacyRequirements(): array
    {
        return [
            [
                'code' => 'pharmacy_facility_license',
                'label_ar' => 'ترخيص المنشأة الصيدلانية',
                'label_en' => 'Pharmacy Operating License',
                'required' => true,
                'purpose' => 'Proves authorization to operate a pharmacy business.',
            ],
            [
                'code' => 'commercial_register',
                'label_ar' => 'السجل التجاري للمؤسسة',
                'label_en' => 'Commercial Registration Certificate',
                'required' => true,
                'purpose' => 'Proves legal business entity registration.',
            ],
            [
                'code' => 'responsible_pharmacist_license',
                'label_ar' => 'ترخيص الصيدلي المسؤول',
                'label_en' => 'Responsible Pharmacist License',
                'required' => true,
                'purpose' => 'Links establishment to a licensed pharmacist in charge.',
            ],
        ];
    }

    /**
     * @return list<DecisionReason>
     */
    public static function decisionReasons(): array
    {
        return [
            [
                'code' => 'approved',
                'allowed_decisions' => ['approved'],
                'label_ar' => 'تم الاعتماد',
                'label_en' => 'Verification Approved',
                'applicant_safe_explanation' => 'تم التحقق من جميع المستندات بنجاح واعتماد الحساب.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
            [
                'code' => 'docs_blurry_or_illegible',
                'allowed_decisions' => ['changes_requested'],
                'label_ar' => 'المستندات غير واضحة',
                'label_en' => 'Documents Illegible',
                'applicant_safe_explanation' => 'صورة المستند المقدم غير واضحة أو يتعذر قراءة البيانات منها. يرجى إعادة رفع نسخة جيدة.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
            [
                'code' => 'missing_required_docs',
                'allowed_decisions' => ['changes_requested'],
                'label_ar' => 'نقص في المستندات المطلوبة',
                'label_en' => 'Required Documents Missing',
                'applicant_safe_explanation' => 'يرجى إرفاق كافة المستندات الإلزامية المطلوبة لإتمام عملية التقييم.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
            [
                'code' => 'identity_mismatch',
                'allowed_decisions' => ['changes_requested'],
                'label_ar' => 'عدم مطابقة بيانات الهوية',
                'label_en' => 'Identity Details Mismatch',
                'applicant_safe_explanation' => 'البيانات المدخلة في الطلب لا تتطابق مع البيانات الموجودة في المستندات المرفقة.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
            [
                'code' => 'license_expired',
                'allowed_decisions' => ['changes_requested'],
                'label_ar' => 'الترخيص أو الهوية منتهية',
                'label_en' => 'Document Expired',
                'applicant_safe_explanation' => 'المستند المرفق (الترخيص أو الهوية) منتهي الصلاحية. يرجى إرفاق مستند ساري.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
            [
                'code' => 'fraudulent_or_altered_doc',
                'allowed_decisions' => ['rejected'],
                'label_ar' => 'مستندات غير صالحة أو معدلة',
                'label_en' => 'Invalid or Fraudulent Document',
                'applicant_safe_explanation' => 'تعذر قبول الطلب بسبب وجود تلاعب أو عدم صحة البيانات في المستندات المرفقة.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
            [
                'code' => 'unauthorized_entity',
                'allowed_decisions' => ['rejected'],
                'label_ar' => 'منشأة غير مؤهلة',
                'label_en' => 'Unauthorized Entity',
                'applicant_safe_explanation' => 'المنشأة أو المتقدم لا يستوفي الشروط التنظيمية للتسجيل في المنصة.',
                'visible_to_applicant' => true,
                'reviewer_notes_allowed' => true,
            ],
        ];
    }

    /**
     * @return array<string, RequirementConfig>
     */
    public static function documentRequirementsConfig(): array
    {
        $requirements = [];
        foreach (self::doctorRequirements() as $requirement) {
            $requirements[$requirement['code']] = [
                'case_type' => 'doctor_verification',
                'required' => $requirement['required'],
                'label_ar' => $requirement['label_ar'],
                'label_en' => $requirement['label_en'],
                'proof_description' => $requirement['proof_description'],
                'visibility' => $requirement['visibility'],
            ];
        }
        foreach (self::pharmacyRequirements() as $requirement) {
            $requirements[$requirement['code']] = [
                'case_type' => 'pharmacy_verification',
                'required' => $requirement['required'],
                'label_ar' => $requirement['label_ar'],
                'label_en' => $requirement['label_en'],
                'purpose' => $requirement['purpose'],
            ];
        }

        return $requirements;
    }

    /**
     * @return array<string, ReasonConfig>
     */
    public static function reasonCodesConfig(): array
    {
        $reasons = [];
        foreach (self::decisionReasons() as $reason) {
            $reasons[$reason['code']] = [
                'allowed_decisions' => $reason['allowed_decisions'],
                'label_ar' => $reason['label_ar'],
                'label_en' => $reason['label_en'],
                'applicant_safe_explanation' => $reason['applicant_safe_explanation'],
                'visible_to_applicant' => $reason['visible_to_applicant'],
                'reviewer_notes_allowed' => $reason['reviewer_notes_allowed'],
            ];
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    public static function doctorRequirementCodes(): array
    {
        return array_map(static fn (array $requirement): string => $requirement['code'], self::doctorRequirements());
    }

    /**
     * @return list<string>
     */
    public static function pharmacyRequirementCodes(): array
    {
        return array_map(static fn (array $requirement): string => $requirement['code'], self::pharmacyRequirements());
    }

    /**
     * @return list<string>
     */
    public static function reasonCodes(): array
    {
        return array_map(static fn (array $reason): string => $reason['code'], self::decisionReasons());
    }

    public static function applicantSafeExplanation(?string $reasonCode): ?string
    {
        if ($reasonCode === null || $reasonCode === '') {
            return null;
        }

        foreach (self::decisionReasons() as $reason) {
            if ($reason['code'] === $reasonCode && $reason['visible_to_applicant'] === true) {
                return $reason['applicant_safe_explanation'];
            }
        }

        return null;
    }
}
