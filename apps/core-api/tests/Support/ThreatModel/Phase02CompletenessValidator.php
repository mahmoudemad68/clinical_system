<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

/**
 * Structural completeness for P02-AUDIT-004. Issues are derived from parsed
 * tables, not from "the ID string appears somewhere".
 */
final class Phase02CompletenessValidator
{
    /** @var array<string, string>|null */
    private ?array $basenameIndex = null;

    public function __construct(
        private readonly string $repoRoot,
        private readonly Phase02ThreatRegisterParser $threats = new Phase02ThreatRegisterParser,
        private readonly Phase02InventoryParser $inventory = new Phase02InventoryParser,
    ) {}

    public static function repoRootFromCoreApi(): string
    {
        return dirname(__DIR__, 5);
    }

    /**
     * @param  list<string>|null  $implementedHttpIdentities
     * @param  list<string>|null  $implementedDoctorChannels
     * @param  list<string>|null  $implementedPharmacyChannels
     */
    public function validate(
        string $onboarding,
        string $catalog,
        string $evidence,
        ?array $implementedHttpIdentities = null,
        ?array $implementedDoctorChannels = null,
        ?array $implementedPharmacyChannels = null,
    ): Phase02CompletenessResult {
        $issues = [];
        $issues = array_merge($issues, $this->requiredSections($onboarding, $catalog));
        $parsed = $this->threats->parseThreats($onboarding);
        $issues = array_merge($issues, $this->threatIdentityIssues($parsed));
        $issues = array_merge($issues, $this->threatFieldIssues($parsed));
        $counts = $this->threats->deriveStatusCounts($parsed);
        $issues = array_merge($issues, $this->statusSummaryIssues($onboarding, $counts));
        $issues = array_merge($issues, $this->approvalWordingIssues($onboarding."\n".$evidence));
        $issues = array_merge($issues, $this->actorAssetIssues($onboarding));
        $issues = array_merge($issues, $this->dfdIssues($onboarding));
        $issues = array_merge($issues, $this->jobsIssues($catalog));
        $issues = array_merge($issues, $this->reconcilerDescriptionIssues($catalog));
        $http = $this->inventory->parseHttpRows($catalog);
        $doctor = $this->inventory->parseDoctorChannels($catalog);
        $pharmacy = $this->inventory->parsePharmacyChannels($catalog);
        $issues = array_merge($issues, $this->httpIssues($catalog, $http, $implementedHttpIdentities));
        $issues = array_merge($issues, $this->ipcIssues($catalog, $doctor, $pharmacy, $implementedDoctorChannels, $implementedPharmacyChannels));
        $issues = array_merge($issues, $this->evidenceReferenceIssues($parsed));

        $actors = $this->tableRowCount($onboarding, '/^## Actors\b/m');
        $assets = $this->tableRowCount($onboarding, '/^## Assets\b/m');

        return new Phase02CompletenessResult(
            array_values(array_unique($issues)),
            $counts,
            count($http),
            count($doctor),
            count($pharmacy),
            $actors,
            $assets,
        );
    }

    /**
     * @return list<string>
     */
    private function requiredSections(string $onboarding, string $catalog): array
    {
        $issues = [];
        $requiredOnboarding = [
            '/^## Actors\b/m' => 'Actors section',
            '/^## Assets\b/m' => 'Assets section',
            '/^## Data-flow diagram\b/m' => 'Trust boundaries / DFD section',
            '/^## Threat register\b/m' => 'Threat register section',
            '/^## Residual \/ open-risk treatment\b/m' => 'residual/open-risk treatment section',
        ];
        foreach ($requiredOnboarding as $pattern => $label) {
            if (preg_match($pattern, $onboarding) !== 1) {
                $issues[] = 'missing required section: '.$label;
            }
        }
        if (! $this->inventory->hasJobsSection($catalog)) {
            $issues[] = 'missing required section: Jobs/workers/storage/scanner';
        }

        return $issues;
    }

    /**
     * @param  list<array{id: string, fields: array<string, string>, status: string}>  $parsed
     * @return list<string>
     */
    private function threatIdentityIssues(array $parsed): array
    {
        $issues = [];
        $expected = Phase02ThreatRegisterParser::expectedIds();
        $seen = [];
        foreach ($parsed as $threat) {
            $id = $threat['id'];
            if (! in_array($id, $expected, true)) {
                $issues[] = 'unexpected threat ID '.$id;
            }
            if (isset($seen[$id])) {
                $issues[] = 'duplicate threat ID '.$id;
            }
            $seen[$id] = true;
        }
        foreach ($expected as $id) {
            if (! isset($seen[$id])) {
                $issues[] = 'missing threat ID '.$id;
            }
        }
        if (count($parsed) !== count($expected) && $issues === []) {
            $issues[] = 'threat row count '.count($parsed).' does not equal '.count($expected);
        }

        return $issues;
    }

    /**
     * @param  list<array{id: string, fields: array<string, string>, status: string}>  $parsed
     * @return list<string>
     */
    private function threatFieldIssues(array $parsed): array
    {
        $issues = [];
        foreach ($parsed as $threat) {
            $id = $threat['id'];
            foreach (Phase02ThreatRegisterParser::REQUIRED_FIELDS as $field) {
                $value = trim((string) ($threat['fields'][$field] ?? ''));
                if ($value === '') {
                    $issues[] = $id.' missing required field '.$field;
                }
            }
            $status = $threat['status'];
            if ($status !== '' && ! in_array($status, Phase02ThreatRegisterParser::ALLOWED_STATUSES, true)) {
                $issues[] = $id.' has invalid status '.$status;
            }
        }

        return $issues;
    }

    /**
     * @param  array{MITIGATED: int, PARTIAL: int, OPEN: int, NOT_APPLICABLE: int, TOTAL: int}  $derived
     * @return list<string>
     */
    private function statusSummaryIssues(string $onboarding, array $derived): array
    {
        $published = $this->threats->parsePublishedStatusCounts($onboarding);
        if ($published === null) {
            return ['missing STATUS_COUNTS summary derived from the register'];
        }
        $issues = [];
        foreach (['MITIGATED', 'PARTIAL', 'OPEN', 'NOT_APPLICABLE', 'TOTAL'] as $key) {
            if ($published[$key] !== $derived[$key]) {
                $issues[] = 'STATUS_COUNTS '.$key.'='.$published[$key].' does not match derived '.$derived[$key];
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function approvalWordingIssues(string $text): array
    {
        $issues = [];
        if (preg_match_all('/G-08-04[^\n]{0,240}/i', $text, $matches) === false) {
            return $issues;
        }
        foreach ($matches[0] as $window) {
            if (preg_match('/\bOPEN\b/', $window) === 1) {
                continue;
            }
            if (preg_match('/\bnot\b.{0,40}\b(APPROVED|ACCEPTED|CLOSED)\b/i', $window) === 1) {
                continue;
            }
            if (preg_match('/\b(APPROVED|ACCEPTED|CLOSED)\b/', $window) === 1) {
                $issues[] = 'forbidden G-08-04 approval/closure wording: '.$window;
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function actorAssetIssues(string $onboarding): array
    {
        $issues = [];
        $actors = $this->section($onboarding, '/^## Actors\b/m');
        $assets = $this->section($onboarding, '/^## Assets\b/m');
        foreach ([
            'anonymous caller',
            'admin acting',
            'staff invitee',
            'log-sink',
            'supply-chain',
            'direct database reader',
        ] as $actor) {
            if (! str_contains(strtolower($actors), strtolower($actor))) {
                $issues[] = 'Actors table missing principal: '.$actor;
            }
        }
        foreach ([
            'staff invitation phone',
            'clinic_staff_profiles',
            'reviewer-download signing',
            'idempotency',
        ] as $asset) {
            if (! str_contains(strtolower($assets), strtolower($asset))) {
                $issues[] = 'Assets table missing: '.$asset;
            }
        }
        if (! str_contains(strtolower($assets), 'target_phone_lookup_hmac')
            && ! str_contains(strtolower($assets), 'blind index')) {
            $issues[] = 'Assets table missing staff invitation phone blind index';
        }

        $declaredActors = $this->declaredCount($onboarding, '/^## Actors \((\d+)\)/m');
        $parsedActors = $this->tableRowCount($onboarding, '/^## Actors\b/m');
        if ($declaredActors !== null && $declaredActors !== $parsedActors) {
            $issues[] = 'Actors declared count '.$declaredActors.' does not match parsed '.$parsedActors;
        }
        $declaredAssets = $this->declaredCount($onboarding, '/^## Assets \((\d+)\)/m');
        $parsedAssets = $this->tableRowCount($onboarding, '/^## Assets\b/m');
        if ($declaredAssets !== null && $declaredAssets !== $parsedAssets) {
            $issues[] = 'Assets declared count '.$declaredAssets.' does not match parsed '.$parsedAssets;
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function dfdIssues(string $onboarding): array
    {
        $issues = [];
        $dfd = $this->section($onboarding, '/^## Data-flow diagram\b/m');
        if (! str_contains($dfd, '```mermaid')) {
            $issues[] = 'DFD missing mermaid diagram';
        }
        foreach ([
            'signed PUT' => 'desktop/admin signed PUT to object storage',
            'HMAC' => 'reviewer HMAC download',
            'reconcile' => 'verification:reconcile-uploads cleanup',
            'clamd' => 'worker/clamd scanner flow',
            'Admin' => 'admin represented upload',
        ] as $needle => $label) {
            if (! str_contains($dfd, $needle)) {
                $issues[] = 'DFD missing '.$label;
            }
        }
        if (preg_match('/S3 event notification webhook/i', $dfd) === 1 && ! str_contains(strtolower($dfd), 'none')) {
            $issues[] = 'DFD invents an S3 callback/webhook';
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function jobsIssues(string $catalog): array
    {
        $jobs = $this->inventory->jobsSection($catalog)."\n".$this->section($catalog, '/^## Non-HTTP security entry points\b/m');
        $issues = [];
        $required = [
            'VerificationUploadCompletedConsumer' => 'verification upload consumer',
            'verification:reconcile-uploads' => 'verification:reconcile-uploads',
            'StoreObject' => 'object storage boundary',
            'ScanObject' => 'malware scanner boundary',
            'TrustedDocumentEvidenceIssuer' => 'trusted-document issuer boundary',
        ];
        foreach ($required as $needle => $label) {
            if (! str_contains($jobs, $needle)) {
                $issues[] = 'Jobs/workers/storage/scanner missing '.$label;
            }
        }
        if (! str_contains($catalog, 'e2e:probe-doctor-clinic-capability')) {
            $issues[] = 'entry-point inventory missing e2e:probe-doctor-clinic-capability';
        }
        if (str_contains($catalog, 'e2e:probe-doctor-clinic-capability')
            && ! str_contains($catalog, 'LOCAL/TESTING ONLY')) {
            $issues[] = 'e2e:probe-doctor-clinic-capability must be classified LOCAL/TESTING ONLY';
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function reconcilerDescriptionIssues(string $catalog): array
    {
        $issues = [];
        if (preg_match('/re-drive stuck scanning/i', $catalog) === 1) {
            $issues[] = 'verification:reconcile-uploads must not be described as re-driving still-scanning uploads';
        }
        $haystack = strtolower($catalog);
        if (! str_contains($haystack, 'expire') || ! str_contains($haystack, 'scanning')) {
            $issues[] = 'verification:reconcile-uploads must document expire/delete authority and that scanning uploads are out of scope';
        }

        return $issues;
    }

    /**
     * @param  list<array{number: int, method: string, path: string, identity: string}>  $http
     * @param  list<string>|null  $implemented
     * @return list<string>
     */
    private function httpIssues(string $catalog, array $http, ?array $implemented): array
    {
        $issues = [];
        $declared = $this->inventory->declaredHttpCount($catalog);
        if ($declared === null) {
            $issues[] = 'HTTP inventory missing declared count';
        } elseif ($declared !== count($http)) {
            $issues[] = 'HTTP declared count '.$declared.' does not match parsed '.count($http);
        }
        $identities = [];
        $numbers = [];
        foreach ($http as $row) {
            if (isset($identities[$row['identity']])) {
                $issues[] = 'duplicate HTTP identity '.$row['identity'];
            }
            $identities[$row['identity']] = true;
            if (isset($numbers[$row['number']])) {
                $issues[] = 'duplicate HTTP row number '.$row['number'];
            }
            $numbers[$row['number']] = true;
        }
        if ($implemented !== null) {
            $documented = array_keys($identities);
            sort($documented);
            $implementedSorted = $implemented;
            sort($implementedSorted);
            foreach (array_diff($implementedSorted, $documented) as $missing) {
                $issues[] = 'HTTP inventory missing implemented route '.$missing;
            }
            foreach (array_diff($documented, $implementedSorted) as $extra) {
                $issues[] = 'HTTP inventory has unexpected route '.$extra;
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $doctor
     * @param  list<string>  $pharmacy
     * @param  list<string>|null  $implementedDoctor
     * @param  list<string>|null  $implementedPharmacy
     * @return list<string>
     */
    private function ipcIssues(
        string $catalog,
        array $doctor,
        array $pharmacy,
        ?array $implementedDoctor,
        ?array $implementedPharmacy,
    ): array {
        $issues = [];
        if (count($doctor) !== 17) {
            $issues[] = 'Doctor IPC parsed count '.count($doctor).' does not equal 17';
        }
        if (count($pharmacy) !== 16) {
            $issues[] = 'Pharmacy IPC parsed count '.count($pharmacy).' does not equal 16';
        }
        $combined = count($doctor) + count($pharmacy);
        if ($combined !== 33) {
            $issues[] = 'combined domain IPC count '.$combined.' does not equal 33';
        }
        $declaredDoctor = $this->inventory->declaredDoctorCount($catalog);
        if ($declaredDoctor !== null && $declaredDoctor !== count($doctor)) {
            $issues[] = 'Doctor IPC declared count '.$declaredDoctor.' does not match parsed '.count($doctor);
        }
        $declaredPharmacy = $this->inventory->declaredPharmacyCount($catalog);
        if ($declaredPharmacy !== null && $declaredPharmacy !== count($pharmacy)) {
            $issues[] = 'Pharmacy IPC declared count '.$declaredPharmacy.' does not match parsed '.count($pharmacy);
        }
        $declaredCombined = $this->inventory->declaredDomainIpcCount($catalog);
        if ($declaredCombined !== null && $declaredCombined !== $combined) {
            $issues[] = 'domain IPC declared count '.$declaredCombined.' does not match parsed '.$combined;
        }
        $issues = array_merge($issues, $this->uniqueOrMissing($doctor, $implementedDoctor, 'Doctor IPC'));
        $issues = array_merge($issues, $this->uniqueOrMissing($pharmacy, $implementedPharmacy, 'Pharmacy IPC'));

        return $issues;
    }

    /**
     * @param  list<string>  $documented
     * @param  list<string>|null  $implemented
     * @return list<string>
     */
    private function uniqueOrMissing(array $documented, ?array $implemented, string $label): array
    {
        $issues = [];
        $seen = [];
        foreach ($documented as $channel) {
            if (isset($seen[$channel])) {
                $issues[] = 'duplicate '.$label.' channel '.$channel;
            }
            $seen[$channel] = true;
        }
        if ($implemented === null) {
            return $issues;
        }
        foreach (array_diff($implemented, $documented) as $missing) {
            $issues[] = $label.' missing channel '.$missing;
        }
        foreach (array_diff($documented, $implemented) as $extra) {
            $issues[] = $label.' unexpected channel '.$extra;
        }

        return $issues;
    }

    /**
     * @param  list<array{id: string, fields: array<string, string>}>  $parsed
     * @return list<string>
     */
    private function evidenceReferenceIssues(array $parsed): array
    {
        $issues = [];
        foreach ($parsed as $threat) {
            $evidence = (string) ($threat['fields']['evidence'] ?? '');
            foreach ($this->repositoryEvidenceTokens($evidence) as $token) {
                $resolved = $this->resolveRepositoryToken($token);
                if ($resolved === null) {
                    continue;
                }
                if ($resolved === false) {
                    $issues[] = $threat['id'].' cites missing repository evidence '.$token;
                }
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function repositoryEvidenceTokens(string $evidence): array
    {
        $tokens = [];
        if (preg_match_all('/`([^`]+)`/', $evidence, $backticks) !== false) {
            foreach ($backticks[1] as $token) {
                $tokens[] = $token;
            }
        }
        if (preg_match_all('/\b([A-Z][A-Za-z0-9]*Test)\b/', $evidence, $tests) !== false) {
            foreach ($tests[1] as $token) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return bool|null true exists, false missing, null not a repository path
     */
    private function resolveRepositoryToken(string $token): ?bool
    {
        $token = trim($token);
        if ($token === '' || $this->isExternalEvidence($token)) {
            return null;
        }
        if (preg_match('/^[A-Z][A-Za-z0-9]*Test$/', $token) === 1) {
            return $this->locateBasename($token.'.php') !== null;
        }
        if (preg_match('/\.(?:test|spec)\.(?:ts|tsx)$/', $token) === 1) {
            return $this->locateBasename($token) !== null;
        }
        if (str_contains($token, '/') && ! str_contains($token, ' ')) {
            if (! preg_match('#^(docs|apps|infra|packages|Modules|tests)/#', $token)) {
                return null;
            }
            $path = $this->repoRoot.'/'.ltrim($token, '/');

            return is_file($path) || is_dir($path);
        }

        return null;
    }

    private function isExternalEvidence(string $token): bool
    {
        return preg_match('/^\d{7,}$/', $token) === 1
            || str_starts_with($token, 'http://')
            || str_starts_with($token, 'https://')
            || str_contains($token, 'github.com')
            || preg_match('/^PR #\d+/', $token) === 1;
    }

    private function locateBasename(string $basename): ?string
    {
        $index = $this->basenameIndex ??= $this->buildBasenameIndex();

        return $index[$basename] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function buildBasenameIndex(): array
    {
        $index = [];
        foreach ([
            $this->repoRoot.'/apps/core-api/tests',
            $this->repoRoot.'/apps/doctor-desktop/src',
            $this->repoRoot.'/apps/pharmacy-desktop/src',
            $this->repoRoot.'/apps/admin-web/src',
            $this->repoRoot.'/packages',
            $this->repoRoot.'/tests',
        ] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
            $filtered = new \RecursiveCallbackFilterIterator(
                $directory,
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), ['vendor', 'node_modules', '.git', 'dist'], true);
                    }

                    return true;
                },
            );
            $iterator = new \RecursiveIteratorIterator($filtered);
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (! isset($index[$name])) {
                    $index[$name] = $file->getPathname();
                }
            }
        }

        return $index;
    }

    private function declaredCount(string $markdown, string $pattern): ?int
    {
        if (preg_match($pattern, $markdown, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    private function tableRowCount(string $markdown, string $startPattern): int
    {
        $section = $this->section($markdown, $startPattern);
        $count = 0;
        $headerLabels = ['Actor', 'Asset', 'Notes', 'Classification', 'Field', 'Status', 'Count', 'IDs', 'Topic', 'State', 'Value'];
        foreach (explode("\n", $section) as $line) {
            if (! str_starts_with($line, '| ')) {
                continue;
            }
            if (str_contains($line, '| ---') || str_starts_with(trim($line, '| '), '---')) {
                continue;
            }
            $cells = array_values(array_filter(array_map('trim', explode('|', $line)), static fn (string $cell): bool => $cell !== ''));
            if ($cells === [] || in_array($cells[0], $headerLabels, true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function section(string $markdown, string $startPattern): string
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
}
