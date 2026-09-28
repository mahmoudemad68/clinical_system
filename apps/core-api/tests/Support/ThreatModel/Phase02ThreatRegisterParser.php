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
     * @param  list<array{id: string, title: string, fields: array<string, string>, status: string}>  $threats
     * @return array{id: string, title: string, fields: array<string, string>, status: string}|null
     */
    public function findThreat(array $threats, string $id): ?array
    {
        foreach ($threats as $threat) {
            if ($threat['id'] === $id) {
                return $threat;
            }
        }

        return null;
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
     * @return list<array{MITIGATED: int, PARTIAL: int, OPEN: int, NOT_APPLICABLE: int, TOTAL: int}>
     */
    public function parseAllPublishedStatusCounts(string $markdown): array
    {
        if (preg_match_all(
            '/STATUS_COUNTS\s+MITIGATED=(\d+)\s+PARTIAL=(\d+)\s+OPEN=(\d+)\s+NOT_APPLICABLE=(\d+)\s+TOTAL=(\d+)/',
            $markdown,
            $matches,
            PREG_SET_ORDER,
        ) === false) {
            return [];
        }

        $published = [];
        foreach ($matches as $match) {
            $published[] = [
                'MITIGATED' => (int) $match[1],
                'PARTIAL' => (int) $match[2],
                'OPEN' => (int) $match[3],
                'NOT_APPLICABLE' => (int) $match[4],
                'TOTAL' => (int) $match[5],
            ];
        }

        return $published;
    }

    /**
     * Parse `| STATUS | N |` rollup rows (register table and evidence completeness).
     *
     * @return list<array{status: string, count: int}>
     */
    public function parseLabeledStatusCounts(string $markdown): array
    {
        $rows = [];
        foreach (['MITIGATED', 'PARTIAL', 'OPEN', 'NOT_APPLICABLE'] as $status) {
            if (preg_match_all('/^\| '.$status.' \| (\d+) \|/m', $markdown, $matches) === false) {
                continue;
            }
            foreach ($matches[1] as $count) {
                $rows[] = [
                    'status' => $status,
                    'count' => (int) $count,
                ];
            }
        }
        if (preg_match_all('/^\| \*\*Total\*\* \| \*\*(\d+)\*\* \|/m', $markdown, $totals) !== false) {
            foreach ($totals[1] as $count) {
                $rows[] = [
                    'status' => 'TOTAL',
                    'count' => (int) $count,
                ];
            }
        }
        if (preg_match_all('/^\| Threats \| (\d+) \|/m', $markdown, $threatTotals) !== false) {
            foreach ($threatTotals[1] as $count) {
                $rows[] = [
                    'status' => 'TOTAL',
                    'count' => (int) $count,
                ];
            }
        }

        return $rows;
    }

    /**
     * Expand ID cells such as `T01–T11, T13, T40–T45` into P02-Txx ids.
     *
     * @return list<string>
     */
    public function expandThreatIdCell(string $cell): array
    {
        $ids = [];
        if (preg_match_all('/T(\d+)(?:\s*[–—-]\s*T(\d+))?/u', $cell, $matches, PREG_SET_ORDER) === false) {
            return $ids;
        }
        foreach ($matches as $match) {
            $start = (int) $match[1];
            $end = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : $start;
            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }
            for ($n = $start; $n <= $end; $n++) {
                $ids[] = sprintf('%s%02d', self::ID_PREFIX, $n);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, list<string>>
     */
    public function parseStatusTableIds(string $markdown): array
    {
        $section = $this->sectionAfter($markdown, '/^## Status counts\b/m');
        $idsByStatus = [];
        foreach (['MITIGATED', 'PARTIAL', 'OPEN', 'NOT_APPLICABLE'] as $status) {
            if (preg_match('/^\| '.$status.' \| \d+ \| ([^|]+) \|/m', $section, $match) === 1) {
                $idsByStatus[$status] = $this->expandThreatIdCell($match[1]);
            }
        }

        return $idsByStatus;
    }

    private function sectionAfter(string $markdown, string $startPattern): string
    {
        if (preg_match($startPattern, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = (int) $match[0][1];
        $rest = substr($markdown, $start);
        $nl = strpos($rest, "\n");
        $afterHeading = $nl === false ? '' : substr($rest, $nl + 1);
        if (preg_match('/^## /m', $afterHeading, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $nl + 1 + (int) $next[0][1]);
        }

        return $rest;
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
