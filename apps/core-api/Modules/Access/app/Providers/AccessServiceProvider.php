<?php

declare(strict_types=1);

namespace Modules\Access\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Contracts\Authorize;
use Modules\Access\Contracts\GrantContextualAccess;
use Modules\Access\Contracts\GrantStore;
use Modules\Access\Contracts\ListEffectiveCapabilities;
use Modules\Access\Contracts\RevokeContextualAccess;
use Modules\Access\Services\DefaultDenyAuthorizer;
use Modules\Access\Services\GrantContextualAccessService;
use Modules\Access\Services\ListEffectiveCapabilitiesService;
use Modules\Access\Services\Persistence\PostgresGrantStore;
use Modules\Access\Services\RevokeContextualAccessService;

final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Authorize::class, DefaultDenyAuthorizer::class);
        $this->app->singleton(GrantStore::class, PostgresGrantStore::class);
        $this->app->singleton(GrantContextualAccess::class, GrantContextualAccessService::class);
        $this->app->singleton(RevokeContextualAccess::class, RevokeContextualAccessService::class);
        $this->app->singleton(ListEffectiveCapabilities::class, ListEffectiveCapabilitiesService::class);
    }
}
