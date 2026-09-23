<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Crypto;

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Contracts\PasswordHasher;

final class Argon2idPasswordHasher implements PasswordHasher
{
    private const DUMMY_PLAIN = 'timing-balanced-unknown-user';

    private ?string $dummyHash = null;

    public function __construct(private readonly Hasher $hasher) {}

    public function hash(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $this->hasher->check($plain, $hash);
    }

    public function primeUnknownUserDummy(): void
    {
        if ($this->dummyHash !== null) {
            return;
        }

        if (! $this->sharesDummyAcrossOctaneWorkers()) {
            $this->dummyHash = $this->hasher->make(self::DUMMY_PLAIN);

            return;
        }

        $this->primeSerializedAcrossWorkers();
    }

    public function dummyVerify(string $plain): void
    {
        $this->hasher->check($plain, $this->dummyHash());
    }

    public function unknownUserDummyIsPrimed(): bool
    {
        return $this->dummyHash !== null;
    }

    /**
     * Dummy Argon2id material is computed once per hasher instance:
     *
     * - Octane: WorkerStarting primes the root-worker singleton before
     *   frankenphp_handle_request(). Concurrent workers serialize the one-time
     *   make() through a param-bound cache file so boot is one KDF, not 16.
     * - FPM/CLI/Pest: no worker hook. AuthenticatePasswordService primes
     *   before the known/unknown branch so both classes pay the same KDF
     *   work in-process. Unrelated requests still skip priming.
     */
    private function dummyHash(): string
    {
        $this->primeUnknownUserDummy();

        return $this->dummyHash ?? throw new \LogicException('unknown-user dummy was not primed');
    }

    private function sharesDummyAcrossOctaneWorkers(): bool
    {
        $flag = $_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE');

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function primeSerializedAcrossWorkers(): void
    {
        $path = $this->cachePath();
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            $this->dummyHash = $this->hasher->make(self::DUMMY_PLAIN);

            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                $this->dummyHash = $this->hasher->make(self::DUMMY_PLAIN);

                return;
            }

            rewind($handle);
            $existing = $this->validatedDummyHash(trim((string) stream_get_contents($handle)));
            if (is_string($existing)) {
                $this->dummyHash = $existing;

                return;
            }

            $this->dummyHash = $this->hasher->make(self::DUMMY_PLAIN);
            if ($this->validatedDummyHash($this->dummyHash) === null) {
                return;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $this->dummyHash);
            fflush($handle);
            @chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function cachePath(): string
    {
        $memory = (string) config('hashing.argon.memory', 65536);
        $time = (string) config('hashing.argon.time', 4);
        $threads = (string) config('hashing.argon.threads', 1);

        return sys_get_temp_dir().'/clinic-argon-dummy-'.$memory.'-'.$time.'-'.$threads.'.hash';
    }

    private function validatedDummyHash(string $hash): ?string
    {
        if ($hash === '' || ! str_starts_with($hash, '$argon2id$')) {
            return null;
        }

        return $hash;
    }
}
