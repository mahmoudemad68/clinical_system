<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\WorkerStarting;
use Modules\Auth\Contracts\PasswordHasher;
use Modules\Auth\Listeners\AttachUnknownUserDummyPrimeHeader;
use Modules\Auth\Listeners\PrimeUnknownUserPasswordDummy;
use Modules\Auth\Services\Crypto\Argon2idPasswordHasher;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);

function argonProbeHasher(object $probe): Hasher
{
    return new class($probe) implements Hasher
    {
        public function __construct(private object $probe) {}

        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            $this->probe->makes++;
            $this->probe->order[] = 'make';
            $this->probe->made[] = (string) $value;

            return 'hashed:'.$value;
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            $this->probe->checks++;
            $this->probe->order[] = 'check';
            $this->probe->checked[] = [(string) $value, (string) $hashedValue];

            return $hashedValue === 'hashed:'.$value;
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }
    };
}

function argonProbe(): object
{
    return (object) [
        'makes' => 0,
        'checks' => 0,
        'order' => [],
        'made' => [],
        'checked' => [],
    ];
}

function argonDummyCachePath(): string
{
    return sys_get_temp_dir().'/clinic-argon-dummy-'
        .config('hashing.argon.memory').'-'
        .config('hashing.argon.time').'-'
        .config('hashing.argon.threads').'.hash';
}

function argonPlantedDummyHash(): string
{
    return '$argon2id$v=19$m=16384,t=1,p=1$c29tZXNhbHRzb21lc2FsdA$c29tZWhhc2hzb21laGFzaHNvbWVoYXNoc29tZWhhc2g';
}

describe('argon2id password hasher', function () {
    beforeEach(function () {
        @unlink(argonDummyCachePath());
        @unlink(argonDummyCachePath().'.lock');
    });

    afterEach(function () {
        @unlink(argonDummyCachePath());
        @unlink(argonDummyCachePath().'.lock');
    });
    it('does not kdf in the constructor', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));

        expect($probe->makes)->toBe(0)
            ->and($passwords->unknownUserDummyIsPrimed())->toBeFalse();
    });

    it('primes the unknown-user dummy with one make and is idempotent', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));

        $passwords->primeUnknownUserDummy();
        expect($probe->makes)->toBe(1)
            ->and($passwords->unknownUserDummyIsPrimed())->toBeTrue()
            ->and($probe->made)->toBe(['timing-balanced-unknown-user']);

        $passwords->primeUnknownUserDummy();
        expect($probe->makes)->toBe(1)
            ->and($probe->checks)->toBe(0);
    });

    it('verifies the dummy after prime without another make', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        $passwords->primeUnknownUserDummy();

        $passwords->dummyVerify('attacker-guess');

        expect($probe->makes)->toBe(1)
            ->and($probe->checks)->toBe(1)
            ->and($probe->order)->toBe(['make', 'check'])
            ->and($probe->checked[0][0])->toBe('attacker-guess')
            ->and($probe->checked[0][1])->toBe('hashed:timing-balanced-unknown-user');
    });

    it('still hashes and verifies real passwords through the hasher', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        $passwords->primeUnknownUserDummy();

        $hash = $passwords->hash('another-secret');
        expect($hash)->toBe('hashed:another-secret')
            ->and($passwords->verify('another-secret', $hash))->toBeTrue()
            ->and($passwords->verify('wrong', $hash))->toBeFalse()
            ->and($probe->makes)->toBe(2)
            ->and($probe->checks)->toBe(2);
    });

    it('last-resort dummyVerify primes once then only checks', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));

        $passwords->dummyVerify('guess');
        $passwords->dummyVerify('guess-two');

        expect($probe->order)->toBe(['make', 'check', 'check'])
            ->and($probe->makes)->toBe(1)
            ->and($probe->checks)->toBe(2);
    });

    it('reuses a valid dummy cache file without another make', function () {
        $path = argonDummyCachePath();
        file_put_contents($path, argonPlantedDummyHash());

        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        $passwords->primeUnknownUserDummy();
        $passwords->dummyVerify('attacker-guess');

        expect($probe->makes)->toBe(0)
            ->and($probe->checks)->toBe(1)
            ->and($probe->checked[0][1])->toBe(argonPlantedDummyHash())
            ->and($passwords->unknownUserDummyIsPrimed())->toBeTrue();
    });

    it('does not persist a non-argon2id probe hash into the worker cache', function () {
        $path = argonDummyCachePath();
        @unlink($path);

        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        $passwords->primeUnknownUserDummy();

        expect($probe->makes)->toBe(1)
            ->and(is_file($path))->toBeFalse();
    });

    it('keeps the Phase 01 Argon2id parameter defaults', function () {
        $source = (string) file_get_contents(config_path('hashing.php'));

        expect($source)
            ->toContain("env('HASH_DRIVER', 'argon2id')")
            ->toContain("env('ARGON_MEMORY', 65536)")
            ->toContain("env('ARGON_THREADS', 1)")
            ->toContain("env('ARGON_TIME', 4)")
            ->and(config('hashing.driver'))->toBe('argon2id');
    });
});

describe('octane worker dummy priming', function () {
    afterEach(function () {
        @unlink(argonDummyCachePath());
        @unlink(argonDummyCachePath().'.lock');
        config()->set('octane.worker_probe', false);
    });

    it('registers the worker-start listener next to Octane warm resolution', function () {
        $listeners = config('octane.listeners.'.WorkerStarting::class);

        expect($listeners)->toContain(PrimeUnknownUserPasswordDummy::class)
            ->and(config('octane.listeners.'.RequestHandled::class))->toContain(AttachUnknownUserDummyPrimeHeader::class)
            ->and(config('octane.warm'))->toContain(PasswordHasher::class);
    });

    it('primes the container hasher when an Octane worker starts', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        app()->instance(PasswordHasher::class, $passwords);

        expect($passwords->unknownUserDummyIsPrimed())->toBeFalse();

        (new PrimeUnknownUserPasswordDummy)->handle(new WorkerStarting(app()));

        expect($passwords->unknownUserDummyIsPrimed())->toBeTrue()
            ->and($probe->makes)->toBe(1)
            ->and($probe->checks)->toBe(0);

        (new PrimeUnknownUserPasswordDummy)->handle(new WorkerStarting(app()));
        expect($probe->makes)->toBe(1);
    });

    it('also primes from the Auth service provider WorkerStarting registration', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        app()->instance(PasswordHasher::class, $passwords);

        event(new WorkerStarting(app()));

        expect($passwords->unknownUserDummyIsPrimed())->toBeTrue()
            ->and($probe->makes)->toBe(1);
    });

    it('exposes dummy priming on the local worker-probe header', function () {
        $probe = argonProbe();
        $passwords = new Argon2idPasswordHasher(argonProbeHasher($probe));
        $passwords->primeUnknownUserDummy();
        app()->instance(PasswordHasher::class, $passwords);
        config()->set('octane.worker_probe', true);

        $response = new Response('ok', 200);
        (new AttachUnknownUserDummyPrimeHeader)->handle(new RequestHandled(
            app(),
            request(),
            $response,
        ));

        expect($response->headers->get('X-Octane-Argon-Dummy-Primed'))->toBe('1');
    });
});
