<?php

declare(strict_types=1);

use Modules\Platform\Enums\ScanOutcome;
use Modules\Platform\Services\Adapters\ClamdScanObject;
use Modules\Platform\Support\ScanVerdict;
use Tests\Support\FixtureScanObject;
use Tests\TestCase;

uses(TestCase::class);

function clamdStub(string $reply, int $sleepMs = 0): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/bin/clamd-stub.php')).' '
        .escapeshellarg($reply).' '.escapeshellarg((string) $sleepMs);
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, base_path());
    assert(is_resource($process));
    fclose($pipes[0]);
    $line = '';
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $line .= (string) stream_get_contents($pipes[1]);
        if (str_contains($line, "\n")) {
            break;
        }
        usleep(10_000);
    }
    $address = trim($line);
    $port = (int) substr($address, strrpos($address, ':') + 1);

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'port' => $port];
}

function clamdStubStop(array $stub): void
{
    fclose($stub['stdout']);
    fclose($stub['stderr']);
    proc_close($stub['process']);
}

function clamdScan(ClamdScanObject $scanner, string $bytes): ScanVerdict
{
    $stream = fopen('php://temp', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $bytes);
    rewind($stream);
    $verdict = $scanner->scanStream($stream, strlen($bytes));
    fclose($stream);

    return $verdict;
}

it('treats a clean stub reply as clean and a FOUND reply as infected', function () {
    $cleanStub = clamdStub("stream: OK\n");
    $clean = new ClamdScanObject('127.0.0.1', $cleanStub['port'], 2000, 20_971_520, 'stub');
    $cleanVerdict = clamdScan($clean, verificationMinimalPdf());
    clamdStubStop($cleanStub);
    expect($cleanVerdict->outcome)->toBe(ScanOutcome::Clean);

    $infectedStub = clamdStub("stream: Eicar-Test-Signature FOUND\n");
    $infected = new ClamdScanObject('127.0.0.1', $infectedStub['port'], 2000, 20_971_520, 'stub');
    $infectedVerdict = clamdScan($infected, FixtureScanObject::EICAR);
    clamdStubStop($infectedStub);
    expect($infectedVerdict->outcome)->toBe(ScanOutcome::Infected)->and($infectedVerdict->isClean())->toBeFalse();
});

it('fails closed on unavailable, timeout, and malformed replies', function () {
    $down = new ClamdScanObject('127.0.0.1', 1, 500, 20_971_520, 'stub');
    expect(clamdScan($down, 'ok')->outcome)->toBe(ScanOutcome::Unavailable);

    $slow = clamdStub("stream: OK\n", 3000);
    $timeout = new ClamdScanObject('127.0.0.1', $slow['port'], 200, 20_971_520, 'stub');
    $timed = clamdScan($timeout, verificationMinimalPdf());
    clamdStubStop($slow);
    expect($timed->outcome)->toBe(ScanOutcome::Unavailable);

    $bad = clamdStub("WAT\n");
    $invalid = new ClamdScanObject('127.0.0.1', $bad['port'], 2000, 20_971_520, 'stub');
    $malformed = clamdScan($invalid, verificationMinimalPdf());
    clamdStubStop($bad);
    expect($malformed->outcome)->toBe(ScanOutcome::Invalid);
});

it('scans a live clamd when one is reachable', function () {
    $socket = @fsockopen('127.0.0.1', 3310, $errno, $error, 0.2);
    if (! is_resource($socket)) {
        test()->markTestSkipped('clamd is not reachable.');
    }
    fclose($socket);

    $scanner = new ClamdScanObject('127.0.0.1', 3310, 10_000, 20_971_520, '1.4.6');
    expect(clamdScan($scanner, verificationMinimalPdf())->outcome)->toBe(ScanOutcome::Clean)
        ->and(clamdScan($scanner, FixtureScanObject::EICAR)->outcome)->toBe(ScanOutcome::Infected);
});
