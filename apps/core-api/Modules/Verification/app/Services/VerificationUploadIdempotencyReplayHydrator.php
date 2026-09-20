<?php

declare(strict_types=1);

namespace Modules\Verification\Services;

use Illuminate\Http\Request;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\IdempotencyReplayHydrator;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

/**
 * Rehydrates a compact verification_upload idempotency pointer into a usable
 * create response. Signed URLs are issued at replay time and are never stored.
 */
final class VerificationUploadIdempotencyReplayHydrator implements IdempotencyReplayHydrator
{
    public function __construct(
        private readonly VerificationUploadService $uploads,
    ) {}

    public function hydrate(array $pointer, Request $request): ?array
    {
        if (($pointer['ref'] ?? null) !== 'verification_upload') {
            return null;
        }

        $uploadId = $pointer['id'] ?? null;
        if (! is_string($uploadId) || $uploadId === '') {
            return null;
        }

        $actor = $request->attributes->get(ActorContext::class);
        if (! $actor instanceof ActorContext) {
            return ['upload_id' => $uploadId];
        }

        try {
            return $this->uploads->replayDoctorUploadCreate($actor, Identifier::fromString($uploadId));
        } catch (AuthorizationDenied|FeatureUnavailable|InvalidValueObject) {
            return ['upload_id' => $uploadId];
        }
    }
}
