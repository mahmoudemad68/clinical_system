<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Console\BootstrapAdminCommand;
use Modules\Auth\Contracts\TotpVerifier;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Modules\Platform\Services\Time\FrozenClock;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    $path = bootstrapAdminUriPath();
    if (is_file($path)) {
        unlink($path);
    }
});

function bootstrapAdminPassword(): string
{
    return 'correct-horse-battery';
}

function bootstrapAdminPhone(): string
{
    return (new SyntheticEgyptianData)->mobileNumber();
}

function bootstrapAdminUriPath(): string
{
    return storage_path('app/private/bootstrap-totp.uri');
}

function bootstrapAdminBindClock(): DateTimeImmutable
{
    $now = new DateTimeImmutable('2026-08-28 12:00:00', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($now));

    return $now;
}

function bootstrapAdminStart(mixed $test, string $phone): string
{
    $test->artisan('identity:bootstrap-admin', ['phone' => $phone])
        ->expectsQuestion('Password', bootstrapAdminPassword())
        ->doesntExpectOutputToContain('otpauth://')
        ->doesntExpectOutputToContain('secret=')
        ->assertSuccessful();

    $userId = DB::table('users')->where('account_type', AccountType::Admin->value)->value('id');
    expect($userId)->toBeString();

    return (string) $userId;
}

function bootstrapAdminPendingSecret(string $userId): string
{
    $row = DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->whereNull('disabled_at')
        ->first();

    expect($row)->not->toBeNull();

    return app(NationalIdProtector::class)->decryptSecret(
        'mfa_secret',
        BinaryColumn::asString($row->secret_ciphertext),
    );
}

function bootstrapAdminVerifiedAt(string $userId): mixed
{
    return DB::table('mfa_factors')
        ->where('user_id', $userId)
        ->where('factor_type', 'totp')
        ->value('verified_at');
}

describe('bootstrap admin command signature', function () {
    it('does not expose a TOTP secret option on the artisan definition', function () {
        $command = $this->app->make(Kernel::class)->all()['identity:bootstrap-admin'];
        $definition = $command->getDefinition();
        $optionNames = array_keys($definition->getOptions());
        $argumentNames = array_keys($definition->getArguments());
        $secretNames = array_values(array_filter(
            [...$optionNames, ...$argumentNames],
            static fn (string $name): bool => (bool) preg_match('/totp|otp|secret|\bcode\b/i', $name),
        ));

        expect($definition->hasOption('totp-code'))->toBeFalse()
            ->and($definition->hasOption('totp'))->toBeFalse()
            ->and($definition->hasOption('code'))->toBeFalse()
            ->and($secretNames)->toBe([])
            ->and($command->getSynopsis())->not->toContain('totp-code')
            ->and($command->getSynopsis())->not->toContain('--totp');
    });

    it('does not advertise --totp-code on help or in the command signature', function () {
        $signature = (new ReflectionProperty(BootstrapAdminCommand::class, 'signature'))
            ->getValue($this->app->make(BootstrapAdminCommand::class));

        expect($signature)->toBe('identity:bootstrap-admin {phone} {--name=Bootstrap Admin} {--confirm}')
            ->and($signature)->not->toContain('totp-code')
            ->and($signature)->not->toContain('totp');

        $this->artisan('identity:bootstrap-admin', ['--help' => true])
            ->doesntExpectOutputToContain('--totp-code')
            ->doesntExpectOutputToContain('totp-code')
            ->assertSuccessful();
    });

    it('rejects a TOTP passed as a command-line option instead of consuming it', function () {
        expect(fn () => $this->artisan('identity:bootstrap-admin', [
            'phone' => bootstrapAdminPhone(),
            '--confirm' => true,
            '--totp-code' => '123456',
        ])->run())->toThrow(InvalidOptionException::class, 'The "--totp-code" option does not exist.');
    });
});

describe('one-time bootstrap', function () {
    it('creates an unverified TOTP factor without printing the provisioning secret', function () {
        $phone = bootstrapAdminPhone();

        $userId = bootstrapAdminStart($this, $phone);

        expect(bootstrapAdminVerifiedAt($userId))->toBeNull()
            ->and(is_file(bootstrapAdminUriPath()))->toBeTrue()
            ->and(DB::table('audit_events')->where('event_name', 'identity.bootstrap_started')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('event_name', 'identity.bootstrap_confirmed')->count())->toBe(0)
            ->and(DB::table('users')->where('account_type', AccountType::Admin->value)->count())->toBe(1);
    });

    it('denies a second bootstrap admin after the first identity exists', function () {
        $first = bootstrapAdminPhone();
        bootstrapAdminStart($this, $first);

        $this->artisan('identity:bootstrap-admin', ['phone' => bootstrapAdminPhone()])
            ->expectsOutput('An admin identity already exists.')
            ->assertFailed();

        expect(DB::table('users')->where('account_type', AccountType::Admin->value)->count())->toBe(1);
    });
});

describe('interactive TOTP confirmation', function () {
    it('confirms enrollment when the hidden prompt receives a valid TOTP', function () {
        $now = bootstrapAdminBindClock();
        $phone = bootstrapAdminPhone();
        $userId = bootstrapAdminStart($this, $phone);
        $code = app(TotpVerifier::class)->codeAt(bootstrapAdminPendingSecret($userId), $now);

        $this->artisan('identity:bootstrap-admin', [
            'phone' => $phone,
            '--confirm' => true,
        ])
            ->expectsQuestion('Confirmation TOTP', $code)
            ->doesntExpectOutputToContain($code)
            ->doesntExpectOutputToContain('otpauth://')
            ->expectsOutputToContain('TOTP enrollment confirmed.')
            ->assertSuccessful();

        expect(bootstrapAdminVerifiedAt($userId))->not->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'identity.bootstrap_confirmed')->count())->toBe(1);
    });

    it('rejects an invalid TOTP without marking the factor verified', function () {
        $phone = bootstrapAdminPhone();
        $userId = bootstrapAdminStart($this, $phone);

        $this->artisan('identity:bootstrap-admin', [
            'phone' => $phone,
            '--confirm' => true,
        ])
            ->expectsQuestion('Confirmation TOTP', '000000')
            ->doesntExpectOutputToContain('000000')
            ->expectsOutput('TOTP confirmation failed.')
            ->assertFailed();

        expect(bootstrapAdminVerifiedAt($userId))->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'identity.bootstrap_confirmed')->count())->toBe(0);
    });

    it('fails safely when the hidden TOTP prompt is empty', function () {
        $phone = bootstrapAdminPhone();
        $userId = bootstrapAdminStart($this, $phone);

        $this->artisan('identity:bootstrap-admin', [
            'phone' => $phone,
            '--confirm' => true,
        ])
            ->expectsQuestion('Confirmation TOTP', '')
            ->expectsOutput('A confirmation TOTP is required.')
            ->assertFailed();

        expect(bootstrapAdminVerifiedAt($userId))->toBeNull()
            ->and(DB::table('audit_events')->where('event_name', 'identity.bootstrap_confirmed')->count())->toBe(0);
    });

    it('does not print the confirmation TOTP in console output', function () {
        $now = bootstrapAdminBindClock();
        $phone = bootstrapAdminPhone();
        $userId = bootstrapAdminStart($this, $phone);
        $code = app(TotpVerifier::class)->codeAt(bootstrapAdminPendingSecret($userId), $now);

        $this->artisan('identity:bootstrap-admin', [
            'phone' => $phone,
            '--confirm' => true,
        ])
            ->expectsQuestion('Confirmation TOTP', $code)
            ->doesntExpectOutputToContain($code)
            ->assertSuccessful();
    });

    it('denies confirmation replay after the factor is already verified', function () {
        $now = bootstrapAdminBindClock();
        $phone = bootstrapAdminPhone();
        $userId = bootstrapAdminStart($this, $phone);
        $code = app(TotpVerifier::class)->codeAt(bootstrapAdminPendingSecret($userId), $now);

        $this->artisan('identity:bootstrap-admin', [
            'phone' => $phone,
            '--confirm' => true,
        ])
            ->expectsQuestion('Confirmation TOTP', $code)
            ->assertSuccessful();

        $this->artisan('identity:bootstrap-admin', [
            'phone' => $phone,
            '--confirm' => true,
        ])
            ->expectsQuestion('Confirmation TOTP', $code)
            ->expectsOutput('No pending TOTP enrollment exists.')
            ->assertFailed();

        expect(DB::table('audit_events')->where('event_name', 'identity.bootstrap_confirmed')->count())->toBe(1);
    });
});
