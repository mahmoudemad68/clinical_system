<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

/**
 * Application-owned HMAC for reviewer downloads. The MAC covers purpose,
 * case id, document id, and expiry only. Storage locators never enter the
 * token or the URL.
 */
final class ReviewerDocumentUrlSigner
{
    public const PURPOSE = 'verification_review_download';

    public const ROUTE = 'api.v1.verification-review-files.show';

    public function __construct(
        private readonly string $secret,
        private readonly UrlGenerator $urls,
    ) {
        if ($this->secret === '') {
            throw new InvalidValueObject('Reviewer document signing secret is not configured.');
        }
    }

    public function sign(Identifier $caseId, Identifier $documentId, DateTimeImmutable $expiresAt): string
    {
        $expires = (string) $expiresAt->getTimestamp();

        return $this->urls->route(self::ROUTE, [
            'caseId' => $caseId->value,
            'documentId' => $documentId->value,
            'expires' => $expires,
            'signature' => $this->signature($caseId->value, $documentId->value, $expires),
        ], true);
    }

    public function isValid(
        string $caseId,
        string $documentId,
        string $expires,
        string $signature,
        DateTimeImmutable $now,
    ): bool {
        if (! $this->isUuidV7($caseId) || ! $this->isUuidV7($documentId)) {
            return false;
        }
        if ($expires === '' || ! ctype_digit($expires) || strlen($expires) > 16) {
            return false;
        }
        if ($signature === '' || strlen($signature) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $signature) !== 1) {
            return false;
        }

        $expected = $this->signature($caseId, $documentId, $expires);
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $expiresAt = (int) $expires;
        $nowTs = $now->getTimestamp();
        if ($expiresAt <= $nowTs) {
            return false;
        }

        return ($expiresAt - $nowTs) <= 300;
    }

    private function signature(string $caseId, string $documentId, string $expires): string
    {
        $payload = self::PURPOSE."\n".$caseId."\n".$documentId."\n".$expires;

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
    }

    private function isUuidV7(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }
}
