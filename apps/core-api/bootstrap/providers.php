<?php

declare(strict_types=1);

use App\Modules\Access\Infrastructure\Providers\AccessServiceProvider;
use App\Modules\Audit\Infrastructure\Providers\AuditServiceProvider;
use App\Modules\Auth\Infrastructure\Providers\AuthServiceProvider;
use App\Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use App\Modules\Platform\Infrastructure\Providers\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

/*
 * Module service providers.
 *
 * Platform is the shared kernel and registers first: every other module
 * depends on its ports (module catalog). Business modules append below as
 * their phases land.
 */
return [
    PlatformServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    AuthServiceProvider::class,
    AccessServiceProvider::class,
    AppServiceProvider::class,
];
