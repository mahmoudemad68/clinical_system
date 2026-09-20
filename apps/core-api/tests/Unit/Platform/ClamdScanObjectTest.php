<?php

declare(strict_types=1);

use Modules\Platform\Enums\ScanOutcome;
use Modules\Platform\Services\Adapters\ClamdScanObject;
use Modules\Platform\Support\BoundedSocketWriter;
use Modules\Platform\Support\ScanVerdict;
use Tests\Support\ClinicPartialWriteStreamWrapper;
use Tests\Support\FixtureScanObject;
use Tests\TestCase;

uses(TestCase::class);

function clamdStub(string $reply, int $sleepMs = 0): array
{
    $portFile = tempnam(sys_get_temp_dir(), 'clamdport');
    assert(is_string($portFile));
    file_put_contents($portFile, '');
    $command = [PHP_BINARY, base_path('tests/Support/bin/clamd-stub.php'), $reply, (string) $sleepMs, $portFile];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, base_path());
    assert(is_resource($process));
    fclose($pipes[0]);
    $port = 0;
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $contents = trim((string) file_get_contents($portFile));
        if (ctype_digit($contents) && (int) $contents > 0) {
            $port = (int) $contents;
            break;
        }
        usleep(10_000);
    }
    expect($port)->toBeGreaterThan(0);

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'port' => $port, 'portFile' => $portFile];
}

function clamdStubStop(array $stub): void
{
    fclose($stub['stdout']);
    fclose($stub['stderr']);
    proc_close($stub['process']);
    if (isset($stub['portFile']) && is_string($stub['portFile']) && is_file($stub['portFile'])) {
        unlink($stub['portFile']);
    }
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

it('requires the exact declared byte count and fails closed on short writes', function () {
    $stub = clamdStub("stream: OK\n");
    $scanner = new ClamdScanObject('127.0.0.1', $stub['port'], 2000, 20_971_520, 'stub');

    $bytes = verificationMinimalPdf();
    $short = fopen('php://temp', 'r+');
    assert(is_resource($short));
    fwrite($short, substr($bytes, 0, 8));
    rewind($short);
    $shortVerdict = $scanner->scanStream($short, strlen($bytes));
    fclose($short);
    clamdStubStop($stub);
    expect($shortVerdict->outcome)->not->toBe(ScanOutcome::Clean)
        ->and($shortVerdict->outcome)->toBe(ScanOutcome::Invalid);

    $longStub = clamdStub("stream: OK\n");
    $longScanner = new ClamdScanObject('127.0.0.1', $longStub['port'], 2000, 20_971_520, 'stub');
    $long = fopen('php://temp', 'r+');
    assert(is_resource($long));
    fwrite($long, $bytes.'EXTRA');
    rewind($long);
    $longVerdict = $longScanner->scanStream($long, strlen($bytes));
    fclose($long);
    clamdStubStop($longStub);
    expect($longVerdict->outcome)->not->toBe(ScanOutcome::Clean)
        ->and($longVerdict->outcome)->toBe(ScanOutcome::Invalid);

    $exactStub = clamdStub("stream: OK\n");
    $exact = new ClamdScanObject('127.0.0.1', $exactStub['port'], 2000, 20_971_520, 'stub');
    expect(clamdScan($exact, $bytes)->outcome)->toBe(ScanOutcome::Clean);
    clamdStubStop($exactStub);

    if (! in_array('clinicpartial', stream_get_wrappers(), true)) {
        stream_wrapper_register('clinicpartial', ClinicPartialWriteStreamWrapper::class);
    }
    $partial = fopen('clinicpartial://ok', 'r+');
    assert(is_resource($partial));
    expect(BoundedSocketWriter::writeAll($partial, 'abcdef'))->toBeTrue();
    fclose($partial);

    $fail = fopen('clinicpartial://fail', 'r+');
    assert(is_resource($fail));
    expect(BoundedSocketWriter::writeAll($fail, 'abcdef'))->toBeFalse();
    fclose($fail);
});

it('scans a live clamd when one is reachable', function () {
    clinicSkipUnlessTcp($this, '127.0.0.1', 3310, 'CLINIC_REQUIRE_CLAMAV', 'clamd');

    $scanner = new ClamdScanObject('127.0.0.1', 3310, 10_000, 20_971_520, '1.4.6');
    expect(clamdScan($scanner, verificationMinimalPdf())->outcome)->toBe(ScanOutcome::Clean)
        ->and(clamdScan($scanner, FixtureScanObject::EICAR)->outcome)->toBe(ScanOutcome::Infected);
});
