<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Audit\Services\Checkpoint\AuditChainCheckpointVerifier;
use Modules\Platform\Services\Persistence\BinaryColumn;

final class PostgresAuditChainVerifier implements VerifyAuditChain
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AuditChainCheckpointVerifier $checkpoints,
    ) {}

    /**
     * @return array{ok: bool, checked: int, first_bad_sequence: int|null}
     */
    public function verifyDatabaseChain(): array
    {
        $rows = $this->connection->table('audit_events')
            ->orderBy('chain_sequence')
            ->get([
                'id',
                'event_name',
                'actor_id',
                'actor_type',
                'object_type',
                'object_id',
                'metadata',
                'previous_hash',
                'row_hash',
                'chain_sequence',
                'occurred_at',
            ]);

        $previousHex = '';
        $checked = 0;

        foreach ($rows as $row) {
            $checked++;
            $payload = $this->canonicalPayload($row->metadata);
            $actual = BinaryColumn::asString($row->row_hash);
            $matched = false;

            foreach ($this->occurredCandidates((string) $row->occurred_at) as $occurredAt) {
                $expected = hash('sha256', implode('|', [
                    $previousHex,
                    (string) $row->id,
                    (string) $row->event_name,
                    (string) $row->object_type,
                    (string) $row->object_id,
                    (string) ($row->actor_id ?? ''),
                    (string) ($row->actor_type ?? ''),
                    $payload,
                    $occurredAt,
                ]), true);

                if (hash_equals($expected, $actual)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return [
                    'ok' => false,
                    'checked' => $checked,
                    'first_bad_sequence' => (int) $row->chain_sequence,
                ];
            }

            $storedPrevious = $row->previous_hash === null ? '' : bin2hex(BinaryColumn::asString($row->previous_hash));
            if ($storedPrevious !== $previousHex) {
                return [
                    'ok' => false,
                    'checked' => $checked,
                    'first_bad_sequence' => (int) $row->chain_sequence,
                ];
            }

            $previousHex = bin2hex($actual);
        }

        return ['ok' => true, 'checked' => $checked, 'first_bad_sequence' => null];
    }

    /**
     * @return array{
     *     ok: bool,
     *     checked: int,
     *     first_bad_sequence: int|null,
     *     checkpoint_ok: bool|null,
     *     checkpoint_reason: string|null
     * }
     */
    public function verify(): array
    {
        $database = $this->verifyDatabaseChain();
        $checkpoint = $this->checkpoints->verify();
        $checkpointOk = $checkpoint['skipped'] ? null : $checkpoint['ok'];
        $ok = $database['ok'] && ($checkpointOk ?? true);

        return [
            'ok' => $ok,
            'checked' => $database['checked'],
            'first_bad_sequence' => $database['first_bad_sequence'],
            'checkpoint_ok' => $checkpointOk,
            'checkpoint_reason' => $checkpoint['reason'],
        ];
    }

    /**
     * @return list<string>
     */
    private function occurredCandidates(string $raw): array
    {
        $candidates = [$raw];

        try {
            $parsed = (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
            $candidates[] = $parsed->format('Y-m-d H:i:s.uP');
            $candidates[] = $parsed->format('Y-m-d\\TH:i:s.u\\Z');
            $candidates[] = $parsed->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            // Keep the raw driver string only.
        }

        return array_values(array_unique($candidates));
    }

    private function canonicalPayload(mixed $metadata): string
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($metadata)) {
            $metadata = [];
        }

        /** @var array<string, bool|int|float|string|null> $metadata */
        return PostgresAuditStore::canonicalMetadata($metadata);
    }
}
