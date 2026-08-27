<?php

declare(strict_types=1);

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Services\ApplyRecoveryService;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

final class ApplyDueRecoveriesCommand extends Command
{
    protected $signature = 'identity:apply-due-recoveries';

    protected $description = 'Apply patient recovery rows whose cooling-off has elapsed. Does not apply manual_review.';

    public function handle(AuthDirectory $auth, Clock $clock, ApplyRecoveryService $apply): int
    {
        $ids = $auth->dueCoolingOffRecoveryIds($clock->now());
        $applied = 0;

        foreach ($ids as $id) {
            try {
                $apply->handle(null, Identifier::fromTrusted($id));
                $applied++;
            } catch (InvalidValueObject) {
                continue;
            }
        }

        $this->info(sprintf('Due recoveries applied=%d', $applied));

        return self::SUCCESS;
    }
}
