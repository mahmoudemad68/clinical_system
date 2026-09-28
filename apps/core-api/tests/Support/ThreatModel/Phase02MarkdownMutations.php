<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

/**
 * In-memory mutations of Phase 02 threat-model Markdown for negative tests.
 */
final class Phase02MarkdownMutations
{
    public static function deleteThreat(string $markdown, string $id): string
    {
        return (string) preg_replace(
            '/^### '.preg_quote($id, '/').'\s+.*?(?=^### P02-T|\Z)/msu',
            '',
            $markdown,
            1,
        );
    }

    public static function duplicateThreat(string $markdown, string $id): string
    {
        if (preg_match(
            '/^### '.preg_quote($id, '/').'\s+.*?(?=^### P02-T|\Z)/msu',
            $markdown,
            $match,
        ) !== 1) {
            return $markdown;
        }

        return str_replace($match[0], $match[0]."\n".$match[0], $markdown);
    }

    public static function changeThreatStatus(string $markdown, string $id, string $status): string
    {
        if (preg_match(
            '/^### '.preg_quote($id, '/').'\s+.*?(?=^### P02-T|\Z)/msu',
            $markdown,
            $match,
        ) !== 1) {
            return $markdown;
        }
        $updated = preg_replace(
            '/^\| Status \| \*\*[A-Z_]+\*\* \|$/m',
            '| Status | **'.$status.'** |',
            $match[0],
            1,
        );

        return str_replace($match[0], (string) $updated, $markdown);
    }

    public static function removeEvidenceField(string $markdown, string $id): string
    {
        if (preg_match(
            '/^### '.preg_quote($id, '/').'\s+.*?(?=^### P02-T|\Z)/msu',
            $markdown,
            $match,
        ) !== 1) {
            return $markdown;
        }
        $updated = preg_replace('/^\| Evidence \| .* \|$/m', '', $match[0], 1);

        return str_replace($match[0], (string) $updated, $markdown);
    }

    public static function removeSection(string $markdown, string $headingPattern): string
    {
        if (preg_match($headingPattern, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $markdown;
        }
        $start = (int) $match[0][1];
        $rest = substr($markdown, $start);
        $nl = strpos($rest, "\n");
        $after = $nl === false ? '' : substr($rest, $nl + 1);
        $length = strlen($rest);
        if (preg_match('/^## /m', $after, $next, PREG_OFFSET_CAPTURE) === 1) {
            $length = $nl + 1 + (int) $next[0][1];
        }

        return substr($markdown, 0, $start).substr($markdown, $start + $length);
    }

    public static function citeMissingTest(string $markdown): string
    {
        return (string) preg_replace(
            '/PatientProfileRaceTest/',
            'NonexistentPhase02RaceTest',
            $markdown,
            1,
        );
    }

    public static function removeMiddleHttpRoute(string $catalog): string
    {
        $rows = [];
        if (preg_match_all('/^\| \d+ \| (?:GET|POST|PATCH|PUT|DELETE) \| `[^`]+` \|.*$/m', $catalog, $matches) !== false) {
            $rows = $matches[0];
        }
        if (count($rows) < 3) {
            return $catalog;
        }
        $middle = $rows[(int) floor(count($rows) / 2)];

        return str_replace($middle."\n", '', $catalog);
    }

    public static function addFakeHttpRoute(string $catalog): string
    {
        $row = "| 99 | POST | `/api/v1/verification-appeals` | attacker | authenticated | any | n/a | none | no | — | none | P02-T48 |\n";

        return (string) preg_replace(
            '/^## Electron IPC entry points/m',
            $row."\n## Electron IPC entry points",
            $catalog,
            1,
        );
    }

    public static function removeIpcChannel(string $catalog, string $channel): string
    {
        return (string) preg_replace(
            '/^\| `'.preg_quote($channel, '/').'` \|.*\n/m',
            '',
            $catalog,
            1,
        );
    }

    public static function addFakeIpcChannel(string $catalog): string
    {
        $row = "| `clinic:doctor.backdoor.shell` | IPC | fake | P02-T30 |\n";

        return (string) preg_replace(
            '/^Handlers: `apps\/doctor-desktop/m',
            $row."\nHandlers: `apps/doctor-desktop",
            $catalog,
            1,
        );
    }

    public static function claimG0804Approved(string $markdown): string
    {
        return $markdown."\n\nG-08-04 APPROVED\n";
    }

    public static function claimG0804Completed(string $markdown): string
    {
        return $markdown."\n\nG-08-04 completed\n";
    }

    /**
     * @param  array{MITIGATED: int, PARTIAL: int, OPEN: int, NOT_APPLICABLE: int, TOTAL: int}  $counts
     */
    public static function replacePublishedStatusCounts(string $markdown, array $counts): string
    {
        return (string) preg_replace(
            '/STATUS_COUNTS\s+MITIGATED=\d+\s+PARTIAL=\d+\s+OPEN=\d+\s+NOT_APPLICABLE=\d+\s+TOTAL=\d+/',
            sprintf(
                'STATUS_COUNTS MITIGATED=%d PARTIAL=%d OPEN=%d NOT_APPLICABLE=%d TOTAL=%d',
                $counts['MITIGATED'],
                $counts['PARTIAL'],
                $counts['OPEN'],
                $counts['NOT_APPLICABLE'],
                $counts['TOTAL'],
            ),
            $markdown,
        );
    }

    public static function corruptFirstPublishedStatusCounts(string $markdown): string
    {
        return (string) preg_replace(
            '/STATUS_COUNTS\s+MITIGATED=(\d+)/',
            'STATUS_COUNTS MITIGATED=0',
            $markdown,
            1,
        );
    }

    public static function setT45OpenMissingPolicy(string $markdown): string
    {
        $updated = self::changeThreatStatus($markdown, 'P02-T45', 'OPEN');
        $updated = (string) preg_replace(
            '/^### P02-T45\s+.*$/m',
            '### P02-T45 — Profile-correction policy missing',
            $updated,
            1,
        );

        return self::replaceThreatField(
            $updated,
            'P02-T45',
            'Evidence',
            '`UpdateOwnDemographics`; **No** product/privacy/security policy artifact for correction/provenance',
        );
    }

    public static function removeCurrentPolicyReference(string $markdown): string
    {
        return str_replace(
            Phase02CompletenessValidator::CURRENT_PROFILE_CORRECTION_JSON,
            'docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.REMOVED.json',
            $markdown,
        );
    }

    public static function corruptCurrentPolicySha(string $markdown): string
    {
        return str_replace(
            Phase02CompletenessValidator::CURRENT_PROFILE_CORRECTION_SHA256,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $markdown,
        );
    }

    public static function replaceThreatField(string $markdown, string $id, string $fieldLabel, string $value): string
    {
        if (preg_match(
            '/^### '.preg_quote($id, '/').'\s+.*?(?=^### P02-T|\Z)/msu',
            $markdown,
            $match,
        ) !== 1) {
            return $markdown;
        }
        $updated = preg_replace(
            '/^\| '.preg_quote($fieldLabel, '/').' \| .* \|$/m',
            '| '.$fieldLabel.' | '.$value.' |',
            $match[0],
            1,
        );

        return str_replace($match[0], (string) $updated, $markdown);
    }

    /**
     * Change a threat status and rewrite STATUS_COUNTS to match the mutated register.
     */
    public static function resolveThreatKeepingCountsConsistent(string $markdown, string $id, string $status = 'MITIGATED'): string
    {
        $updated = self::changeThreatStatus($markdown, $id, $status);
        $parser = new Phase02ThreatRegisterParser;
        $derived = $parser->deriveStatusCounts($parser->parseThreats($updated));

        return self::replacePublishedStatusCounts($updated, $derived);
    }
}
