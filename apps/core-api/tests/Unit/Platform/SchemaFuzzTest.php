<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\CursorScope;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Infrastructure\Pagination\HmacCursorSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Bounded rejection of hostile cursors and identifiers (Phase 00 security tests).
 */
final class SchemaFuzzTest extends TestCase
{
    /**
     * @return list<list<string>>
     */
    public static function hostileCursors(): array
    {
        return [
            [''],
            ['not-a-cursor'],
            [str_repeat('A', 4096)],
            ["..\0../etc/passwd"],
            ['{"id":"1"}'],
            ['a.b.c'],
            ["'. OR 1=1 --"],
            ['%00'],
            ["\x80\x81"],
            ['eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0.'],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function hostileIdentifiers(): array
    {
        return [
            [''],
            ['00000000-0000-4000-8000-000000000000'],
            ['not-a-uuid'],
            ['0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01; drop table users'],
            [str_repeat('0', 14)],
            ['<script>alert(1)</script>'],
            ['0199A5C8-1F2E-7C3A-9B41-2F6D0C5E7C01 extra'],
        ];
    }

    #[Test]
    #[DataProvider('hostileCursors')]
    public function hostile_cursors_are_rejected_without_echoing_the_token(string $token): void
    {
        $signer = new HmacCursorSigner('test-cursor-secret-not-a-production-key');
        $scope = CursorScope::of('fuzz.list', 'actor-a', null, [], ['id']);

        try {
            $signer->decode($token, $scope);
            $this->fail('Hostile cursor must be rejected.');
        } catch (InvalidValueObject $exception) {
            if ($token !== '') {
                $this->assertStringNotContainsString($token, $exception->getMessage());
            }
            $this->assertStringNotContainsString('script', strtolower($exception->getMessage()));
        }
    }

    #[Test]
    #[DataProvider('hostileIdentifiers')]
    public function hostile_identifiers_are_rejected_without_echoing_the_value(string $value): void
    {
        try {
            Identifier::fromString($value);
            $this->fail('Hostile identifier must be rejected.');
        } catch (InvalidValueObject $exception) {
            $this->assertSame('Identifier must be a UUID version 7.', $exception->getMessage());
            if ($value !== '') {
                $this->assertStringNotContainsString($value, $exception->getMessage());
            }
        }
    }
}
