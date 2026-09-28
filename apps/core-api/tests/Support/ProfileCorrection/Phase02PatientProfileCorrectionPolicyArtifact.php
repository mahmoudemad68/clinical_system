<?php

declare(strict_types=1);

namespace Tests\Support\ProfileCorrection;

use JsonException;
use RuntimeException;

/**
 * Loader for the immutable Phase 02 patient profile-correction policy
 * artifact. Freeze allowlists below are the controller-approved oracle,
 * not a copy of the JSON at load time.
 */
final class Phase02PatientProfileCorrectionPolicyArtifact
{
    public const RELATIVE_JSON = 'docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.0-phase02.json';

    public const RELATIVE_SHA256 = 'docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.0-phase02.sha256';

    public const RELATIVE_EVIDENCE = 'docs/evidence/phase-02/p02-audit-003-profile-correction-policy.md';

    public const RELATIVE_VERIFICATION_POLICY = 'docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.json';

    public const RELATIVE_SF001 = 'infra/security/exceptions/SF-001.json';

    /** @var list<string> */
    public const APPROVED_SELF_EDIT_ALLOWLIST = [
        'full_name',
        'gender',
        'date_of_birth',
        'height_cm',
        'weight_kg',
        'marital_status',
        'blood_type',
    ];

    /** @var list<string> */
    public const IMMUTABLE_EXCLUDED_FIELDS = [
        'national_id',
        'user_id',
        'patient_id',
        'status',
        'national_id_ciphertext',
        'national_id_lookup_hmac',
        'national_id_key_version',
        'full_name_ciphertext',
        'reason_code',
        'source_type',
    ];

    /** @var list<string> */
    public const ACCEPTED_RESIDUAL_IDS = [
        'no_server_status_active_gate',
        'no_patch_idempotency_key',
        'no_correction_velocity_control',
        'aal1_not_aal2',
        'stored_dob_may_disagree_with_nid_encoded_date',
        'full_name_supplied_always_revises',
        'non_name_revision_plaintext',
        'encrypted_historical_name_survives_erasure',
        'no_staff_assisted_correction_path',
        'no_correction_notification',
        'no_identity_reverification_after_name_or_dob_correction',
    ];

    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 5);
    }

    public static function jsonPath(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_JSON;
    }

    public static function sha256Path(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_SHA256;
    }

    public static function evidencePath(): string
    {
        return self::repositoryRoot().'/'.self::RELATIVE_EVIDENCE;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decoded(): array
    {
        $json = self::rawJson();
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Profile-correction policy artifact is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Profile-correction policy artifact must decode to an object.');
        }

        return $decoded;
    }

    public static function rawJson(): string
    {
        $path = self::jsonPath();
        if (! is_file($path)) {
            throw new RuntimeException('Missing profile-correction policy artifact: '.$path);
        }

        return (string) file_get_contents($path);
    }

    public static function recordedSha256(): string
    {
        $path = self::sha256Path();
        if (! is_file($path)) {
            throw new RuntimeException('Missing profile-correction policy SHA-256 companion: '.$path);
        }

        return trim((string) file_get_contents($path));
    }

    public static function computedSha256(): string
    {
        return hash('sha256', self::rawJson());
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    public static function stringList(array $artifact, string $path): array
    {
        $cursor = $artifact;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                throw new RuntimeException('Missing policy path: '.$path);
            }
            $cursor = $cursor[$segment];
        }

        if (! is_array($cursor)) {
            throw new RuntimeException('Policy path is not a list: '.$path);
        }

        $values = [];
        foreach ($cursor as $item) {
            if (! is_string($item)) {
                throw new RuntimeException('Policy path contains a non-string: '.$path);
            }
            $values[] = $item;
        }

        return array_values($values);
    }
}
