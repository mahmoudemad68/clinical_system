<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Exceptions\ProviderNotEnabled;
use Modules\Platform\Services\Adapters\DisabledGenerateText;
use Modules\Platform\Services\Adapters\DisabledRetrieveKnowledge;
use Modules\Platform\Services\Adapters\DisabledScanObject;
use Modules\Platform\Services\Adapters\DisabledSendOtp;
use Modules\Platform\Services\Adapters\DisabledSendPush;
use Modules\Platform\Services\ObjectStorage\InMemoryStoreObject;
use Modules\Platform\Support\StoredObjectRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Provider port contracts (G-03-05). Every future adapter must satisfy these
 * assertions, including the in-memory fake.
 */
final class ProviderPortContractTest extends TestCase
{
    #[Test]
    public function in_memory_store_keeps_objects_private_and_denies_anonymous_access(): void
    {
        $this->assertStoreObjectContract(new InMemoryStoreObject);
    }

    #[Test]
    public function disabled_adapters_fail_closed(): void
    {
        $this->expectException(ProviderNotEnabled::class);
        (new DisabledSendOtp)->send('dest-fingerprint', 'login', ['purpose' => 'login', 'locale' => 'en']);
    }

    #[Test]
    public function disabled_push_fails_closed(): void
    {
        $this->expectException(ProviderNotEnabled::class);
        (new DisabledSendPush)->send('device-fingerprint', 'generic', ['ref' => '1']);
    }

    #[Test]
    public function disabled_scan_fails_closed(): void
    {
        $this->expectException(ProviderNotEnabled::class);
        (new DisabledScanObject)->scan(new StoredObjectRef('phase00', 'object-1'));
    }

    #[Test]
    public function disabled_generate_text_fails_closed(): void
    {
        $this->expectException(ProviderNotEnabled::class);
        (new DisabledGenerateText)->generate('prompt-ref', ['deadline_ms' => 1000, 'schema_version' => 1]);
    }

    #[Test]
    public function disabled_retrieve_knowledge_fails_closed(): void
    {
        $this->expectException(ProviderNotEnabled::class);
        (new DisabledRetrieveKnowledge)->retrieve('query-ref', ['scope' => 'none', 'version' => 'v1', 'limit' => 3]);
    }

    private function assertStoreObjectContract(StoreObject $store): void
    {
        $ref = $store->put('phase00', 'object-1', 'text/plain', 'synthetic-bytes');

        $this->assertTrue($store->exists($ref));
        $meta = $store->metadata($ref);
        $this->assertSame('text/plain', $meta['content_type']);
        $this->assertSame(strlen('synthetic-bytes'), $meta['size_bytes']);
        $this->assertTrue($meta['encrypted']);

        $expires = new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC'));
        $url = $store->temporaryUrl($ref, $expires);
        $this->assertNotSame('', $url);
        $this->assertStringNotContainsString('synthetic-bytes', $url);

        $this->expectException(RuntimeException::class);
        $store->anonymousGet($ref);
    }
}
