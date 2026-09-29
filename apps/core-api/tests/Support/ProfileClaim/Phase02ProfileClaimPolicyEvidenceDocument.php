<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

/**
 * Deterministic parser for Profile-Claim Policy v1 evidence Markdown.
 */
final class Phase02ProfileClaimPolicyEvidenceDocument
{
    /** @var list<string> */
    public const SENSITIVE_CLIENT_STATES = [
        'wrong_claim_code',
        'profile_exists',
        'profile_already_linked',
        'claim_code_expired',
        'national_id_not_found',
    ];

    /** @var list<string> */
    public const PC020_MARKDOWN_PREREQUISITES = [
        'policy recorded',
        'ceremony implementation complete',
        'tests complete',
        'observability complete',
        'monitored rollout/cohort controls ready',
        'verified kill switch',
        'external governance approvals',
        'independent engineering QA',
        'applicable independent-human gates',
    ];

    /** @var list<string> */
    public const GUARDED_IDENTITIES = [
        'P02-AUDIT-005',
        'T46',
        'P02-AUDIT-006',
        'P02-AUDIT-007',
        'G-08-04',
        'Production enablement',
        'Feature state',
    ];

    public function __construct(private readonly string $markdown) {}

    public static function normalizeEmphasis(string $text): string
    {
        $current = $text;
        $previous = null;
        while ($previous !== $current) {
            $previous = $current;
            $current = str_replace('`', '', $current);
            $current = preg_replace('/\*\*(.+?)\*\*/s', '$1', $current) ?? $current;
            $current = preg_replace('/__(.+?)__/s', '$1', $current) ?? $current;
            $current = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '$1', $current) ?? $current;
            $current = preg_replace('/(?<![A-Za-z0-9])_(?!_)(.+?)(?<!_)_(?![A-Za-z0-9])/s', '$1', $current) ?? $current;
        }

        return $current;
    }

    public function normalized(): string
    {
        return self::normalizeEmphasis($this->markdown);
    }

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
        $map = [];
        foreach ($this->identityTableStates() as $key => $values) {
            $map[$key] = $values[array_key_last($values)] ?? '';
        }

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    public function identityTableStates(): array
    {
        foreach ($this->tables($this->markdown) as $table) {
            if (($table['headers'][0] ?? null) !== 'Field' || ($table['headers'][1] ?? null) !== 'Value') {
                continue;
            }
            $states = [];
            foreach ($table['rows'] as $row) {
                $key = self::plain($row[0] ?? '');
                if ($key === '') {
                    continue;
                }
                $states[$key][] = self::plain($row[1] ?? '');
            }

            return $states;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function identityStatesFor(string $identity): array
    {
        $all = $this->identityTableStates();
        if (isset($all[$identity])) {
            return $all[$identity];
        }
        foreach ($all as $key => $values) {
            if (str_contains($key, $identity)) {
                return $values;
            }
        }

        return [];
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
        $section = self::normalizeEmphasis($this->section('## Non-enumeration'));
        if (preg_match('/Prohibited client-visible states remain ([^.]+)/', $section, $matches) !== 1) {
            return [];
        }

        $parts = preg_split('/,\s*(?:and\s+)?|\s+and\s+/', $matches[1]) ?: [];
        $items = array_map(static fn (string $item): string => trim($item), $parts);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    public function genericPending(): ?string
    {
        $section = self::normalizeEmphasis($this->section('## Non-enumeration'));
        if (preg_match('/Generic pending is ([A-Za-z0-9_]+)/', $section, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function hiddenDenial(): ?string
    {
        $section = self::normalizeEmphasis($this->section('## Non-enumeration'));
        if (preg_match('/Hidden denial is ([A-Za-z0-9_]+)/', $section, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<array{identity: string, state: string}>
     */
    public function finalStatusPairs(): array
    {
        $section = $this->finalStatusSection();
        $pairs = [];
        if (preg_match_all('/^(?:\*{0,2}`?)([^:`\n]+?)(?::)\s*(.+?)$/m', $section, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $identity = self::plain($match[1]);
                $state = self::plain($match[2]);
                if ($identity === '' || $state === '' || str_starts_with(strtolower($identity), 'current material state')) {
                    continue;
                }
                $pairs[] = ['identity' => $identity, 'state' => $state];
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, string>
     */
    public function finalStatusMap(): array
    {
        $map = [];
        foreach ($this->finalStatusPairs() as $pair) {
            $map[$pair['identity']] = $pair['state'];
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function finalStatesFor(string $identity): array
    {
        $states = [];
        foreach ($this->finalStatusPairs() as $pair) {
            if ($pair['identity'] === $identity || str_contains($pair['identity'], $identity)) {
                $states[] = $pair['state'];
            }
        }

        return $states;
    }

    public function hasConflictingStates(string $identity): bool
    {
        $states = array_merge($this->identityStatesFor($identity), $this->finalStatesFor($identity));
        $normalized = [];
        foreach ($states as $state) {
            $normalized[] = strtoupper(trim($state));
        }
        $identityStates = $this->identityStatesFor($identity);
        $identityUnique = array_values(array_unique(array_map(strtoupper(...), $identityStates)));
        $finalUnique = array_values(array_unique(array_map(strtoupper(...), $this->finalStatesFor($identity))));

        return count($identityUnique) > 1 || count($finalUnique) > 1;
    }

    /**
     * Positive current-state assignments for an identity. Negated historical
     * wording such as "does not close P02-AUDIT-005" is excluded when it is
     * in the same sentence/clause as the candidate phrase.
     *
     * @return list<array{site: string, state: string}>
     */
    public function positiveStateAssignments(string $identity): array
    {
        $assignments = [];
        foreach ($this->identityStatesFor($identity) as $state) {
            $assignments[] = ['site' => 'identity_table', 'state' => $this->normalizeState($state)];
        }
        foreach ($this->finalStatesFor($identity) as $state) {
            $assignments[] = ['site' => 'final_status', 'state' => $this->normalizeState($state)];
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
        $states = [];
        foreach ($this->clauses($this->normalized()) as $clause) {
            if ($this->isHistoricalClause($clause) || $this->clauseNegatesIdentity($clause, $identity)) {
                continue;
            }
            foreach ($this->clausePositiveStates($clause, $identity) as $state) {
                $states[] = $state;
            }
        }

        return $states;
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return list<string>
     */
    public function pc020PrerequisiteItems(): array
    {
        $section = $this->section('## PC-001 through PC-022');
        if (preg_match('/Prerequisites before any later\s+production hard-off removal:\n\n((?:- .+\n)+)/', $section, $matches) !== 1) {
            return [];
        }

        preg_match_all('/^- (.+)$/m', $matches[1], $items);

        return array_map(trim(...), $items[1]);
    }

    public function permitsSensitiveClientState(): bool
    {
        return $this->sectionPermitsSensitive($this->section('## Non-enumeration'))
            || $this->sectionPermitsSensitive($this->section('## Hybrid model'))
            || $this->sectionPermitsSensitive($this->section('## Legacy unlinked profiles'));
    }

    public function hybridPermitsSensitive(): bool
    {
        $text = self::normalizeEmphasis($this->section('## Hybrid model'));
        if (preg_match('/manual_review_required/i', $text) !== 1) {
            return true;
        }

        return $this->sectionPermitsSensitive($this->section('## Hybrid model'))
            || preg_match('/client contract \((wrong_claim_code|profile_exists|profile_already_linked|claim_code_expired|national_id_not_found)/i', $text) === 1;
    }

    public function legacyPermitsSensitive(): bool
    {
        $text = self::normalizeEmphasis($this->section('## Legacy unlinked profiles'));
        if (preg_match('/receive profile_exists|return profile_exists|sees profile_exists/i', $text) === 1) {
            return true;
        }

        return $this->sectionPermitsSensitive($this->section('## Legacy unlinked profiles'));
    }

    public function claimsControllerFreezeIsGovernanceApproval(): bool
    {
        foreach ($this->clauses($this->normalized()) as $clause) {
            if (preg_match('/Controller freeze is (?!not\b)(Product|Security|Privacy|Support\/Operations) approval/i', $clause) === 1) {
                return true;
            }
        }

        return false;
    }

    public function hasMaterialGovernanceApproved(string $label): bool
    {
        foreach ($this->identityStatesFor($label) as $value) {
            if (str_contains(strtoupper($value), 'APPROVED') && ! str_contains(strtoupper($value), 'PENDING')) {
                return true;
            }
        }

        $quoted = preg_quote($label, '/');
        foreach ($this->clauses($this->normalized()) as $clause) {
            if ($this->clauseNegatesIdentity($clause, $label) || $this->isHistoricalClause($clause)) {
                continue;
            }
            if (preg_match('/'.$quoted.'\s*(?:\||:|is|remains|has been)?\s*(APPROVED|GRANTED|signed off|sign-off)/i', $clause) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function signOffEmails(): array
    {
        preg_match_all('/\b[A-Za-z][\w.+-]*@[A-Za-z][A-Za-z0-9.-]*\.[A-Za-z]{2,}\b/', $this->normalized(), $matches);

        return $matches[0];
    }

    public function hasApproverSignOff(): bool
    {
        if ($this->signOffEmails() !== []) {
            return true;
        }

        return preg_match('/\bApproved by\b/i', $this->normalized()) === 1
            && preg_match('/\bApproved by\b.{0,80}@/i', $this->normalized()) === 1;
    }

    /**
     * @return list<string>
     */
    public function pc002MissingFactors(): array
    {
        $text = preg_replace('/\s+/', ' ', self::normalizeEmphasis($this->section('## PC-002 high-confidence proof bundle'))) ?? '';
        $missing = [];
        if (preg_match('/Active unlinked/i', $text) !== 1 || preg_match('/HMAC/i', $text) !== 1) {
            $missing[] = 'target_hmac';
        }
        if (preg_match('/non-empty bound National-ID/i', $text) !== 1 && preg_match('/non-empty bound National ID/i', $text) !== 1) {
            $missing[] = 'bound_nid';
        }
        if (preg_match('/profile_claim/i', $text) !== 1 || preg_match('/already-verified phone/i', $text) !== 1 || preg_match('/OTP/i', $text) !== 1) {
            $missing[] = 'otp';
        }
        if (preg_match('/clinic-issued/i', $text) !== 1 || preg_match('/single-use/i', $text) !== 1) {
            $missing[] = 'credential';
        }

        return $missing;
    }

    public function pc002ClaimsDobSufficient(): bool
    {
        return $this->claimsProofSufficient($this->section('## PC-002 high-confidence proof bundle'), 'DOB|date of birth');
    }

    public function pc002ClaimsNidOtpSufficient(): bool
    {
        $section = $this->section('## PC-002 high-confidence proof bundle')."\n".$this->section('## Why the additional proof is a clinic-issued claim credential');

        return $this->claimsProofSufficient($section, 'NID\+OTP|National ID plus OTP|National ID plus a newly verified phone');
    }

    public function pc002RejectedProofIncomplete(): bool
    {
        $text = preg_replace('/\s+/', ' ', self::normalizeEmphasis($this->section('## PC-002 high-confidence proof bundle'))) ?? '';

        return preg_match('/\bDOB\b/i', $text) !== 1
            || preg_match('/NID\+OTP|National ID plus OTP/i', $text) !== 1
            || preg_match('/insufficient/i', $text) !== 1;
    }

    public function alreadyBoundAllowsReclaim(): bool
    {
        $text = self::normalizeEmphasis($this->section('## Already-bound profiles'));

        return preg_match('/\bcan be reclaimed\b/i', $text) === 1;
    }

    public function alreadyBoundAllowsOverwriteOrReassignment(): bool
    {
        $text = self::normalizeEmphasis($this->section('## Already-bound profiles'));

        return preg_match('/\bcan be overwritten\b/i', $text) === 1
            || preg_match('/\bcan be automatically reassigned\b/i', $text) === 1;
    }

    public function claimsProductionAuthorized(): bool
    {
        foreach ($this->clauses($this->normalized()) as $clause) {
            if ($this->isHistoricalClause($clause) || preg_match('/does\s+not\s+authorize/i', $clause) === 1) {
                continue;
            }
            if (preg_match('/Production enablement\s+(?:is\s+now\s+|has\s+been\s+|was\s+|remains\s+|is\s+)?(?!NOT_)AUTHORIZED/i', $clause) === 1) {
                return true;
            }
            if (preg_match('/Production enablement\s+has\s+been\s+authorized/i', $clause) === 1) {
                return true;
            }
            if (preg_match('/Production\s+is\s+authorized/i', $clause) === 1 && preg_match('/not\s+authorized/i', $clause) !== 1) {
                return true;
            }
        }

        return false;
    }

    public function runtimeResolverReturnsTrueInProduction(): bool
    {
        $text = self::normalizeEmphasis($this->section('## Current runtime (must stay dark)'));

        return preg_match('/resolver returns true in production/i', $text) === 1;
    }

    public function runtimeMissingDarkClaims(): bool
    {
        $text = preg_replace('/\s+/', ' ', self::normalizeEmphasis($this->section('## Current runtime (must stay dark)'))) ?? '';

        return preg_match('/defaults to false/i', $text) !== 1
            || preg_match('/resolver returns false/i', $text) !== 1
            || preg_match('/do not attach|does not attach|still do not attach/i', $text) !== 1
            || preg_match('/env flag alone cannot enable production/i', $text) !== 1;
    }

    public function ceremonyClaimedImplemented(): bool
    {
        $optionB = self::normalizeEmphasis($this->section('## Option B credential'));

        return preg_match('/are not implemented/i', $optionB) !== 1
            && preg_match('/not implemented/i', $optionB) !== 1;
    }

    public function statusClaimsApprovedProductionPolicy(): bool
    {
        foreach ($this->identityStatesFor('Status') as $value) {
            $upper = strtoupper($value);
            if (str_contains($upper, 'APPROVED_PRODUCTION_POLICY') && ! str_contains($upper, 'NOT')) {
                return true;
            }
        }

        foreach ($this->clauses($this->normalized()) as $clause) {
            if ($this->clauseNegatesIdentity($clause, 'Status') || preg_match('/is not\s+APPROVED_PRODUCTION_POLICY/i', $clause) === 1) {
                continue;
            }
            if (preg_match('/\bStatus\b.{0,40}\bAPPROVED_PRODUCTION_POLICY\b/i', $clause) === 1
                && preg_match('/\bnot\b.{0,20}\bAPPROVED_PRODUCTION_POLICY\b/i', $clause) !== 1) {
                return true;
            }
        }

        return false;
    }

    public function pc015InventedStatus(): bool
    {
        $text = self::normalizeEmphasis($this->section('## PC-001 through PC-022'));

        return preg_match('/No new status is invented/i', $text) !== 1
            || preg_match('/\bdisputed\b/i', $text) !== 1
            || preg_match('/automatic reassignment is (?:required|allowed|authorized)/i', $text) === 1;
    }

    public function layerDLacksPendingExternal(): bool
    {
        $section = self::normalizeEmphasis($this->section('## Four layers (must not be collapsed)'));

        return preg_match('/PENDING_EXTERNAL/i', $section) !== 1
            || preg_match('/OPEN \/ EXTERNAL_HUMAN/i', $section) !== 1;
    }

    public function envFlagAloneEnablesProduction(): bool
    {
        $text = $this->normalized();

        return preg_match('/env flag alone (?:can|is sufficient to) enable production/i', $text) === 1;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     * @return list<string>
     */
    public static function setIssues(array $expected, array $actual, string $prefix): array
    {
        $issues = [];
        if (count($actual) !== count(array_unique($actual))) {
            $issues[] = $prefix.'_duplicate_member';
        }
        $unique = array_values(array_unique($actual));
        if (array_diff($expected, $unique) !== []) {
            $issues[] = $prefix.'_incomplete';
        }
        if (array_diff($unique, $expected) !== []) {
            $issues[] = $prefix.'_unexpected_member';
        }

        return $issues;
    }

    private function finalStatusSection(): string
    {
        $section = $this->section('## Final blocker state');
        if ($section === '') {
            $section = $this->section('## Final blocker state after this evidence PR');
        }

        return $section;
    }

    /**
     * @return list<string>
     */
    private function clauses(string $text): array
    {
        $text = preg_replace("/\R/", "\n", $text) ?? $text;
        $parts = preg_split('/(?<=[.!?;])\s+|\n+/', $text) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $part): bool => $part !== ''));
    }

    private function isHistoricalClause(string $clause): bool
    {
        return preg_match('/\b(historically|historical quotation|labeled historical|not current authoritative policy state)\b/i', $clause) === 1;
    }

    private function clauseNegatesIdentity(string $clause, string $identity): bool
    {
        $id = $this->identityToken($identity);

        return preg_match('/does\s+not\s+(?:close|change|convert|mark|claim|reuse|enable|authorize|implement)\s+'.$id.'/i', $clause) === 1
            || preg_match('/'.$id.'\s+is\s+not\b/i', $clause) === 1
            || preg_match('/not\s+(?:close|change|convert)\s+'.$id.'/i', $clause) === 1;
    }

    /**
     * @return list<string>
     */
    private function clausePositiveStates(string $clause, string $identity): array
    {
        $id = $this->identityToken($identity);
        $states = [];
        if (preg_match_all(
            '/'.$id.'\s+(?:is\s+now|has\s+been|was|remains|is)\s+(OPEN|CLOSED|MITIGATED|NOT_AUTHORIZED|AUTHORIZED|DISABLED|PENDING_EXTERNAL|APPROVED)/i',
            $clause,
            $matches,
        ) > 0) {
            foreach ($matches[1] as $state) {
                $states[] = strtoupper($state);
            }
        }
        if (preg_match_all('/'.$id.'\s+(?:has\s+been|was)\s+(closed|mitigated|authorized)/i', $clause, $matches) > 0) {
            foreach ($matches[1] as $state) {
                $states[] = strtoupper($state);
            }
        }

        return $states;
    }

    private function identityToken(string $identity): string
    {
        if ($identity === 'T46') {
            return '(?<![A-Za-z0-9-])T46(?![A-Za-z0-9-])';
        }

        return preg_quote($identity, '/');
    }

    private function sectionPermitsSensitive(string $section): bool
    {
        $normalized = self::normalizeEmphasis($section);
        $stripped = preg_replace('/[^.]*\b(?:must not|do not|does not|cannot|never|prohibited)\b[^.]*\.?/i', '', $normalized) ?? $normalized;

        return preg_match('/\b(?:may|permit|return|use|introduce|allowed|receive|reveal)\b[^.\n]{0,80}\b(wrong_claim_code|profile_exists|profile_already_linked|claim_code_expired|national_id_not_found)\b/i', $stripped) === 1
            || (
                preg_match('/\b(wrong_claim_code|profile_exists|profile_already_linked|claim_code_expired|national_id_not_found)\b/i', $stripped) === 1
                && preg_match('/\b(manual_review_required|NOT_FOUND)\b/i', $stripped) !== 1
                && preg_match('/Prohibited client-visible states remain/i', $normalized) !== 1
            );
    }

    private function claimsProofSufficient(string $section, string $factor): bool
    {
        $text = preg_replace('/\s+/', ' ', self::normalizeEmphasis($section)) ?? '';

        return preg_match('/(?:'.$factor.')[^.]{0,80}\balone\b[^.]{0,40}(?<!in)sufficient/i', $text) === 1
            && preg_match('/(?:'.$factor.')[^.]{0,80}\balone\b[^.]{0,40}insufficient/i', $text) !== 1;
    }

    private function normalizeState(string $state): string
    {
        $state = self::plain($state);
        $state = preg_replace('/\s+/', ' ', $state) ?? $state;

        return $state;
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>}>|null
     */
    private function firstTableInSection(string $heading): ?array
    {
        $tables = $this->tables($this->section($heading));

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
        $cell = self::normalizeEmphasis($cell);
        $cell = preg_replace('/\s+/', ' ', $cell) ?? $cell;

        return trim($cell);
    }
}
