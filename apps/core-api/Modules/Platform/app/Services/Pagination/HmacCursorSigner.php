<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Pagination;

use Modules\Platform\Contracts\CursorSigner as CursorSignerPort;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\CursorScope;
use Modules\Platform\Support\PaginationCursor;

/**
 * HMAC-SHA256 signed cursor. The payload is not encrypted: it is opaque and
 * tamper-evident. Scope binding is what stops one actor replaying another's
 * position (PaginationCursor).
 */
final class HmacCursorSigner implements CursorSignerPort
{
    public function __construct(private readonly string $secret) {}

    public function encode(PaginationCursor $cursor): string
    {
        $payload = $this->base64Url(json_encode($cursor->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $token = $payload.'.'.$this->base64Url($this->mac($payload));

        if (strlen($token) > PaginationCursor::MAX_ENCODED_LENGTH) {
            throw new InvalidValueObject('Pagination cursor exceeds the encoded size bound.');
        }

        return $token;
    }

    public function decode(string $token, CursorScope $expectedScope): PaginationCursor
    {
        if ($token === '' || strlen($token) > PaginationCursor::MAX_ENCODED_LENGTH) {
            throw new InvalidValueObject('Pagination cursor is malformed.');
        }

        $parts = explode('.', $token, 3);

        if (count($parts) !== 2) {
            throw new InvalidValueObject('Pagination cursor is malformed.');
        }

        [$payload, $providedMac] = $parts;
        $expectedMac = $this->base64Url($this->mac($payload));

        if (! hash_equals($expectedMac, $providedMac)) {
            throw new InvalidValueObject('Pagination cursor signature is invalid.');
        }

        $json = $this->base64UrlDecode($payload);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new InvalidValueObject('Pagination cursor payload is malformed.');
        }

        return PaginationCursor::fromVerifiedPayload($decoded, $expectedScope);
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        if (! is_string($decoded)) {
            throw new InvalidValueObject('Pagination cursor payload is malformed.');
        }

        return $decoded;
    }
}
