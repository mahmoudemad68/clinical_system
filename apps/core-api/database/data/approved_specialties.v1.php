<?php

declare(strict_types=1);

/**
 * Phase 02 approved specialty reference (engineering-default).
 *
 * Source of truth: this file. Rows are the bilingual public specialty
 * vocabulary already used by Doctors tests and onboarding helpers
 * (`doctorsSeedSpecialty` / chunk-11 catalogue projections). Labels are
 * public names only. This is not a government, syndicate, board, or
 * licensing catalogue and must not be described as certified.
 *
 * Identity is migration-safe: stable UUIDv7 values and unique `code`.
 *
 * @return list<array{id: string, code: string, label_ar: string, label_en: string, sort_order: int}>
 */
return [
    [
        'id' => '0199a016-c516-7000-8000-000000000001',
        'code' => 'general_practice',
        'label_ar' => 'طب الأسرة',
        'label_en' => 'General Practice',
        'sort_order' => 10,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000002',
        'code' => 'cardiology',
        'label_ar' => 'قلب',
        'label_en' => 'Cardiology',
        'sort_order' => 20,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000003',
        'code' => 'dermatology',
        'label_ar' => 'جلدية',
        'label_en' => 'Dermatology',
        'sort_order' => 30,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000004',
        'code' => 'endocrinology',
        'label_ar' => 'غدد صماء',
        'label_en' => 'Endocrinology',
        'sort_order' => 40,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000005',
        'code' => 'gastroenterology',
        'label_ar' => 'جهاز هضمي',
        'label_en' => 'Gastroenterology',
        'sort_order' => 50,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000006',
        'code' => 'neurology',
        'label_ar' => 'مخ وأعصاب',
        'label_en' => 'Neurology',
        'sort_order' => 60,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000007',
        'code' => 'obstetrics_gynecology',
        'label_ar' => 'نساء وتوليد',
        'label_en' => 'Obstetrics and Gynecology',
        'sort_order' => 70,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000008',
        'code' => 'ophthalmology',
        'label_ar' => 'عيون',
        'label_en' => 'Ophthalmology',
        'sort_order' => 80,
    ],
    [
        'id' => '0199a016-c516-7000-8000-000000000009',
        'code' => 'orthopedics',
        'label_ar' => 'عظام',
        'label_en' => 'Orthopedics',
        'sort_order' => 90,
    ],
    [
        'id' => '0199a016-c516-7000-8000-00000000000a',
        'code' => 'otolaryngology',
        'label_ar' => 'أنف وأذن وحنجرة',
        'label_en' => 'Otolaryngology',
        'sort_order' => 100,
    ],
    [
        'id' => '0199a016-c516-7000-8000-00000000000b',
        'code' => 'pediatrics',
        'label_ar' => 'أطفال',
        'label_en' => 'Pediatrics',
        'sort_order' => 110,
    ],
    [
        'id' => '0199a016-c516-7000-8000-00000000000c',
        'code' => 'pulmonology',
        'label_ar' => 'صدر',
        'label_en' => 'Pulmonology',
        'sort_order' => 120,
    ],
];
