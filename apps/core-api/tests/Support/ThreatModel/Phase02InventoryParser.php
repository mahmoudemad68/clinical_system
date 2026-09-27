<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

/**
 * Parses the Phase 02 entry-point catalog tables (HTTP, IPC, jobs).
 */
final class Phase02InventoryParser
{
    /**
     * @return list<array{number: int, method: string, path: string, identity: string}>
     */
    public function parseHttpRows(string $catalog): array
    {
        $section = $this->section($catalog, '/^## HTTP entry points\b/m');
        $rows = [];
        if (preg_match_all(
            '/^\| (\d+) \| (GET|POST|PATCH|PUT|DELETE) \| `([^`]+)` \|/m',
            $section,
            $matches,
            PREG_SET_ORDER,
        ) === false) {
            return $rows;
        }

        foreach ($matches as $match) {
            $method = strtoupper($match[2]);
            $path = $match[3];
            $rows[] = [
                'number' => (int) $match[1],
                'method' => $method,
                'path' => $path,
                'identity' => $method.' '.$path,
            ];
        }

        return $rows;
    }

    public function declaredHttpCount(string $catalog): ?int
    {
        if (preg_match('/^## HTTP entry points \((\d+)\)/m', $catalog, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @return list<string>
     */
    public function parseDoctorChannels(string $catalog): array
    {
        $section = $this->section($catalog, '/^### Doctor domain\b/m', '/^### Pharmacy domain\b/m');

        return $this->channelsIn($section, 'clinic:doctor.');
    }

    /**
     * @return list<string>
     */
    public function parsePharmacyChannels(string $catalog): array
    {
        $section = $this->section($catalog, '/^### Pharmacy domain\b/m', '/^## Non-HTTP security entry points\b/m');

        return $this->channelsIn($section, 'clinic:pharmacy.');
    }

    public function declaredDoctorCount(string $catalog): ?int
    {
        if (preg_match('/Doctor domain[^\n]*\((\d+)\)/', $catalog, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    public function declaredPharmacyCount(string $catalog): ?int
    {
        if (preg_match('/Pharmacy domain[^\n]*\((\d+)\)/', $catalog, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    public function declaredDomainIpcCount(string $catalog): ?int
    {
        if (preg_match('/Electron IPC entry points \((\d+) domain/', $catalog, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    public function hasJobsSection(string $catalog): bool
    {
        return preg_match('/^## Jobs \/ storage \/ scanner/m', $catalog) === 1;
    }

    public function jobsSection(string $catalog): string
    {
        return $this->section($catalog, '/^## Jobs \/ storage \/ scanner/m');
    }

    /**
     * @return list<string>
     */
    private function channelsIn(string $section, string $prefix): array
    {
        $channels = [];
        if (preg_match_all('/`('.preg_quote($prefix, '/').'[^`]+)`/', $section, $matches) === false) {
            return $channels;
        }
        foreach ($matches[1] as $channel) {
            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    private function section(string $markdown, string $startPattern, ?string $endPattern = null): string
    {
        if (preg_match($startPattern, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = (int) $match[0][1];
        $rest = substr($markdown, $start);
        $nl = strpos($rest, "\n");
        $afterHeading = $nl === false ? '' : substr($rest, $nl + 1);
        if ($endPattern !== null && preg_match($endPattern, $afterHeading, $end, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $nl + 1 + (int) $end[0][1]);
        }
        if (preg_match('/^## /m', $afterHeading, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $nl + 1 + (int) $next[0][1]);
        }

        return $rest;
    }
}
