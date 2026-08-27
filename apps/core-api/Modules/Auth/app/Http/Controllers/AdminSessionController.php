<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Auth\Contracts\AuthDirectory;
use Modules\Auth\Services\AuthenticatePasswordService;
use Modules\Auth\Services\CompleteMfaService;
use Modules\Auth\Services\CredentialIssuer;
use Modules\Auth\Services\SessionCommandService;
use Modules\Identity\Services\ResolveActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Exceptions\AuthenticationFailed;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Http\Support\ClosedJsonValidator;
use Modules\Platform\Support\Identifier;

/**
 * First-party admin cookie session. Tokens never enter the browser.
 */
final class AdminSessionController
{
    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Login', [
            'locale' => (string) $request->attributes->get('locale', 'en'),
            'labels' => [
                'title' => __('auth.login_title'),
                'phone' => __('auth.phone'),
                'password' => __('auth.password'),
                'submit' => __('auth.sign_in'),
                'failed' => __('auth.failed'),
            ],
        ]);
    }

    public function store(Request $request, AuthenticatePasswordService $handler): RedirectResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        try {
            $payload = $handler->handle(
                $data['phone'],
                $data['password'],
                'admin_web',
                'web',
                'admin-browser',
                $this->ipPrefix($request),
            );
        } catch (AuthenticationFailed) {
            return back()->withErrors(['phone' => __('auth.failed')]);
        }

        if (($payload['mfa_required'] ?? false) === true) {
            $request->session()->put('mfa_challenge_id', $payload['challenge_id'] ?? null);

            return redirect()->route('admin.mfa');
        }

        $this->establishCookie($request, $payload);

        return redirect('/');
    }

    public function mfa(Request $request): Response|RedirectResponse
    {
        if (! is_string($request->session()->get('mfa_challenge_id'))) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('Admin/Mfa', [
            'locale' => (string) $request->attributes->get('locale', 'en'),
            'labels' => [
                'title' => __('auth.mfa_title'),
                'code' => __('auth.mfa_code'),
                'submit' => __('auth.verify'),
                'failed' => __('auth.mfa_failed'),
            ],
        ]);
    }

    public function verifyMfa(Request $request, CompleteMfaService $handler): RedirectResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'code' => ['required', 'string', 'size:6'],
        ]);

        $challengeId = $request->session()->get('mfa_challenge_id');
        if (! is_string($challengeId)) {
            return redirect()->route('admin.login');
        }

        try {
            $payload = $handler->handle($challengeId, $data['code'], $this->ipPrefix($request));
        } catch (InvalidValueObject) {
            return back()->withErrors(['code' => __('auth.mfa_failed')]);
        }

        $request->session()->forget('mfa_challenge_id');
        $this->establishCookie($request, $payload);

        return redirect('/');
    }

    public function destroy(
        Request $request,
        SessionCommandService $handler,
        ResolveActorContext $resolver,
    ): RedirectResponse {
        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            try {
                $actor = $resolver->fromCookieUser(
                    Identifier::fromTrusted((string) $user->getAuthIdentifier()),
                    (string) $request->session()->getId(),
                );
                $handler->logoutCurrent($actor);
            } catch (AuthenticationFailed) {
                // Laravel session still invalidated below.
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function establishCookie(Request $request, array $payload): void
    {
        if (($payload['session_kind'] ?? null) !== 'admin_cookie') {
            return;
        }

        $user = User::query()->find($payload['user_id'] ?? null);
        if ($user instanceof User) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $sessionId = $payload['session_id'] ?? null;
            if (is_string($sessionId) && $sessionId !== '') {
                app(AuthDirectory::class)->bindCookieSessionHash(
                    Identifier::fromTrusted($sessionId),
                    app(CredentialIssuer::class)->hashToken('cookie:'.$request->session()->getId()),
                    app(Clock::class)->now(),
                );
            }
        }
    }

    private function ipPrefix(Request $request): string
    {
        $ip = $request->ip() ?? '0.0.0.0';

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4)).'::';
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? $parts[0].'.'.$parts[1].'.'.$parts[2].'.0' : '0.0.0.0';
    }
}
