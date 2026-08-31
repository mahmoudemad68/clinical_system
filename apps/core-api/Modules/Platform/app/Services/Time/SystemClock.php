<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Time;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Contracts\Clock;

/**
 * The production clock. UTC everywhere; business time zone only at the edge.
 */
final class SystemClock implements Clock
{
    private readonly DateTimeZone $utc;

    private readonly DateTimeZone $business;

    public function __construct(string $businessTimeZone = 'Africa/Cairo')
    {
        $this->utc = new DateTimeZone('UTC');
        $this->business = new DateTimeZone($businessTimeZone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }

    public function businessTimeZone(): DateTimeZone
    {
        return $this->business;
    }

    public function toBusinessTime(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone($this->business);
    }
}
