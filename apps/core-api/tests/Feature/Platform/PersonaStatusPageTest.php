<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class);

$personas = [
    'admin' => ['path' => '/', 'component' => 'Admin/Status', 'titleEn' => 'Clinic admin', 'titleAr' => 'إدارة العيادة'],
    'patient' => ['path' => '/patient', 'component' => 'Patient/Status', 'titleEn' => 'Clinic patient', 'titleAr' => 'تطبيق المريض'],
    'doctor' => ['path' => '/doctor', 'component' => 'Doctor/Status', 'titleEn' => 'Clinic doctor', 'titleAr' => 'تطبيق الطبيب'],
    'pharmacy' => ['path' => '/pharmacy', 'component' => 'Pharmacy/Status', 'titleEn' => 'Clinic pharmacy', 'titleAr' => 'تطبيق الصيدلية'],
];

describe('Inertia persona status pages', function () use ($personas) {
    it('renders a safe English status projection for each persona', function () use ($personas) {
        foreach ($personas as $persona) {
            $this->withHeaders(['Accept-Language' => 'en'])
                ->get($persona['path'])
                ->assertOk()
                ->assertHeader('Content-Language', 'en')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertInertia(fn (Assert $page) => $page
                    ->component($persona['component'])
                    ->where('service', 'core-api')
                    ->where('status', 'up')
                    ->where('locale', 'en')
                    ->where('labels.title', $persona['titleEn'])
                    ->where('message', 'All services are operating normally.')
                    ->has('version')
                    ->missing('checks')
                    ->missing('telescope')
                    ->missing('host')
                );
        }
    });

    it('renders Arabic copy and an RTL document for each persona', function () use ($personas) {
        foreach ($personas as $persona) {
            $response = $this->withHeaders(['Accept-Language' => 'ar'])->get($persona['path']);

            $response->assertOk()
                ->assertHeader('Content-Language', 'ar')
                ->assertInertia(fn (Assert $page) => $page
                    ->component($persona['component'])
                    ->where('locale', 'ar')
                    ->where('labels.title', $persona['titleAr'])
                    ->where('message', 'جميع الخدمات تعمل بشكل طبيعي.')
                );

            expect($response->getContent())->toContain('dir="rtl"')
                ->and($response->getContent())->toContain('lang="ar"');
        }
    });

    it('issues a CSRF cookie and never stores a token in localStorage', function () {
        $response = $this->get('/');

        $response->assertOk()->assertCookie('XSRF-TOKEN');
        expect($response->getContent())->not->toContain('localStorage');
    });

    it('uses a same-origin CSP on Inertia pages and a deny-all CSP on the API', function () {
        $webCsp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $apiCsp = (string) $this->getJson('/api/v1/health')->headers->get('Content-Security-Policy');

        expect($webCsp)->toContain("default-src 'self'")
            ->and($webCsp)->toContain("frame-ancestors 'none'")
            ->and($webCsp)->toContain("object-src 'none'")
            ->and($webCsp)->not->toContain('unsafe-eval')
            ->and($webCsp)->not->toContain('bunny.net')
            ->and($apiCsp)->toContain("default-src 'none'");
    });

    it('does not leak infrastructure detail in the page payload', function () {
        $payload = json_encode($this->get('/')->original ?? []);

        expect((string) $payload)->not->toContain('postgres')
            ->and((string) $payload)->not->toContain('5432')
            ->and((string) $payload)->not->toContain('redis:')
            ->and((string) $payload)->not->toContain('password');
    });
});
