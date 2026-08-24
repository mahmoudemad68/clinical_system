<?php

declare(strict_types=1);

/**
 * Client-facing health messages.
 *
 * No hard-coded strings anywhere in the codebase (plan.md section 148). These
 * are deliberately vague about which component is affected: a health endpoint
 * is public, and naming the failing dependency tells an attacker where to push.
 */
return [
    'status' => [
        'operational' => 'All services are operating normally.',
        'degraded' => 'Some optional services are currently unavailable. Core services are working.',
        'unavailable' => 'The service is temporarily unavailable. Please try again shortly.',
    ],
];
