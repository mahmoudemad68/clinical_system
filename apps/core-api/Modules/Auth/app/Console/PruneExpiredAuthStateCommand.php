<?php

declare(strict_types=1);

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Services\Telemetry\PlatformMetrics;

final class PruneExpiredAuthStateCommand extends Command
{
    protected $signature = 'auth:prune-expired';

    protected $description = 'Invalidate expired OTP challenges and prune obsolete Phase-01 auth rows. Ciphertext is not logged.';

    public function handle(AuthDirectory $auth, Clock $clock, PlatformMetrics $metrics): int
    {
        $now = $clock->now();
        $otps = $auth->pruneExpiredOtps($now);
        $sessions = $auth->pruneExpiredSessions($now);
        $recoveries = $auth->pruneObsoleteRecoveryRequests($now);
        $devices = $auth->pruneObsoleteDevices($now);
        $consumptions = $auth->pruneObsoleteRefreshConsumptions($now);
        $metrics->set('clinic_active_sessions', (float) $auth->countActiveSessions($now), [
            'client_class' => 'all',
        ]);
        $this->info(sprintf(
            'Expired auth state pruned (otp=%d sessions=%d recoveries=%d devices=%d consumptions=%d).',
            $otps,
            $sessions,
            $recoveries,
            $devices,
            $consumptions,
        ));

        return self::SUCCESS;
    }
}
