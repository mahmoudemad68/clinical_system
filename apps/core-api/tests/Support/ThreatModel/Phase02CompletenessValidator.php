<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

/**
 * Structural completeness for P02-AUDIT-004. Issues are derived from parsed
 * tables, not from "the ID string appears somewhere".
 */
final class Phase02CompletenessValidator
{
    public const CURRENT_PROFILE_CORRECTION_JSON = 'docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.2-phase02.json';

    public const CURRENT_PROFILE_CORRECTION_SHA256 = '1961be59aa3ea0ab2e712ebc854d15a23343ca03485d81155aa4c36627c35e37';

    public const PROFILE_CORRECTION_EVIDENCE = 'docs/evidence/phase-02/p02-audit-003-profile-correction-policy.md';

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
        $issues = array_merge($issues, $this->statusSummaryIssues($onboarding, $evidence, $counts, $parsed));
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
        $issues = array_merge($issues, $this->profileCorrectionReconciliationIssues($parsed, $onboarding, $evidence));
        $issues = array_merge($issues, $this->preservedOpenBlockerIssues($parsed, $onboarding, $evidence));

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
     * @param  list<array{id: string, status: string}>  $parsed
     * @return list<string>
     */
    private function statusSummaryIssues(string $onboarding, string $evidence, array $derived, array $parsed): array
    {
        $issues = [];
        $publishedBlocks = $this->threats->parseAllPublishedStatusCounts($onboarding."\n".$evidence);
        if ($publishedBlocks === []) {
            return ['missing STATUS_COUNTS summary derived from the register'];
        }
        foreach ($publishedBlocks as $index => $published) {
            foreach (['MITIGATED', 'PARTIAL', 'OPEN', 'NOT_APPLICABLE', 'TOTAL'] as $key) {
                if ($published[$key] !== $derived[$key]) {
                    $issues[] = 'STATUS_COUNTS '.$key.'='.$published[$key].' does not match derived '.$derived[$key];
                }
            }
            if ($index > 0 && $published !== $publishedBlocks[0]) {
                $issues[] = 'duplicated STATUS_COUNTS summaries disagree';
            }
        }

        foreach ($this->threats->parseLabeledStatusCounts($onboarding."\n".$evidence) as $row) {
            if ($row['count'] !== $derived[$row['status']]) {
                $issues[] = 'status table '.$row['status'].'='.$row['count'].' does not match derived '.$derived[$row['status']];
            }
        }

        $byStatus = [];
        foreach ($parsed as $threat) {
            $byStatus[$threat['status']][] = $threat['id'];
        }
        $tableIds = $this->threats->parseStatusTableIds($onboarding);
        foreach (['MITIGATED', 'PARTIAL', 'OPEN', 'NOT_APPLICABLE'] as $status) {
            $expected = $byStatus[$status] ?? [];
            $listed = $tableIds[$status] ?? null;
            if ($listed === null) {
                continue;
            }
            sort($expected);
            $listedSorted = $listed;
            sort($listedSorted);
            if ($expected !== $listedSorted) {
                $issues[] = 'status table IDs for '.$status.' do not match register rows';
            }
        }

        $t45 = $this->threats->findThreat($parsed, 'P02-T45');
        if ($t45 !== null) {
            $openListed = $tableIds['OPEN'] ?? [];
            $mitigatedListed = $tableIds['MITIGATED'] ?? [];
            if ($t45['status'] === 'MITIGATED' && in_array('P02-T45', $openListed, true)) {
                $issues[] = 'P02-T45 status MITIGATED disagrees with OPEN summary IDs';
            }
            if ($t45['status'] === 'MITIGATED' && $mitigatedListed !== [] && ! in_array('P02-T45', $mitigatedListed, true)) {
                $issues[] = 'P02-T45 status MITIGATED disagrees with MITIGATED summary IDs';
            }
            if ($t45['status'] === 'OPEN' && in_array('P02-T45', $mitigatedListed, true)) {
                $issues[] = 'P02-T45 status OPEN disagrees with MITIGATED summary IDs';
            }
        }

        return array_values(array_unique($issues));
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
            if (preg_match('/\bnot\b.{0,40}\b(APPROVED|ACCEPTED|CLOSED|COMPLETED)\b/i', $window) === 1) {
                continue;
            }
            if (preg_match('/\b(APPROVED|ACCEPTED|CLOSED|COMPLETED)\b/i', $window) === 1) {
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
     * @param  list<array{id: string, title: string, fields: array<string, string>, status: string}>  $parsed
     * @return list<string>
     */
    private function profileCorrectionReconciliationIssues(array $parsed, string $onboarding, string $evidence): array
    {
        $issues = [];
        $jsonPath = $this->repoRoot.'/'.self::CURRENT_PROFILE_CORRECTION_JSON;
        if (! is_file($jsonPath)) {
            return ['missing current profile-correction policy artifact '.self::CURRENT_PROFILE_CORRECTION_JSON];
        }
        $fileSha = hash('sha256', (string) file_get_contents($jsonPath));
        if ($fileSha !== self::CURRENT_PROFILE_CORRECTION_SHA256) {
            $issues[] = 'current profile-correction artifact SHA is '.$fileSha.' expected '.self::CURRENT_PROFILE_CORRECTION_SHA256;
        }

        $t45 = $this->threats->findThreat($parsed, 'P02-T45');
        if ($t45 === null) {
            return $issues;
        }

        $t45Text = $t45['title']."\n".implode("\n", $t45['fields']);
        $t45Evidence = (string) ($t45['fields']['evidence'] ?? '');
        $t45Flat = $this->flatten($t45Text);

        if ($t45['status'] !== 'MITIGATED') {
            $issues[] = 'P02-T45 status '.$t45['status'].' while current profile-correction policy artifact exists';
        }
        if (str_contains($t45Flat, 'policy missing') || str_contains($t45Flat, 'policy is missing') || str_contains($t45Flat, 'policy is still missing')) {
            $issues[] = 'P02-T45 says profile-correction policy is missing while v1.0.2 is current';
        }
        if (str_contains($t45Flat, 'external_policy_input_required')) {
            $issues[] = 'P02-T45 says profile-correction policy is missing while v1.0.2 is current';
        }
        if ($this->claimsNoProductPrivacySecurityEvidence($t45Evidence)) {
            $issues[] = 'P02-T45 says no Product/Security/Privacy evidence exists';
        }
        $evidenceFlat = $this->flatten($t45Evidence);
        foreach (['product evidence', 'privacy evidence', 'security evidence'] as $required) {
            if (! str_contains($evidenceFlat, $required)) {
                $issues[] = 'P02-T45 does not record '.$required;
            }
        }
        if (! str_contains($t45Evidence, self::CURRENT_PROFILE_CORRECTION_JSON)) {
            $issues[] = 'P02-T45 current artifact reference is missing/wrong';
        }
        if (! str_contains($t45Evidence, self::CURRENT_PROFILE_CORRECTION_SHA256)) {
            $issues[] = 'P02-T45 current artifact SHA is missing/wrong';
        }
        if (preg_match('/\b[a-f0-9]{64}\b/', $t45Evidence, $shaMatch) === 1
            && $shaMatch[0] !== self::CURRENT_PROFILE_CORRECTION_SHA256) {
            $issues[] = 'P02-T45 current artifact SHA is missing/wrong';
        }
        if (! str_contains($t45Evidence, self::PROFILE_CORRECTION_EVIDENCE)) {
            $issues[] = 'P02-T45 missing profile-correction evidence document reference';
        }
        if (! str_contains($t45Evidence, 'Phase02PatientProfileCorrectionPolicyAlignmentTest')) {
            $issues[] = 'P02-T45 MITIGATED evidence missing named alignment test';
        }
        if (! str_contains($t45Flat, 'freeze_current_behavior')) {
            $issues[] = 'P02-T45 missing FREEZE_CURRENT_BEHAVIOR decision';
        }

        if (preg_match('/^\| P02-T45 \| OPEN \|/m', $evidence) === 1 && $t45['status'] !== 'OPEN') {
            $issues[] = 'P02-T45 listed OPEN in evidence while register status is '.$t45['status'];
        }
        if ($t45['status'] === 'MITIGATED' && preg_match('/^\| T45 \| /m', $evidence) !== 1) {
            $issues[] = 'P02-T45 MITIGATED evidence map row is missing';
        }
        if (preg_match('/^\| Profile-correction \| ([^|]+) \|/m', $evidence, $profileCell) === 1) {
            $cell = $this->flatten($profileCell[1]);
            if (str_contains($cell, 'out of product-policy scope')
                || str_contains($cell, 'external_policy_input_required')
                || str_contains($cell, 'policy is missing')) {
                $issues[] = 'threat-model evidence document says the profile-correction policy is absent';
            }
        }
        if ($this->currentVoiceMissingPolicyParagraphs($evidence) !== []) {
            $issues[] = 'threat-model evidence document says the profile-correction policy is absent';
        }

        $audit003Path = $this->repoRoot.'/'.self::PROFILE_CORRECTION_EVIDENCE;
        if (! is_file($audit003Path)) {
            $issues[] = 'missing P02-AUDIT-003 profile-correction evidence document';
        } else {
            $audit003 = (string) file_get_contents($audit003Path);
            if (! str_contains($audit003, self::CURRENT_PROFILE_CORRECTION_SHA256)
                || ! str_contains($audit003, 'FREEZE_CURRENT_BEHAVIOR')) {
                $issues[] = 'P02-AUDIT-003 evidence missing current v1.0.2 Freeze Current Behavior reference';
            }
            if ($this->currentVoiceMissingPolicyParagraphs($audit003) !== []) {
                $issues[] = 'P02-AUDIT-003 evidence currently cites obsolete missing-policy rationale';
            }
        }

        $combined = $onboarding."\n".$evidence;
        if (! str_contains($combined, 'READY_FOR_RE_QA')) {
            $issues[] = 'P02-AUDIT-003 current wording must be READY_FOR_RE_QA';
        }
        if ($this->claimsAudit003Closed($combined)) {
            $issues[] = 'P02-AUDIT-003 falsely claimed CLOSED before independent QA';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  list<array{id: string, title: string, fields: array<string, string>, status: string}>  $parsed
     * @return list<string>
     */
    private function preservedOpenBlockerIssues(array $parsed, string $onboarding, string $evidence): array
    {
        $issues = [];
        $combined = $onboarding."\n".$evidence;
        $t46 = $this->threats->findThreat($parsed, 'P02-T46');
        $t49 = $this->threats->findThreat($parsed, 'P02-T49');
        if ($t46 === null) {
            $issues[] = 'missing threat ID P02-T46';
        } elseif ($t46['status'] !== 'OPEN') {
            $issues[] = 'P02-T46 status changed from OPEN to '.$t46['status'];
        } else {
            $t46Text = $this->flatten($t46['title'].' '.implode(' ', $t46['fields']));
            if (! str_contains($t46Text, 'p02-audit-005')) {
                $issues[] = 'P02-T46 missing P02-AUDIT-005 owner';
            }
        }
        if ($t49 === null) {
            $issues[] = 'missing threat ID P02-T49';
        } elseif ($t49['status'] !== 'OPEN') {
            $issues[] = 'P02-T49 status changed from OPEN to '.$t49['status'];
        } else {
            $t49Text = $this->flatten($t49['title'].' '.implode(' ', $t49['fields']));
            if (! str_contains($t49Text, 'sf-001') || ! str_contains($t49Text, 'extract-zip')) {
                $issues[] = 'P02-T49 missing SF-001 extract-zip residual';
            }
        }
        $flat = $this->flatten($combined);
        if (str_contains($flat, 'profile claim') && preg_match('/profile claim[^\n]{0,80}(enabled|live|on)/i', $combined) === 1) {
            $issues[] = 'wording implies profile claim is enabled';
        }
        if (preg_match('/SF-001[^\n]{0,80}\b(ACCEPTED|CLOSED|REMEDIATED)\b/', $combined) === 1
            && preg_match('/SF-001[^\n]{0,80}\bOPEN\b/', $combined) !== 1) {
            $issues[] = 'wording implies SF-001 is accepted';
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function currentVoiceMissingPolicyParagraphs(string $text): array
    {
        $hits = [];
        $chunks = preg_split('/\n{2,}/', $text) ?: [];
        foreach ($chunks as $chunk) {
            $flat = $this->flatten($chunk);
            $historical = str_contains($flat, 'historical')
                || str_contains($flat, 'originally')
                || str_contains($flat, 'at that time')
                || str_contains($flat, 'obsolete')
                || str_contains($flat, 'not current')
                || str_contains($flat, 'predated')
                || str_contains($flat, 'was absent')
                || str_contains($flat, 'had not yet');
            $missing = str_contains($flat, 'out of product-policy scope')
                || str_contains($flat, 'profile-correction policy is missing')
                || str_contains($flat, 'profile-correction policy is still missing')
                || str_contains($flat, 'no product/privacy/security policy')
                || str_contains($flat, 'no product privacy security policy')
                || str_contains($flat, 'p02-audit-003 stays open')
                || str_contains($flat, 'p02-audit-003 remains open because');
            if ($missing && ! $historical) {
                $hits[] = $chunk;
            }
        }

        return $hits;
    }

    private function claimsNoProductPrivacySecurityEvidence(string $evidence): bool
    {
        $flat = $this->flatten($evidence);

        return str_contains($flat, 'no product/privacy/security policy')
            || str_contains($flat, 'no product privacy security policy')
            || (str_contains($flat, 'no product') && str_contains($flat, 'policy artifact'));
    }

    private function claimsAudit003Closed(string $text): bool
    {
        if (preg_match_all('/P02-AUDIT-003[^\n]{0,160}/', $text, $matches) === false) {
            return false;
        }
        foreach ($matches[0] as $window) {
            $flat = $this->flatten($window);
            if (! str_contains($flat, 'closed')) {
                continue;
            }
            if (str_contains($flat, 'not closed')
                || str_contains($flat, 'not claim')
                || str_contains($flat, 'does not close')
                || str_contains($flat, 'do not claim')) {
                continue;
            }
            if (preg_match('/p02-audit-003[^\n]{0,80}closed/', $flat) === 1) {
                return true;
            }
        }

        return false;
    }

    private function flatten(string $text): string
    {
        $stripped = str_replace(['*', '`', '"', "'"], '', $text);
        $collapsed = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;

        return strtolower($collapsed);
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
