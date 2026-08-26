<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\ApplyRecoveryHandler;
use App\Modules\Auth\Application\AuthenticatePasswordHandler;
use App\Modules\Auth\Application\CompleteMfaHandler;
use App\Modules\Auth\Application\CompleteRecoveryHandler;
use App\Modules\Auth\Application\ConfirmTotpHandler;
use App\Modules\Auth\Application\CredentialIssuer;
use App\Modules\Auth\Application\DisableTotpHandler;
use App\Modules\Auth\Application\EnrollTotpHandler;
use App\Modules\Auth\Application\RefreshDeviceSessionHandler;
use App\Modules\Auth\Application\RegisterAccountCoordinator;
use App\Modules\Auth\Application\RequestOtpHandler;
use App\Modules\Auth\Application\RotateRecoveryCodesHandler;
use App\Modules\Auth\Application\SessionCommandHandler;
use App\Modules\Auth\Application\VerifyOtpHandler;
use App\Modules\Auth\Domain\Contracts\AuthDirectory;
use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use App\Modules\Auth\Domain\Rules\PasswordPolicy;
use App\Modules\Identity\Application\MeQuery;
use App\Modules\Identity\Domain\ValueObjects\ActorContext;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Http\Responses\Envelope;
use App\Modules\Platform\Http\Support\ClosedJsonValidator;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class AuthController
{
    public function csrf(Request $request): JsonResponse
    {
        $request->session()->regenerateToken();

        return Envelope::ok(['csrf' => true], $this->requestId($request));
    }

    public function register(Request $request, RegisterAccountCoordinator $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:32'],
            'national_id' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:128'],
            'language' => ['required', Rule::in(['ar', 'en'])],
        ]);

        $result = $handler->handle($data, $this->ipPrefix($request), $request->header('X-Device-Fingerprint'));

        return Envelope::created($result->toArray(), $this->requestId($request));
    }

    public function requestOtp(Request $request, RequestOtpHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'phone' => ['required', 'string', 'max:32'],
            'purpose' => ['required', Rule::in(['registration', 'phone_change', 'recovery', 'profile_claim'])],
            'language' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);

        $result = $handler->handle(
            $data['phone'],
            $data['purpose'],
            $data['language'] ?? 'en',
            $this->ipPrefix($request),
        );

        return Envelope::ok($result->toArray(), $this->requestId($request));
    }

    public function verifyOtp(Request $request, VerifyOtpHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:6'],
            'client_class' => ['required', Rule::in(['patient_mobile', 'doctor_desktop', 'pharmacy_desktop', 'admin_web'])],
            'platform' => ['required', Rule::in(['android', 'ios', 'windows', 'macos', 'linux', 'web'])],
            'device_label' => ['required', 'string', 'max:120'],
        ]);

        $payload = $handler->handle(
            $data['challenge_id'],
            $data['code'],
            $data['client_class'],
            $data['platform'],
            $data['device_label'],
        );

        $this->establishAdminCookie($request, $payload);

        return Envelope::ok($payload, $this->requestId($request));
    }

    public function login(Request $request, AuthenticatePasswordHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:128'],
            'client_class' => ['required', Rule::in(['patient_mobile', 'doctor_desktop', 'pharmacy_desktop', 'admin_web'])],
            'platform' => ['required', Rule::in(['android', 'ios', 'windows', 'macos', 'linux', 'web'])],
            'device_label' => ['required', 'string', 'max:120'],
        ]);

        $payload = $handler->handle(
            $data['phone'],
            $data['password'],
            $data['client_class'],
            $data['platform'],
            $data['device_label'],
            $this->ipPrefix($request),
        );

        $this->establishAdminCookie($request, $payload);

        return Envelope::ok($payload, $this->requestId($request));
    }

    public function verifyMfa(Request $request, CompleteMfaHandler $handler, string $id): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'code' => ['required', 'string', 'size:6'],
        ]);

        $payload = $handler->handle($id, $data['code'], $this->ipPrefix($request));
        $this->establishAdminCookie($request, $payload);

        return Envelope::ok($payload, $this->requestId($request));
    }

    public function refresh(Request $request, RefreshDeviceSessionHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'refresh_token' => ['required', 'string', 'max:256'],
        ]);

        return Envelope::ok($handler->handle(
            $data['refresh_token'],
            $request->headers->get('Idempotency-Key'),
            $this->ipPrefix($request),
        ), $this->requestId($request));
    }

    public function logout(Request $request, SessionCommandHandler $handler): JsonResponse
    {
        $handler->logoutCurrent($this->actor($request));
        Auth::guard('web')->logout();
        $request->session()?->invalidate();

        return Envelope::ok(['revoked' => true], $this->requestId($request));
    }

    public function sessions(Request $request, SessionCommandHandler $handler): JsonResponse
    {
        return Envelope::ok($handler->list($this->actor($request)), $this->requestId($request));
    }

    public function destroySession(Request $request, SessionCommandHandler $handler, string $sessionId): JsonResponse
    {
        $handler->revoke($this->actor($request), $sessionId);

        return Envelope::ok(['revoked' => true], $this->requestId($request));
    }

    public function revokeAll(Request $request, SessionCommandHandler $handler): JsonResponse
    {
        $handler->revokeAll($this->actor($request));

        return Envelope::ok(['revoked' => true], $this->requestId($request));
    }

    public function changePassword(Request $request, SessionCommandHandler $handler, PasswordHasher $hasher, PasswordPolicy $policy): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'current_password' => ['required', 'string', 'max:128'],
            'new_password' => ['required', 'string', 'max:128'],
        ]);

        $handler->changePassword($this->actor($request), $data['current_password'], $data['new_password'], $hasher, $policy);

        return Envelope::ok(['credential_rotated' => true], $this->requestId($request));
    }

    public function recoveryStart(Request $request, RequestOtpHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'phone' => ['required', 'string', 'max:32'],
            'language' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);

        $result = $handler->handle($data['phone'], 'recovery', $data['language'] ?? 'en', $this->ipPrefix($request));

        return Envelope::ok($result->toArray(), $this->requestId($request));
    }

    public function recoveryComplete(Request $request, CompleteRecoveryHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $result = $handler->handle($data['challenge_id'], $data['code'], $data['password']);

        return Envelope::ok($result, $this->requestId($request));
    }

    public function me(Request $request, MeQuery $query): JsonResponse
    {
        return Envelope::ok($query->handle($this->actor($request)), $this->requestId($request));
    }

    public function capabilities(Request $request, MeQuery $query): JsonResponse
    {
        return Envelope::ok(['capabilities' => $query->capabilities($this->actor($request))], $this->requestId($request));
    }

    public function enrollTotp(Request $request, EnrollTotpHandler $handler): JsonResponse
    {
        return Envelope::ok($handler->handle($this->actor($request)), $this->requestId($request));
    }

    public function confirmTotp(Request $request, ConfirmTotpHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'code' => ['required', 'string', 'size:6'],
        ]);

        return Envelope::ok($handler->handle($this->actor($request), $data['code']), $this->requestId($request));
    }

    public function rotateRecoveryCodes(Request $request, RotateRecoveryCodesHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'code' => ['required', 'string', 'size:6'],
        ]);

        return Envelope::ok($handler->handle($this->actor($request), $data['code']), $this->requestId($request));
    }

    public function disableTotp(Request $request, DisableTotpHandler $handler): JsonResponse
    {
        $data = ClosedJsonValidator::validate($request, [
            'code' => ['required', 'string', 'size:6'],
        ]);
        $handler->handle($this->actor($request), $data['code']);

        return Envelope::ok(['disabled' => true], $this->requestId($request));
    }

    public function applyRecovery(Request $request, ApplyRecoveryHandler $handler, string $id): JsonResponse
    {
        $status = $handler->handle($this->actor($request), Identifier::fromString($id));

        return Envelope::ok(['status' => $status], $this->requestId($request));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function establishAdminCookie(Request $request, array $payload): void
    {
        if (($payload['session_kind'] ?? null) !== 'admin_cookie' || ($payload['mfa_required'] ?? false) === true) {
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

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get(ActorContext::class);
        if (! $actor instanceof ActorContext) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
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
