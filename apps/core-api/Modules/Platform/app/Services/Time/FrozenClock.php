<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Time;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Contracts\Clock;

/**
 * Test clock. Production never binds this.
 */
final class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
        private readonly DateTimeZone $business = new DateTimeZone('Africa/Cairo'),
    ) {
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function businessTimeZone(): DateTimeZone
    {
        return $this->business;
    }

    public function toBusinessTime(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone($this->business);
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
