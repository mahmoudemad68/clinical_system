<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Infrastructure\Telemetry\PlatformMetrics;
use Illuminate\Console\Command;

final class PruneExpiredAuthStateCommand extends Command
{
    protected $signature = 'auth:prune-expired';

    protected $description = 'Invalidate expired OTP challenges and auth sessions. Ciphertext is not logged.';

    public function handle(AuthDirectory $auth, Clock $clock, PlatformMetrics $metrics): int
    {
        $now = $clock->now();
        $otps = $auth->pruneExpiredOtps($now);
        $sessions = $auth->pruneExpiredSessions($now);
        $metrics->set('clinic_active_sessions', (float) $auth->countActiveSessions($now), [
            'client_class' => 'all',
        ]);
        $this->info(sprintf('Expired auth state pruned (otp=%d sessions=%d).', $otps, $sessions));

        return self::SUCCESS;
    }
}
