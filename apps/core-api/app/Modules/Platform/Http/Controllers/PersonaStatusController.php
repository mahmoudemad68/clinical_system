<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Status\PlatformStatusQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * First-party Inertia status pages for the four personas.
 *
 * Props are a safe projection. Locale and copy come from the negotiated
 * request language, not from a client-owned catalogue.
 */
final class PersonaStatusController
{
    public function __invoke(Request $request, PlatformStatusQuery $query, string $persona): Response
    {
        $component = match ($persona) {
            'admin' => 'Admin/Status',
            'patient' => 'Patient/Status',
            'doctor' => 'Doctor/Status',
            'pharmacy' => 'Pharmacy/Status',
            default => throw new NotFoundHttpException,
        };

        $page = $query->handle();
        $locale = (string) $request->attributes->get('locale', 'en');

        return Inertia::render($component, [
            'service' => $page->service,
            'version' => $page->version,
            'status' => $page->status,
            'message' => __('health.status.operational'),
            'locale' => $locale,
            'labels' => [
                'title' => __('status.personas.'.$persona),
                'service' => __('status.fields.service'),
                'version' => __('status.fields.version'),
                'status' => __('status.fields.status'),
                'message' => __('status.fields.message'),
            ],
        ]);
    }
}
