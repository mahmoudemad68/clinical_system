<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

/**
 * Structural parser for the Phase 02 threat register Markdown tables.
 * Prose mentions of an ID are not a row.
 */
final class Phase02ThreatRegisterParser
{
    public const ID_PREFIX = 'P02-T';

    public const FIRST_ID = 1;

    public const LAST_ID = 52;

    /** @var list<string> */
    public const ALLOWED_STATUSES = ['MITIGATED', 'PARTIAL', 'OPEN', 'NOT_APPLICABLE'];

    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'asset/flow',
        'attacker',
        'category',
        'abuse scenario',
        'existing control',
        'evidence',
        'residual risk',
        'status',
        'owner/follow-up',
    ];

    /**
     * @return list<string>
     */
    public static function expectedIds(): array
    {
        $ids = [];
        for ($n = self::FIRST_ID; $n <= self::LAST_ID; $n++) {
            $ids[] = sprintf('%s%02d', self::ID_PREFIX, $n);
        }

        return $ids;
    }

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     fields: array<string, string>,
     *     status: string
     * }>
     */
    public function parseThreats(string $markdown): array
    {
        $threats = [];
        if (preg_match_all(
            '/^### (P02-T\d+)\s+(?:[\x{2013}\x{2014}\-]\s+)?(.+)$/mu',
            $markdown,
            $headings,
            PREG_OFFSET_CAPTURE,
        ) !== false) {
            $count = count($headings[1]);
            for ($i = 0; $i < $count; $i++) {
                $id = $headings[1][$i][0];
                $title = trim($headings[2][$i][0]);
                $start = (int) $headings[0][$i][1];
                $end = $i + 1 < $count
                    ? (int) $headings[0][$i + 1][1]
                    : strlen($markdown);
                $block = substr($markdown, $start, $end - $start);
                $fields = $this->parseFieldTable($block);
                $status = $this->normalizeStatus($fields['status'] ?? '');
                $threats[] = [
                    'id' => $id,
                    'title' => $title,
                    'fields' => $fields,
                    'status' => $status,
                ];
            }
        }

        return $threats;
    }

    /**
     * @param  list<array{status: string}>  $threats
     * @return array{MITIGATED: int, PARTIAL: int, OPEN: int, NOT_APPLICABLE: int, TOTAL: int}
     */
    public function deriveStatusCounts(array $threats): array
    {
        $counts = [
            'MITIGATED' => 0,
            'PARTIAL' => 0,
            'OPEN' => 0,
            'NOT_APPLICABLE' => 0,
        ];
        foreach ($threats as $threat) {
            $status = $threat['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $counts['TOTAL'] = count($threats);

        return $counts;
    }

    /**
     * @return array{MITIGATED: int, PARTIAL: int, OPEN: int, NOT_APPLICABLE: int, TOTAL: int}|null
     */
    public function parsePublishedStatusCounts(string $markdown): ?array
    {
        if (preg_match(
            '/STATUS_COUNTS\s+MITIGATED=(\d+)\s+PARTIAL=(\d+)\s+OPEN=(\d+)\s+NOT_APPLICABLE=(\d+)\s+TOTAL=(\d+)/',
            $markdown,
            $match,
        ) !== 1) {
            return null;
        }

        return [
            'MITIGATED' => (int) $match[1],
            'PARTIAL' => (int) $match[2],
            'OPEN' => (int) $match[3],
            'NOT_APPLICABLE' => (int) $match[4],
            'TOTAL' => (int) $match[5],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseFieldTable(string $block): array
    {
        $fields = [];
        if (preg_match_all('/^\| ([^|]+?) \| ([^|]*) \|$/m', $block, $rows, PREG_SET_ORDER) === false) {
            return $fields;
        }

        foreach ($rows as $row) {
            $label = trim($row[1]);
            $value = trim($row[2]);
            if ($label === 'Field' || $label === '---' || str_starts_with($label, '---')) {
                continue;
            }
            $canonical = $this->canonicalizeField($label);
            if ($canonical === null) {
                continue;
            }
            $fields[$canonical] = $value;
        }

        return $fields;
    }

    private function canonicalizeField(string $label): ?string
    {
        $key = strtolower(trim(str_replace(['*', '`'], '', $label)));

        return match (true) {
            str_contains($key, 'asset') => 'asset/flow',
            $key === 'attacker' => 'attacker',
            str_contains($key, 'stride') || $key === 'category' => 'category',
            str_contains($key, 'abuse') => 'abuse scenario',
            str_contains($key, 'existing control') || $key === 'control' => 'existing control',
            $key === 'evidence' => 'evidence',
            str_starts_with($key, 'residual') => 'residual risk',
            $key === 'status' => 'status',
            str_starts_with($key, 'owner') => 'owner/follow-up',
            default => null,
        };
    }

    private function normalizeStatus(string $raw): string
    {
        $status = strtoupper(trim(str_replace(['*', '`'], '', $raw)));
        $status = preg_replace('/\s+/', '_', $status) ?? $status;

        return $status;
    }
}
