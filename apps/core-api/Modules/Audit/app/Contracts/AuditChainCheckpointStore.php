<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

/**
 * Durable store for signed audit-chain checkpoints, outside PostgreSQL.
 *
 * Implementations may use a local disk, object storage, or any Laravel disk.
 * A local filesystem is not an immutable production store.
 */
interface AuditChainCheckpointStore
{
    public function put(string $name, string $contents): void;

    public function exists(string $name): bool;

    /**
     * @return list<array{name: string, contents: string}>
     */
    public function all(): array;
}
