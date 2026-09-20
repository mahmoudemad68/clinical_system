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
     * @param  array<string, mixed>  $args
     */
    public function copyObject(array $args): void
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

        $destination = (string) ($args['Key'] ?? '');
        $copySource = (string) ($args['CopySource'] ?? '');
        $source = substr($copySource, strlen('clinic-test/'));
        if ($destination === '' || $source === '' || ! $this->disk->exists($source)) {
            throw new RuntimeException('copyObject source missing');
        }

        $this->disk->objects[$destination] = $this->disk->objects[$source];
    }
}
