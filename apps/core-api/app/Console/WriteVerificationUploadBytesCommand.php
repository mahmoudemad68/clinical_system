<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Support\VerificationPolicy;

/**
 * Testing-only: persist fixture bytes for a verification upload intent.
 */
final class WriteVerificationUploadBytesCommand extends Command
{
    protected $signature = 'e2e:write-verification-upload {uploadId : Upload intent UUID}';

    protected $description = 'Write synthetic verification upload bytes (local/testing only).';

    public function handle(StoreObject $objects, VerificationPolicy $policy): int
    {
        if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            $this->error('This command is disabled outside local/testing.');

            return self::FAILURE;
        }

        $uploadId = (string) $this->argument('uploadId');
        $row = DB::table('verification_upload_intents')->where('id', $uploadId)->first();
        if ($row === null) {
            $this->error('Upload intent is not available.');

            return self::FAILURE;
        }

        $bytes = "%PDF-1.4\n"
            ."1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
            ."2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n"
            ."3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 3 3] >>endobj\n"
            ."trailer<< /Root 1 0 R >>\n"
            ."%%EOF\n";

        $objects->writeAt(
            new StoredObjectRef(
                $policy->objectNamespace(),
                (string) $row->object_id,
                (string) $row->storage_locator,
            ),
            'application/pdf',
            $bytes,
        );
        $this->info('Wrote fixture bytes.');

        return self::SUCCESS;
    }
}
