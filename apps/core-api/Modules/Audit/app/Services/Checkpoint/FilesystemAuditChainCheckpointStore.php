<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Checkpoint;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Audit\Contracts\AuditChainCheckpointStore;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;
use Throwable;

/**
 * Checkpoint objects on a configurable Laravel disk. Not PostgreSQL.
 *
 * Production should use a disk that the database owner does not control
 * (object-lock/WORM or equivalent). The default local disk is evidence-only.
 */
final class FilesystemAuditChainCheckpointStore implements AuditChainCheckpointStore
{
    public function __construct(private readonly FilesystemFactory $filesystems) {}

    public function put(string $name, string $contents): void
    {
        $this->assertSafeName($name);
        $path = $this->path($name);
        $temporary = $path.'.tmp';

        try {
            $disk = $this->disk();
            $written = $disk->put($temporary, $contents);
            if ($written === false) {
                throw new AuditChainCheckpointFailed('checkpoint store rejected the write', 'checkpoint_store_unavailable');
            }

            if ($disk->exists($path)) {
                $disk->delete($temporary);
                throw new AuditChainCheckpointFailed('checkpoint object name already exists', 'checkpoint_store_unavailable');
            }

            $moved = $disk->move($temporary, $path);
            if ($moved === false) {
                $disk->delete($temporary);
                throw new AuditChainCheckpointFailed('checkpoint store could not finalize the object', 'checkpoint_store_unavailable');
            }
        } catch (AuditChainCheckpointFailed $e) {
            throw $e;
        } catch (Throwable) {
            try {
                $this->disk()->delete($temporary);
            } catch (Throwable) {
                // Best-effort cleanup of a partial object. Never a valid checkpoint.
            }

            throw new AuditChainCheckpointFailed('checkpoint store write failed', 'checkpoint_store_unavailable');
        }
    }

    public function exists(string $name): bool
    {
        $this->assertSafeName($name);

        try {
            return $this->disk()->exists($this->path($name));
        } catch (Throwable) {
            throw new AuditChainCheckpointFailed('checkpoint store is unavailable', 'checkpoint_store_unavailable');
        }
    }

    public function all(): array
    {
        try {
            $disk = $this->disk();
            $files = $disk->files($this->prefix());
        } catch (Throwable) {
            throw new AuditChainCheckpointFailed('checkpoint store is unavailable', 'checkpoint_store_unavailable');
        }

        sort($files);

        $items = [];
        foreach ($files as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }

            try {
                $contents = $disk->get($path);
            } catch (Throwable) {
                throw new AuditChainCheckpointFailed('checkpoint object could not be read', 'checkpoint_store_unavailable');
            }

            if (! is_string($contents) || $contents === '') {
                throw new AuditChainCheckpointFailed('checkpoint object is empty', 'checkpoint_malformed');
            }

            $items[] = [
                'name' => $path,
                'contents' => $contents,
            ];
        }

        return $items;
    }

    private function disk(): Filesystem
    {
        $name = (string) config('audit.checkpoint.disk', 'audit_checkpoints');
        if ($name === '') {
            throw new AuditChainCheckpointFailed('checkpoint disk is not configured', 'checkpoint_store_unavailable');
        }

        return $this->filesystems->disk($name);
    }

    private function prefix(): string
    {
        $prefix = trim((string) config('audit.checkpoint.prefix', 'checkpoints'), '/');
        if ($prefix === '' || $prefix === '.' || str_contains($prefix, '..')) {
            throw new AuditChainCheckpointFailed('checkpoint prefix is invalid', 'checkpoint_store_unavailable');
        }

        return $prefix;
    }

    private function path(string $name): string
    {
        return $this->prefix().'/'.$name;
    }

    private function assertSafeName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+\.json$/', $name) !== 1) {
            throw new AuditChainCheckpointFailed('checkpoint object name is invalid', 'checkpoint_store_unavailable');
        }
    }
}
