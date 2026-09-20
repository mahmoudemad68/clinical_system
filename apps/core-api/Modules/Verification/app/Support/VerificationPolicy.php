<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Config-backed allowlists. Unknown case types, requirement codes, and reason
 * codes deny. Values are ENGINEERING_DEFAULT, not an approved product catalogue.
 */
final class VerificationPolicy
{
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
     * @return list<string>
     */
    public function requiredRequirementCodes(string $caseType): array
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
            if (($meta['required'] ?? false) === true) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function isKnownRequirement(string $caseType, string $code): bool
    {
        $requirements = config('verification_module.document_requirements', []);
        if (! is_array($requirements) || ! isset($requirements[$code]) || ! is_array($requirements[$code])) {
            return false;
        }

        return ($requirements[$code]['case_type'] ?? null) === $caseType;
    }

    /**
     * @return list<string>
     */
    public function allowedDecisionsForReason(string $reasonCode): array
    {
        $reasons = config('verification_module.reason_codes', []);
        if (! is_array($reasons) || ! isset($reasons[$reasonCode]) || ! is_array($reasons[$reasonCode])) {
            return [];
        }

        /** @var list<string> $allowed */
        $allowed = array_values(array_filter($reasons[$reasonCode], 'is_string'));

        return $allowed;
    }

    public function reasonAllowsDecision(string $reasonCode, string $decision): bool
    {
        return in_array($decision, $this->allowedDecisionsForReason($reasonCode), true);
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
