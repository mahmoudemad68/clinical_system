<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Command;
use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Services\VerificationUploadProcessor;
use RuntimeException;

/**
 * Testing-only: process one upload with the E2E clean scanner.
 */
final class ProcessVerificationUploadFixtureCommand extends Command
{
    protected $signature = 'e2e:process-verification-upload {uploadId : Upload intent UUID}';

    protected $description = 'Process a verification upload with the E2E scanner (local/testing only).';

    public function handle(): int
    {
        if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            $this->error('This command is disabled outside local/testing.');

            return self::FAILURE;
        }

        $this->laravel->instance(ScanObject::class, new E2eCleanScanObject);
        $this->laravel->forgetInstance(VerificationUploadProcessor::class);
        $processor = $this->laravel->make(VerificationUploadProcessor::class);
        if (! $processor instanceof VerificationUploadProcessor) {
            throw new RuntimeException('Upload processor is not bound.');
        }

        $processor->process(Identifier::fromString((string) $this->argument('uploadId')));
        $this->info('Processed upload.');

        return self::SUCCESS;
    }
}
