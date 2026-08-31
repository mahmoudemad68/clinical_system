<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Modules\Platform\Http\Responses\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * PHP ErrorCode cases stay identical to the OpenAPI enum (ADR 0003).
 */
final class OpenApiErrorCodeParityTest extends TestCase
{
    #[Test]
    public function php_error_codes_match_the_openapi_enum(): void
    {
        $path = dirname(__DIR__, 5).'/packages/contracts/openapi/openapi.yaml';
        $this->assertFileExists($path);

        /** @var array<string, mixed> $document */
        $document = Yaml::parseFile($path);
        $enum = $document['components']['schemas']['ErrorCode']['enum'] ?? null;

        $this->assertIsArray($enum);

        $php = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());

        $this->assertEqualsCanonicalizing($enum, $php);
    }
}
