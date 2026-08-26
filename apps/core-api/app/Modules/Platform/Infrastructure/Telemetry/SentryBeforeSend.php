<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Telemetry;

/**
 * Strip request bodies, cookies, and headers before Sentry export.
 */
final class SentryBeforeSend
{
    public static function filter(object $event): object
    {
        if (! method_exists($event, 'getRequest') || ! method_exists($event, 'setRequest')) {
            return $event;
        }

        $request = $event->getRequest();
        if (is_array($request)) {
            unset($request['cookies'], $request['data'], $request['env'], $request['headers']);
            $event->setRequest($request);
        }

        return $event;
    }
}
