<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Audit;

use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use Illuminate\Database\ConnectionInterface;

/**
 * Audit trail for configuration, flag, and secret-access changes (Phase 00
 * mandatory control). Values stored here are keys and booleans, never secrets.
 */
final class ConfigChangeAuditor
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IdentityGenerator $identities,
        private readonly Clock $clock,
    ) {}

    public function record(
        string $kind,
        string $key,
        ?string $fromValue,
        ?string $toValue,
        ?string $actorKey = null,
    ): void {
        $this->connection->table('platform_config_audits')->insert([
            'id' => $this->identities->next()->value,
            'kind' => $kind,
            'key' => $key,
            'from_value' => $this->safeValue($fromValue),
            'to_value' => $this->safeValue($toValue),
            'actor_key' => $actorKey,
            'occurred_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * Audit rows hold keys and booleans, never secrets or clinical text.
     */
    private function safeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) > 32) {
            return '[withheld]';
        }

        return $value;
    }
}
