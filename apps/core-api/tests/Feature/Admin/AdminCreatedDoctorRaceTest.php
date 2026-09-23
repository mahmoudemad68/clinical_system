<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentHttpPair;

uses(CommittedDatabaseTestCase::class);

it('creates one doctor profile and case when two Admin-created writes race', function () {
    $admin = adminVerificationInsertAdmin('race-creator');
    $body = adminCreatedDoctorApplicantBody([
        'professional_display_name' => 'Dr Race Applicant',
        'syndicate_number' => 'SYN-RACE-1',
    ]);

    $pair = ConcurrentHttpPair::run(
        [
            'op' => 'admin_create_doctor',
            'reviewer_user_id' => $admin['id'],
            'input' => $body,
        ],
        [
            'op' => 'admin_create_doctor',
            'reviewer_user_id' => $admin['id'],
            'input' => $body,
        ],
    );

    $statuses = [$pair['left']['status'], $pair['right']['status']];
    expect($statuses)->toContain(201)
        ->and($pair['left']['ok'] || $pair['right']['ok'])->toBeTrue();
    foreach ([$pair['left']['recovery_status'] ?? null, $pair['right']['recovery_status'] ?? null] as $outcome) {
        if (is_string($outcome) && $outcome !== '') {
            expect(in_array($outcome, ['created', 'already_exists'], true))->toBeTrue();
        }
    }

    expect(DB::table('doctor_profiles')->count())->toBe(1)
        ->and(DB::table('verification_cases')->count())->toBe(1)
        ->and(DB::table('users')->where('account_type', 'doctor')->count())->toBe(1);

    $doctorIds = array_values(array_unique(array_filter([
        $pair['left']['doctor_id'] ?? null,
        $pair['right']['doctor_id'] ?? null,
    ])));
    expect($doctorIds)->toHaveCount(1);
});
