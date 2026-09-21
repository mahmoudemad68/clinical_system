<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Test double for S3StoreObject conditional CopyObject. Not a production adapter.
 *
 * @internal
 */
final class FakeS3Filesystem implements Filesystem
{
    /** @var array<string, string> */
    public array $objects = [];

    public int $copyCalls = 0;

    public FakeS3CopyClient $client;

    /**
     * @var array<string, mixed>
     */
    public array $temporaryUploadHeaders = [
        'Host' => '127.0.0.1:19000',
        'Content-Type' => 'application/pdf',
    ];

    public string $signedUploadUrl = 'https://objects.example/upload?X-Amz-Signature=synthetic-signature';

    public function __construct()
    {
        $this->client = new FakeS3CopyClient($this);
    }

    public function getClient(): FakeS3CopyClient
    {
        return $this->client;
    }

    public function getAdapter(): object
    {
        return new class($this)
        {
            public function __construct(private FakeS3Filesystem $disk) {}

            public function getClient(): FakeS3CopyClient
            {
                return $this->disk->client;
            }
        };
    }

    /**
     * @return array{bucket: string}
     */
    public function getConfig(): array
    {
        return ['bucket' => 'clinic-test'];
    }

    /**
     * @param  mixed  $path
     * @param  mixed  $expiration
     * @param  array<string, mixed>  $options
     * @return array{url: string, headers: array<string, mixed>}
     */
    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        unset($path, $expiration, $options);

        return [
            'url' => $this->signedUploadUrl,
            'headers' => $this->temporaryUploadHeaders,
        ];
    }

    public function mimeType(string $path): string
    {
        unset($path);

        return 'application/pdf';
    }

    public function path($path)
    {
        return $path;
    }

    public function exists($path)
    {
        return array_key_exists($path, $this->objects);
    }

    public function get($path)
    {
        return $this->objects[$path] ?? null;
    }

    public function readStream($path)
    {
        if (! $this->exists($path)) {
            return null;
        }

        $stream = fopen('php://temp', 'r+');
        if (! is_resource($stream)) {
            return null;
        }
        fwrite($stream, $this->objects[$path]);
        rewind($stream);

        return $stream;
    }

    public function put($path, $contents, $options = [])
    {
        $this->objects[$path] = is_string($contents) ? $contents : '';

        return true;
    }

    public function putFile($path, $file = null, $options = [])
    {
        throw new RuntimeException('unused');
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        throw new RuntimeException('unused');
    }

    public function writeStream($path, $resource, array $options = [])
    {
        throw new RuntimeException('unused');
    }

    public function getVisibility($path)
    {
        return Filesystem::VISIBILITY_PRIVATE;
    }

    public function setVisibility($path, $visibility)
    {
        unset($path, $visibility);

        return true;
    }

    public function prepend($path, $data)
    {
        throw new RuntimeException('unused');
    }

    public function append($path, $data)
    {
        throw new RuntimeException('unused');
    }

    public function delete($paths)
    {
        foreach ((array) $paths as $path) {
            unset($this->objects[$path]);
        }

        return true;
    }

    public function copy($from, $to)
    {
        $this->copyCalls++;
        if (! $this->exists($from)) {
            return false;
        }
        $this->objects[$to] = $this->objects[$from];

        return true;
    }

    public function move($from, $to)
    {
        throw new RuntimeException('unused');
    }

    public function size($path)
    {
        return strlen($this->objects[$path] ?? '');
    }

    public function lastModified($path)
    {
        return 0;
    }

    public function files($directory = null, $recursive = false)
    {
        return [];
    }

    public function allFiles($directory = null)
    {
        return [];
    }

    public function directories($directory = null, $recursive = false)
    {
        return [];
    }

    public function allDirectories($directory = null)
    {
        return [];
    }

    public function makeDirectory($path)
    {
        return true;
    }

    public function deleteDirectory($directory)
    {
        return true;
    }
}
