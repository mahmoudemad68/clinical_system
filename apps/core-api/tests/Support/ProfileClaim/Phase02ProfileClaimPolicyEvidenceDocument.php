<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

/**
 * Deterministic parser for Profile-Claim Policy v1 evidence Markdown.
 */
final class Phase02ProfileClaimPolicyEvidenceDocument
{
    public function __construct(private readonly string $markdown) {}

    public function section(string $heading): string
    {
        $quoted = preg_quote($heading, '/');
        if (preg_match('/^'.$quoted.'\s*\n(.*?)(?=^## |\z)/ms', $this->markdown, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    /**
     * @return array<string, string>
     */
    public function tableMap(string $heading, string $keyHeader, string $valueHeader): array
    {
        $table = $this->firstTableInSection($heading);
        if ($table === null) {
            return [];
        }

        $keyIndex = array_search($keyHeader, $table['headers'], true);
        $valueIndex = array_search($valueHeader, $table['headers'], true);
        if (! is_int($keyIndex) || ! is_int($valueIndex)) {
            return [];
        }

        $map = [];
        foreach ($table['rows'] as $row) {
            $key = self::plain($row[$keyIndex] ?? '');
            if ($key === '') {
                continue;
            }
            $map[$key] = self::plain($row[$valueIndex] ?? '');
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    public function identityTable(): array
    {
        $table = $this->firstFieldValueTable();

        return $table ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function optionBTable(): array
    {
        return $this->tableMap('## Option B credential', 'Rule', 'Policy v1');
    }

    /**
     * @return array<string, string>
     */
    public function numericTable(): array
    {
        return $this->tableMap('## Numeric Policy v1', 'Control', 'Policy v1');
    }

    /**
     * @return array<string, array{implementation_status: string, blocks_production_enablement: bool|null}>
     */
    public function decisionMatrix(): array
    {
        $table = $this->firstTableInSection('## PC-001 through PC-022');
        if ($table === null) {
            return [];
        }

        $idIndex = array_search('ID', $table['headers'], true);
        $statusIndex = array_search('Current implementation status', $table['headers'], true);
        $blockIndex = array_search('Blocks production enablement', $table['headers'], true);
        if (! is_int($idIndex) || ! is_int($statusIndex) || ! is_int($blockIndex)) {
            return [];
        }

        $matrix = [];
        foreach ($table['rows'] as $row) {
            $id = self::plain($row[$idIndex] ?? '');
            if (! str_starts_with($id, 'PC-')) {
                continue;
            }
            $statusCell = self::plain($row[$statusIndex] ?? '');
            $status = 'UNKNOWN';
            foreach (['PARTIALLY_ENFORCED', 'NOT_IMPLEMENTED', 'ALREADY_ENFORCED', 'EVIDENCE_REQUIRED'] as $token) {
                if (str_starts_with($statusCell, $token)) {
                    $status = $token;
                    break;
                }
            }
            $blockCell = strtolower(self::plain($row[$blockIndex] ?? ''));
            $blocks = match ($blockCell) {
                'yes', 'true' => true,
                'no', 'false' => false,
                default => null,
            };
            $matrix[$id] = [
                'implementation_status' => $status,
                'blocks_production_enablement' => $blocks,
            ];
        }

        return $matrix;
    }

    /**
     * @return list<string>
     */
    public function clientMustNotLearn(): array
    {
        $section = $this->section('## Non-enumeration');
        if (preg_match('/The client must not learn:\n\n((?:- .+\n)+)/', $section, $matches) !== 1) {
            return [];
        }

        preg_match_all('/^- (.+)$/m', $matches[1], $items);

        return array_map(trim(...), $items[1]);
    }

    /**
     * @return list<string>
     */
    public function prohibitedClientStates(): array
    {
        $section = $this->section('## Non-enumeration');
        preg_match_all(
            '/`(wrong_claim_code|profile_exists|profile_already_linked|claim_code_expired|national_id_not_found)`/',
            $section,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    public function genericPending(): ?string
    {
        $section = $this->section('## Non-enumeration');
        if (preg_match('/Generic pending is `([^`]+)`/', $section, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function hiddenDenial(): ?string
    {
        $section = $this->section('## Non-enumeration');
        if (preg_match('/Hidden denial is `([^`]+)`/', $section, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function finalStatusMap(): array
    {
        $section = $this->section('## Final blocker state after this evidence PR');
        $map = [];
        if (preg_match_all('/^(?:\*{0,2}`?)([^:`\n]+?)(?::)\s*(.+?)$/m', $section, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $identity = self::plain($match[1]);
                $state = self::plain($match[2]);
                if ($identity === '' || $state === '' || str_starts_with(strtolower($identity), 'current material state')) {
                    continue;
                }
                $map[$identity] = $state;
            }
        }

        return $map;
    }

    /**
     * Positive current-state assignments for an identity. Negated historical
     * wording such as "does not close P02-AUDIT-005" is excluded.
     *
     * @return list<array{site: string, state: string}>
     */
    public function positiveStateAssignments(string $identity): array
    {
        $assignments = [];
        $identityValue = $this->lookup($this->identityTable(), $identity);
        if ($identityValue !== null) {
            $assignments[] = ['site' => 'identity_table', 'state' => $this->normalizeState($identityValue)];
        }

        $finalValue = $this->lookup($this->finalStatusMap(), $identity);
        if ($finalValue !== null) {
            $assignments[] = ['site' => 'final_status', 'state' => $this->normalizeState($finalValue)];
        }

        foreach ($this->proseAssignments($identity) as $state) {
            $assignments[] = ['site' => 'prose', 'state' => $state];
        }

        return $assignments;
    }

    /**
     * @return list<string>
     */
    public function proseAssignments(string $identity): array
    {
        $id = $this->wrappedIdentityPattern($identity);
        $states = [];
        foreach (['is', 'remains'] as $verb) {
            $verbs = $verb === 'remains'
                ? 'OPEN|CLOSED|MITIGATED'
                : 'OPEN|CLOSED|MITIGATED|NOT_AUTHORIZED|AUTHORIZED|DISABLED|PENDING_EXTERNAL|APPROVED';
            $state = '[`\*]*('.$verbs.')[`\*]*';
            if (preg_match_all('/'.$id.'\s+'.$verb.'\s+'.$state.'/i', $this->markdown, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as $index => $full) {
                    if ($this->isNegated((int) $full[1])) {
                        continue;
                    }
                    $states[] = strtoupper($matches[1][$index][0]);
                }
            }
        }

        return $states;
    }

    public function plaintextDestinations(): array
    {
        $section = preg_replace('/\s+/', ' ', $this->section('## Option B credential')) ?? '';
        if (preg_match('/Plaintext of the claim credential is prohibited in:\s*([^.]+)/', $section, $matches) !== 1) {
            return [];
        }

        $items = array_map(
            static fn (string $item): string => trim($item),
            explode(',', $matches[1]),
        );

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    public function permitsSensitiveClientState(): bool
    {
        $section = $this->section('## Non-enumeration');
        $stripped = preg_replace(
            '/Prohibited client-visible states remain[^\n]+\n(?:[^\n]+\n)?/',
            '',
            $section,
        ) ?? $section;

        return preg_match(
            '/\b(?:may|permit|return|use|introduce|allowed)\b[^.\n]{0,80}\b(wrong_claim_code|profile_exists|profile_already_linked|claim_code_expired|national_id_not_found)\b/i',
            $stripped,
        ) === 1;
    }

    public function claimsControllerFreezeIsGovernanceApproval(): bool
    {
        return preg_match(
            '/Controller freeze is (?!not\b)(Product|Security|Privacy|Support\/Operations) approval/i',
            $this->markdown,
        ) === 1;
    }

    public function hasMaterialGovernanceApproved(string $label): bool
    {
        $table = $this->identityTable();
        if (isset($table[$label]) && str_contains(strtoupper($table[$label]), 'APPROVED') && ! str_contains(strtoupper($table[$label]), 'PENDING')) {
            return true;
        }

        $quoted = preg_quote($label, '/');
        if (preg_match('/'.$quoted.'\s*(?:\||:|is|remains)\s*[`\*]*(APPROVED|GRANTED)[`\*]*/i', $this->markdown, $match, PREG_OFFSET_CAPTURE) === 1) {
            return ! $this->isNegated((int) $match[0][1]);
        }

        return false;
    }

    private function isNegated(int $offset): bool
    {
        $window = substr($this->markdown, max(0, $offset - 120), 120);

        return preg_match('/does\s+(?:\*\*)?not(?:\*\*)?\s+(?:close|change|convert|mark|claim|reuse|enable|authorize|implement)/i', $window) === 1
            || preg_match('/\*\*not\*\*\s+(?:close|change|convert|mark)/i', $window) === 1;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function lookup(array $map, string $identity): ?string
    {
        if (isset($map[$identity])) {
            return $map[$identity];
        }
        foreach ($map as $key => $value) {
            if (str_contains($key, $identity)) {
                return $value;
            }
        }

        return null;
    }

    private function wrappedIdentityPattern(string $identity): string
    {
        $id = $identity === 'T46'
            ? '(?<![A-Za-z0-9-])T46(?![A-Za-z0-9-])'
            : preg_quote($identity, '/');

        return '[`\*]*'.$id.'[`\*]*';
    }

    private function normalizeState(string $state): string
    {
        $state = self::plain($state);
        $state = preg_replace('/\s+/', ' ', $state) ?? $state;

        return $state;
    }

    /**
     * @return array<string, string>|null
     */
    private function firstFieldValueTable(): ?array
    {
        foreach ($this->tables($this->markdown) as $table) {
            if (($table['headers'][0] ?? null) === 'Field' && ($table['headers'][1] ?? null) === 'Value') {
                $map = [];
                foreach ($table['rows'] as $row) {
                    $map[self::plain($row[0] ?? '')] = self::plain($row[1] ?? '');
                }

                return $map;
            }
        }

        return null;
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>}>|null
     */
    private function firstTableInSection(string $heading): ?array
    {
        $section = $this->section($heading);
        $tables = $this->tables($section);

        return $tables[0] ?? null;
    }

    /**
     * @return list<array{headers: list<string>, rows: list<list<string>}>}
     */
    private function tables(string $markdown): array
    {
        $lines = preg_split("/\R/", $markdown) ?: [];
        $tables = [];
        $count = count($lines);
        $index = 0;
        while ($index < $count) {
            if (
                preg_match('/^\|.+\|$/', $lines[$index]) === 1
                && isset($lines[$index + 1])
                && preg_match('/^\|\s*[-: ]+\|/', $lines[$index + 1]) === 1
            ) {
                $headers = self::cells($lines[$index]);
                $index += 2;
                $rows = [];
                while ($index < $count && preg_match('/^\|.+\|$/', $lines[$index]) === 1) {
                    $rows[] = self::cells($lines[$index]);
                    $index++;
                }
                $tables[] = ['headers' => $headers, 'rows' => $rows];

                continue;
            }
            $index++;
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');

        return array_map(trim(...), explode('|', $line));
    }

    public static function plain(string $cell): string
    {
        $cell = str_replace(['**', '`'], '', $cell);
        $cell = preg_replace('/\s+/', ' ', $cell) ?? $cell;

        return trim($cell);
    }
}
