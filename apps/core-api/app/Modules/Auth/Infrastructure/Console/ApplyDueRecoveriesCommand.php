<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Application\ApplyRecoveryHandler;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use Illuminate\Console\Command;

final class ApplyDueRecoveriesCommand extends Command
{
    protected $signature = 'identity:apply-due-recoveries';

    protected $description = 'Apply patient recovery rows whose cooling-off has elapsed. Does not apply manual_review.';

    public function handle(AuthDirectory $auth, Clock $clock, ApplyRecoveryHandler $apply): int
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
