<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

use JsonException;
use RuntimeException;

/**
 * Loader for the Controller-frozen Phase 02 Profile-Claim Policy v1.0.0.
 * This is an evidence oracle, not a live ceremony implementation.
 */
final class Phase02ProfileClaimPolicyArtifact
{
    public const VERSION = 'v1.0.0-phase02';

    public const TITLE = 'Phase 02 Profile Claim Policy';

    public const RELEASE_DATE = '2026-09-28';

    public const DECISION = 'HYBRID_PROFILE_CLAIM';

    public const DECISION_STATE = 'CONTROLLER_POLICY_V1_FROZEN';

    public const RELATIVE_JSON = 'docs/evidence/phase-02/reference-data/phase02-profile-claim-policy.v1.0.0-phase02.json';

    public const RELATIVE_SHA256 = 'docs/evidence/phase-02/reference-data/phase02-profile-claim-policy.v1.0.0-phase02.sha256';

    public const RELATIVE_EVIDENCE = 'docs/evidence/phase-02/p02-audit-005-profile-claim-policy.md';

    public const RELATIVE_THREAT_MODEL = 'docs/threat-models/phase-02-onboarding.md';

    public const RELATIVE_SF001 = 'infra/security/exceptions/SF-001.json';

    public const RELATIVE_CORRECTION_POLICY = 'docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.2-phase02.json';

    public const CROCKFORD_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const DISPLAY_FORMAT = 'XXXX-XXXX-XXXX-XXXX';

    public const PUBLISHED_SHA256 = '36a7a20cc96b18cf421209ec69de5b0554a59d0a3f8ddbfd85a396309ae5050a';

    /** @var list<string> */
    public const DECISION_IDS = [
        'PC-001', 'PC-002', 'PC-003', 'PC-004', 'PC-005', 'PC-006',
        'PC-007', 'PC-008', 'PC-009', 'PC-010', 'PC-011', 'PC-012',
        'PC-013', 'PC-014', 'PC-015', 'PC-016', 'PC-017', 'PC-018',
        'PC-019', 'PC-020', 'PC-021', 'PC-022',
    ];

    /** @var list<string> */
    public const PROHIBITED_CLIENT_STATES = [
        'wrong_claim_code',
        'profile_exists',
        'profile_already_linked',
        'claim_code_expired',
        'national_id_not_found',
    ];

    /** @var list<string> */
    public const REJECTED_INDEPENDENT_PROOF = [
        'date_of_birth',
        'full_name',
        'gender',
        'blood_type',
        'other_demographic_data_printed_on_or_derivable_from_national_id',
        'national_id_plus_otp_alone',
        'patient_verification_document_upload_as_v1_high_confidence_path',
    ];

    /** @var list<string> */
    public const PLAINTEXT_PROHIBITED_IN = [
        'persistence',
        'logs',
        'urls',
        'audit_metadata',
        'events',
        'metrics',
        'analytics',
        'telemetry',
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
        return self::decodeObject(self::rawJson(), 'Profile-claim policy artifact');
    }

    public static function rawJson(): string
    {
        return self::readFile(self::jsonPath(), 'Missing profile-claim policy artifact: ');
    }

    public static function evidenceRaw(): string
    {
        return self::readFile(self::evidencePath(), 'Missing profile-claim evidence document: ');
    }

    public static function recordedSha256(): string
    {
        return trim(self::readFile(self::sha256Path(), 'Missing profile-claim policy SHA-256 companion: '));
    }

    public static function computedSha256(): string
    {
        return hash('sha256', self::rawJson());
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, array<string, mixed>>
     */
    public static function decisionsById(array $artifact): array
    {
        $decisions = $artifact['decisions'] ?? null;
        if (! is_array($decisions)) {
            throw new RuntimeException('Policy decisions list is missing.');
        }

        $byId = [];
        foreach ($decisions as $decision) {
            if (! is_array($decision) || ! isset($decision['id']) || ! is_string($decision['id'])) {
                throw new RuntimeException('Policy decision is missing an id.');
            }
            if (isset($byId[$decision['id']])) {
                throw new RuntimeException('Duplicate policy decision id: '.$decision['id']);
            }
            $byId[$decision['id']] = $decision;
        }

        return $byId;
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

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label.' is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must decode to an object.');
        }

        return $decoded;
    }

    private static function readFile(string $path, string $missingPrefix): string
    {
        if (! is_file($path)) {
            throw new RuntimeException($missingPrefix.$path);
        }

        return (string) file_get_contents($path);
    }
}
