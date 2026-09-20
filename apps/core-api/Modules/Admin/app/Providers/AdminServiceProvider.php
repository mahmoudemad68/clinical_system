<?php

declare(strict_types=1);

namespace Modules\Admin\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Admin\Http\Controllers\AdminVerificationController;
use Modules\Admin\Services\AdminVerificationReviewService;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/config.php', 'admin_module');
        $this->app->bind(AdminVerificationReviewService::class);
        $this->app->bind(AdminVerificationController::class);
    }
}
