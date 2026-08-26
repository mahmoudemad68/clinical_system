<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shared Inertia props. No actor, tenant, or clinical data in Phase 00.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => (string) $request->attributes->get('locale', 'en'),
            'authenticated' => $request->user() !== null,
        ];
    }
}
