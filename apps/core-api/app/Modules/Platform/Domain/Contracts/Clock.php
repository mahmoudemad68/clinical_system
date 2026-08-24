<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time as a dependency rather than a global.
 *
 * Every persisted instant is UTC (docs/phases/README.md invariant 17). Business
 * scheduling intent keeps an IANA time-zone identifier, never a fixed offset,
 * because Egypt observes daylight saving and a stored "+02:00" silently becomes
 * wrong twice a year.
 *
 * Domain code depends on this interface, never on now() or Carbon::now(), so a
 * DST boundary is a test case instead of an incident.
 */
interface Clock
{
    /**
     * The current instant, always in UTC.
     */
    public function now(): DateTimeImmutable;

    /**
     * The business time zone for scheduling intent.
     *
     * V1 is Egypt only, but callers must ask rather than hard-code
     * Africa/Cairo, so adding a second country stays a configuration change
     * (plan.md section 149).
     */
    public function businessTimeZone(): DateTimeZone;

    /**
     * Render a UTC instant in the business time zone for display at the edge.
     *
     * Conversion happens at the edge only. Nothing downstream of this call may
     * persist the result.
     */
    public function toBusinessTime(DateTimeImmutable $instant): DateTimeImmutable;
}
