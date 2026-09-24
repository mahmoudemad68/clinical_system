<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Config-backed allowlists for Phase 02 Verification Policy v1.0.1-phase02.
 * Unknown case types, requirement codes, and reason codes deny.
 *
 * Document requirements, decision/reason pairs, MIME types, and max active
 * uploads are approved policy. Maximum bytes, upload-grant TTL, and reviewer
 * document URL TTL remain ENGINEERING_CONTROL values.
 */
final class VerificationPolicy
{
    /**
     * Historical requirement codes that may exist on already-issued upload
     * intents or already-submitted evidence. New grants and new submissions
     * do not accept them.
     *
     * @var array<string, list<string>>
     */
    private const LEGACY_RECOGNIZED_REQUIREMENTS = [
        'doctor_verification' => [ApprovedVerificationPolicyV1::LEGACY_DOCTOR_REQUIREMENT],
        'pharmacy_verification' => [ApprovedVerificationPolicyV1::LEGACY_PHARMACY_REQUIREMENT],
    ];

    public function policyVersion(): string
    {
        return (string) config('verification_module.policy_version', ApprovedVerificationPolicyV1::VERSION);
    }

    /**
     * @return list<string>
     */
    public function caseTypes(): array
    {
        /** @var list<string> $types */
        $types = config('verification_module.case_types', []);

        return $types;
    }

    public function isKnownCaseType(string $caseType): bool
    {
        return in_array($caseType, $this->caseTypes(), true);
    }

    public function maxDocumentBytes(): int
    {
        return (int) config('verification_module.max_document_bytes', 20_971_520);
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        /** @var list<string> $types */
        $types = config('verification_module.allowed_mime_types', []);

        return $types;
    }

    public function isAllowedMime(string $mime): bool
    {
        return in_array($mime, $this->allowedMimeTypes(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementMeta(string $code): ?array
    {
        $requirements = config('verification_module.document_requirements', []);
        if (! is_array($requirements) || ! isset($requirements[$code]) || ! is_array($requirements[$code])) {
            return null;
        }

        return $requirements[$code];
    }

    /**
     * @return list<string>
     */
    public function knownRequirementCodes(string $caseType): array
    {
        $requirements = config('verification_module.document_requirements', []);
        if (! is_array($requirements)) {
            return [];
        }

        $codes = [];
        foreach ($requirements as $code => $meta) {
            if (! is_string($code) || ! is_array($meta)) {
                continue;
            }
            if (($meta['case_type'] ?? null) !== $caseType) {
                continue;
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public function requiredRequirementCodes(string $caseType): array
    {
        $codes = [];
        foreach ($this->knownRequirementCodes($caseType) as $code) {
            $meta = $this->requirementMeta($code);
            if (is_array($meta) && ($meta['required'] ?? false) === true) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function isKnownRequirement(string $caseType, string $code): bool
    {
        $meta = $this->requirementMeta($code);
        if ($meta === null) {
            return false;
        }

        return ($meta['case_type'] ?? null) === $caseType;
    }

    public function isOptionalRequirement(string $caseType, string $code): bool
    {
        if (! $this->isKnownRequirement($caseType, $code)) {
            return false;
        }
        $meta = $this->requirementMeta($code);

        return is_array($meta) && ($meta['required'] ?? false) !== true;
    }

    /**
     * Accepts v1.0.1 codes and historical in-flight codes so already-issued
     * scans can complete. New grants still use isKnownRequirement().
     */
    public function isRecognizedRequirement(string $caseType, string $code): bool
    {
        if ($this->isKnownRequirement($caseType, $code)) {
            return true;
        }

        $legacy = self::LEGACY_RECOGNIZED_REQUIREMENTS[$caseType] ?? [];

        return in_array($code, $legacy, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reasonMeta(string $reasonCode): ?array
    {
        $reasons = config('verification_module.reason_codes', []);
        if (! is_array($reasons) || ! isset($reasons[$reasonCode]) || ! is_array($reasons[$reasonCode])) {
            return null;
        }

        return $reasons[$reasonCode];
    }

    /**
     * @return list<string>
     */
    public function allowedDecisionsForReason(string $reasonCode): array
    {
        $meta = $this->reasonMeta($reasonCode);
        if ($meta === null) {
            return [];
        }

        $allowed = $meta['allowed_decisions'] ?? null;
        if (! is_array($allowed)) {
            return [];
        }

        /** @var list<string> $values */
        $values = array_values(array_filter($allowed, 'is_string'));

        return $values;
    }

    public function reasonAllowsDecision(string $reasonCode, string $decision): bool
    {
        return in_array($decision, $this->allowedDecisionsForReason($reasonCode), true);
    }

    public function applicantSafeExplanation(?string $reasonCode): ?string
    {
        if ($reasonCode === null || $reasonCode === '') {
            return null;
        }

        $meta = $this->reasonMeta($reasonCode);
        if ($meta === null) {
            return null;
        }
        if (($meta['visible_to_applicant'] ?? false) !== true) {
            return null;
        }
        $explanation = $meta['applicant_safe_explanation'] ?? null;

        return is_string($explanation) && $explanation !== '' ? $explanation : null;
    }

    public function notesMaxLength(): int
    {
        return (int) config('verification_module.notes_max_length', 2000);
    }

    public function uploadExpirySeconds(): int
    {
        return (int) config('verification_module.upload_expiry_seconds', 900);
    }

    public function maxActiveUploadsPerRequirement(): int
    {
        return (int) config('verification_module.max_active_uploads_per_requirement', 3);
    }

    public function cleanupRejectedAfterSeconds(): int
    {
        return (int) config('verification_module.cleanup_rejected_after_seconds', 86_400);
    }

    public function maxProcessingAttempts(): int
    {
        return (int) config('verification_module.max_processing_attempts', 8);
    }

    public function objectNamespace(): string
    {
        return 'verification';
    }

    public function reviewerDocumentAccessTtlSeconds(): int
    {
        return (int) config('verification_module.reviewer_document_access_ttl_seconds', 120);
    }

    public function reviewerDownloadChunkBytes(): int
    {
        return 65_536;
    }

    /**
     * Generic server-owned download name. Never the original filename and
     * never derived from request input.
     */
    public function reviewerDownloadFilename(string $detectedMime): string
    {
        return match ($detectedMime) {
            'application/pdf' => 'verification-document.pdf',
            'image/jpeg' => 'verification-document.jpg',
            'image/png' => 'verification-document.png',
            default => 'verification-document.bin',
        };
    }

    public function queueDefaultLimit(): int
    {
        return (int) config('verification_module.queue_default_limit', 25);
    }

    public function queueMaxLimit(): int
    {
        return (int) config('verification_module.queue_max_limit', 100);
    }
}
