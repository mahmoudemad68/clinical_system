<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

/**
 * Data classification levels (Phase 00 §5.1).
 *
 * Defined in docs/data-classification/classification-policy.md. Encoded as a
 * type so that a classification decision is made where data is defined rather
 * than inferred later by whoever writes the log line.
 *
 * Note there is no Credential case usable on an event payload: credentials
 * never travel in an event (packages/contracts/events/README.md rule 2). The
 * case exists for classifying storage and configuration, and the event
 * validator rejects it explicitly.
 */
enum Classification: string
{
    /** Publishable without restriction, for example the medication catalogue. */
    case Public = 'public';

    /** Operational data with no personal dimension, for example queue depth. */
    case Internal = 'internal';

    /** Identifies or relates to a person: name, phone, address, appointment. */
    case Personal = 'personal';

    /** Clinical or otherwise sensitive personal data. The strictest routine level. */
    case Sensitive = 'sensitive';

    /** Secrets and credentials. Never logged, never cached, never in an event. */
    case Credential = 'credential';

    /**
     * May a value at this level appear in telemetry (logs, traces, metrics)?
     *
     * Personal and above may appear only as a pseudonymous identifier, never as
     * a value. The redaction processor enforces this before export.
     */
    public function allowedInTelemetry(): bool
    {
        return match ($this) {
            self::Public, self::Internal => true,
            self::Personal, self::Sensitive, self::Credential => false,
        };
    }

    /**
     * May a value at this level be used as a metric label?
     *
     * Never for anything personal: labels are unbounded cardinality and are
     * retained and queried far more widely than log bodies. The phase file
     * forbids patient, doctor, appointment, file, prescription, or free-text
     * values as labels.
     */
    public function allowedAsMetricLabel(): bool
    {
        return $this === self::Public || $this === self::Internal;
    }

    /**
     * May a value at this level be cached?
     *
     * PHI caching is avoided. A reviewed exception must encrypt content,
     * sharply bound TTL and access, and prove deletion (ADR 0007), and it is
     * recorded in the cache inventory rather than decided in code.
     */
    public function cacheableByDefault(): bool
    {
        return match ($this) {
            self::Public, self::Internal => true,
            self::Personal, self::Sensitive, self::Credential => false,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Personal => 2,
            self::Sensitive => 3,
            self::Credential => 4,
        };
    }
}
