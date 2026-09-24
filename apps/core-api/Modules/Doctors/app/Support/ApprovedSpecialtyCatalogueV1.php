<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

/**
 * Versioned Phase 02 medical-specialty catalogue v1.0.0-phase02.
 *
 * Domain-approved labels and codes come from the immutable evidence artifact.
 * Persistence UUIDv7 identifiers were generated once and hard-coded here so
 * every environment receives the same internal ids. Those ids are not part of
 * the domain-approved source JSON.
 *
 * These are product-approved specialty labels, not government or syndicate
 * certification claims.
 *
 * @phpstan-type ApprovedSpecialtyRow array{
 *     id: string,
 *     code: string,
 *     label_ar: string,
 *     label_en: string,
 *     active: bool,
 *     sort_order: int
 * }
 */
final class ApprovedSpecialtyCatalogueV1
{
    public const VERSION = 'v1.0.0-phase02';

    public const APPROVED_BY = 'Medical Operations & Clinical Informatics Governance Team';

    public const RELEASE_DATE = '2026-09-24';

    public const ROW_COUNT = 30;

    public const ARTIFACT_RELATIVE_PATH = 'docs/evidence/phase-02/reference-data/approved-medical-specialties.v1.0.0-phase02.json';

    public const UUID_MAP_RELATIVE_PATH = 'docs/evidence/phase-02/reference-data/approved-medical-specialties.v1.0.0-phase02.uuid-map.json';

    public const ARTIFACT_SHA256_RELATIVE_PATH = 'docs/evidence/phase-02/reference-data/approved-medical-specialties.v1.0.0-phase02.sha256';

    public const ARTIFACT_SHA256 = 'e58a8a93aa938c9e270e6835b5867ec8a3c58e2429ac753413e8c1bd4fecf5fc';

    public const MIGRATION = '2026_09_24_055405_install_approved_specialty_catalogue_v1_0_0_phase02';

    public const CODE_FORMAT = '/^[a-z0-9_]+$/';

    public static function repositoryRoot(): string
    {
        return dirname(base_path(), 2);
    }

    public static function artifactPath(): string
    {
        return self::repositoryRoot().DIRECTORY_SEPARATOR.self::ARTIFACT_RELATIVE_PATH;
    }

    public static function uuidMapPath(): string
    {
        return self::repositoryRoot().DIRECTORY_SEPARATOR.self::UUID_MAP_RELATIVE_PATH;
    }

    public static function artifactSha256Path(): string
    {
        return self::repositoryRoot().DIRECTORY_SEPARATOR.self::ARTIFACT_SHA256_RELATIVE_PATH;
    }

    /**
     * @return list<ApprovedSpecialtyRow>
     */
    public static function rows(): array
    {
        return [
            [
                'id' => '01a0d1f8-e111-78f3-8010-6886b150097f',
                'code' => 'family_medicine',
                'label_ar' => 'طب الأسرة',
                'label_en' => 'Family Medicine',
                'active' => true,
                'sort_order' => 10,
            ],
            [
                'id' => '01a0d1f8-e111-7943-8010-6886b1a5829f',
                'code' => 'internal_medicine',
                'label_ar' => 'الطب الباطني',
                'label_en' => 'Internal Medicine',
                'active' => true,
                'sort_order' => 20,
            ],
            [
                'id' => '01a0d1f8-e111-7973-8010-6886b1dc0378',
                'code' => 'pediatrics',
                'label_ar' => 'طب الأطفال',
                'label_en' => 'Pediatrics',
                'active' => true,
                'sort_order' => 30,
            ],
            [
                'id' => '01a0d1f8-e111-797b-8010-6886b244e353',
                'code' => 'obstetrics_gynecology',
                'label_ar' => 'النساء والتوليد',
                'label_en' => 'Obstetrics & Gynecology',
                'active' => true,
                'sort_order' => 40,
            ],
            [
                'id' => '01a0d1f8-e111-7983-8010-6886b2e4af0f',
                'code' => 'general_surgery',
                'label_ar' => 'الجراحة العامة',
                'label_en' => 'General Surgery',
                'active' => true,
                'sort_order' => 50,
            ],
            [
                'id' => '01a0d1f8-e111-7987-8010-6886b3caae06',
                'code' => 'cardiology',
                'label_ar' => 'أمراض القلب والأوعية الدموية',
                'label_en' => 'Cardiology',
                'active' => true,
                'sort_order' => 60,
            ],
            [
                'id' => '01a0d1f8-e111-798b-8010-6886b48823a7',
                'code' => 'orthopedics',
                'label_ar' => 'جراحة العظام',
                'label_en' => 'Orthopedics',
                'active' => true,
                'sort_order' => 70,
            ],
            [
                'id' => '01a0d1f8-e111-798f-8010-6886b4fe667d',
                'code' => 'dermatology',
                'label_ar' => 'الأمراض الجلدية وتجميل الجلد',
                'label_en' => 'Dermatology',
                'active' => true,
                'sort_order' => 80,
            ],
            [
                'id' => '01a0d1f8-e111-7993-8010-6886b538c5dd',
                'code' => 'ophthalmology',
                'label_ar' => 'طب وجراحة العيون',
                'label_en' => 'Ophthalmology',
                'active' => true,
                'sort_order' => 90,
            ],
            [
                'id' => '01a0d1f8-e111-7997-8010-6886b59783a3',
                'code' => 'ear_nose_throat',
                'label_ar' => 'الأنف والأذن والحنجرة',
                'label_en' => 'Ear, Nose & Throat (ENT)',
                'active' => true,
                'sort_order' => 100,
            ],
            [
                'id' => '01a0d1f8-e111-799b-8010-6886b5accee0',
                'code' => 'neurology',
                'label_ar' => 'أمراض المخ والأعصاب',
                'label_en' => 'Neurology',
                'active' => true,
                'sort_order' => 110,
            ],
            [
                'id' => '01a0d1f8-e111-799f-8010-6886b699b26c',
                'code' => 'neurosurgery',
                'label_ar' => 'جراحة المخ والأعصاب',
                'label_en' => 'Neurosurgery',
                'active' => true,
                'sort_order' => 120,
            ],
            [
                'id' => '01a0d1f8-e111-79a3-8010-6886b73bf7df',
                'code' => 'psychiatry',
                'label_ar' => 'الطب النفسي',
                'label_en' => 'Psychiatry',
                'active' => true,
                'sort_order' => 130,
            ],
            [
                'id' => '01a0d1f8-e111-79a7-8010-6886b795de32',
                'code' => 'urology',
                'label_ar' => 'جراحة المسالك البولية',
                'label_en' => 'Urology',
                'active' => true,
                'sort_order' => 140,
            ],
            [
                'id' => '01a0d1f8-e111-79ab-8010-6886b80cec44',
                'code' => 'gastroenterology',
                'label_ar' => 'الجهاز الهضمي والكبد',
                'label_en' => 'Gastroenterology',
                'active' => true,
                'sort_order' => 150,
            ],
            [
                'id' => '01a0d1f8-e111-79af-8010-6886b820d17a',
                'code' => 'pulmonology',
                'label_ar' => 'الأمراض الصدرية والجهاز التنفسي',
                'label_en' => 'Pulmonology',
                'active' => true,
                'sort_order' => 160,
            ],
            [
                'id' => '01a0d1f8-e111-79b3-8010-6886b843b015',
                'code' => 'endocrinology',
                'label_ar' => 'الغدد الصماء والسكر',
                'label_en' => 'Endocrinology & Diabetes',
                'active' => true,
                'sort_order' => 170,
            ],
            [
                'id' => '01a0d1f8-e111-79bb-8010-6886b93e92fa',
                'code' => 'nephrology',
                'label_ar' => 'أمراض الكلى',
                'label_en' => 'Nephrology',
                'active' => true,
                'sort_order' => 180,
            ],
            [
                'id' => '01a0d1f8-e111-79bf-8010-6886b9b0d02d',
                'code' => 'rheumatology',
                'label_ar' => 'أمراض الروماتيزم والمفاصل',
                'label_en' => 'Rheumatology',
                'active' => true,
                'sort_order' => 190,
            ],
            [
                'id' => '01a0d1f8-e111-79c3-8010-6886b9eb41b6',
                'code' => 'oncology',
                'label_ar' => 'علاج الأورام',
                'label_en' => 'Oncology',
                'active' => true,
                'sort_order' => 200,
            ],
            [
                'id' => '01a0d1f8-e111-79c7-8010-6886ba887a15',
                'code' => 'hematology',
                'label_ar' => 'أمراض الدم',
                'label_en' => 'Hematology',
                'active' => true,
                'sort_order' => 210,
            ],
            [
                'id' => '01a0d1f8-e111-79cb-8010-6886baf063ff',
                'code' => 'allergy_immunology',
                'label_ar' => 'الحساسية والمناعة',
                'label_en' => 'Allergy & Immunology',
                'active' => true,
                'sort_order' => 220,
            ],
            [
                'id' => '01a0d1f8-e111-79cf-8010-6886bb030c6e',
                'code' => 'dentistry',
                'label_ar' => 'طب وجراحة الأسنان',
                'label_en' => 'Dentistry',
                'active' => true,
                'sort_order' => 230,
            ],
            [
                'id' => '01a0d1f8-e111-79d7-8010-6886bbf30e2b',
                'code' => 'physical_medicine_rehab',
                'label_ar' => 'الطب الطبيعي والتأهيل',
                'label_en' => 'Physical Medicine & Rehabilitation',
                'active' => true,
                'sort_order' => 240,
            ],
            [
                'id' => '01a0d1f8-e111-79df-8010-6886bc08b9f6',
                'code' => 'anesthesiology',
                'label_ar' => 'التخدير وعلاج الألم',
                'label_en' => 'Anesthesiology',
                'active' => true,
                'sort_order' => 250,
            ],
            [
                'id' => '01a0d1f8-e111-79e3-8010-6886bc548478',
                'code' => 'radiology',
                'label_ar' => 'الأشعة والتصوير الطبي',
                'label_en' => 'Radiology',
                'active' => true,
                'sort_order' => 260,
            ],
            [
                'id' => '01a0d1f8-e111-79e7-8010-6886bcb1368e',
                'code' => 'pathology',
                'label_ar' => 'علم الأمراض والتحاليل الطبية',
                'label_en' => 'Pathology & Laboratory Medicine',
                'active' => true,
                'sort_order' => 270,
            ],
            [
                'id' => '01a0d1f8-e111-79eb-8010-6886bd914c84',
                'code' => 'emergency_medicine',
                'label_ar' => 'طب الطوارئ',
                'label_en' => 'Emergency Medicine',
                'active' => true,
                'sort_order' => 280,
            ],
            [
                'id' => '01a0d1f8-e111-79ef-8010-6886bdba9260',
                'code' => 'vascular_surgery',
                'label_ar' => 'جراحة الأوعية الدموية',
                'label_en' => 'Vascular Surgery',
                'active' => true,
                'sort_order' => 290,
            ],
            [
                'id' => '01a0d1f8-e111-79f3-8010-6886be0a5e7a',
                'code' => 'plastic_surgery',
                'label_ar' => 'جراحة التجميل والترميم',
                'label_en' => 'Plastic & Reconstructive Surgery',
                'active' => true,
                'sort_order' => 300,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (array $row): string => $row['code'], self::rows());
    }

    /**
     * @return array<string, string>
     */
    public static function idsByCode(): array
    {
        $out = [];
        foreach (self::rows() as $row) {
            $out[$row['code']] = $row['id'];
        }

        return $out;
    }

    public static function idFor(string $code): string
    {
        $ids = self::idsByCode();
        if (! isset($ids[$code])) {
            throw new \InvalidArgumentException('Unknown approved specialty code.');
        }

        return $ids[$code];
    }
}
