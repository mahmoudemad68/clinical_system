<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Throwable;

/**
 * Programmable S3 copyObject client for conditional-copy tests.
 *
 * @internal
 */
final class FakeS3CopyClient
{
    public int $copyObjectCalls = 0;

    /** @var list<Throwable> */
    public array $failures = [];

    public ?string $bytesOnFailure = null;

    public function __construct(private readonly FakeS3Filesystem $disk) {}

    /**
     * Match Aws\S3\S3Client: CopyObject is a magic operation, not a declared method.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name !== 'copyObject') {
            throw new RuntimeException($name.' is not supported');
        }

        $args = $arguments[0] ?? [];
        if (! is_array($args)) {
            throw new RuntimeException('copyObject arguments are invalid');
        }

        $this->performCopy($args);

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function performCopy(array $args): void
    {
        $this->copyObjectCalls++;
        $destination = (string) ($args['Key'] ?? '');
        if ($this->failures !== []) {
            $failure = array_shift($this->failures);
            if (is_string($this->bytesOnFailure) && $destination !== '') {
                $this->disk->objects[$destination] = $this->bytesOnFailure;
            }

            throw $failure;
        }

        $copySource = (string) ($args['CopySource'] ?? '');
        $source = substr($copySource, strlen('clinic-test/'));
        if ($destination === '' || $source === '' || ! $this->disk->exists($source)) {
            throw new RuntimeException('copyObject source missing');
        }

        $this->disk->objects[$destination] = $this->disk->objects[$source];
    }
}
