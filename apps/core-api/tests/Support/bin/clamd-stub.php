<?php

declare(strict_types=1);

/**
 * One-shot clamd INSTREAM stub. Prints the bound address, accepts one client,
 * reads framed INSTREAM chunks until a zero-length frame, then writes the
 * configured reply. Used only in isolated tests.
 */
$reply = (string) ($argv[1] ?? "stream: OK\n");
$sleepMs = (int) ($argv[2] ?? 0);
$portFile = (string) ($argv[3] ?? '');

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    fwrite(STDERR, "bind_failed\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
if (! is_string($name) || ! str_contains($name, ':')) {
    fwrite(STDERR, "bind_name_failed\n");
    exit(1);
}
$port = (int) substr($name, strrpos($name, ':') + 1);
if ($portFile !== '') {
    file_put_contents($portFile, (string) $port);
}
fwrite(STDOUT, $name."\n");
fflush(STDOUT);

$client = @stream_socket_accept($server, 8);
if (! is_resource($client)) {
    exit(0);
}

stream_set_timeout($client, 8);

$command = '';
while (! str_contains($command, "\n")) {
    $char = fread($client, 1);
    if ($char === false || $char === '') {
        break;
    }
    $command .= $char;
}

if (! str_contains($command, 'INSTREAM')) {
    fwrite($client, $reply);
    fclose($client);
    fclose($server);
    exit(0);
}

while (true) {
    $lenBytes = '';
    while (strlen($lenBytes) < 4) {
        $chunk = fread($client, 4 - strlen($lenBytes));
        if ($chunk === false || $chunk === '') {
            break 2;
        }
        $lenBytes .= $chunk;
    }
    $len = unpack('N', $lenBytes)[1] ?? 0;
    if ($len === 0) {
        break;
    }
    $left = $len;
    while ($left > 0) {
        $chunk = fread($client, min(8192, $left));
        if ($chunk === false || $chunk === '') {
            break 2;
        }
        $left -= strlen($chunk);
    }
}

if ($sleepMs > 0) {
    usleep($sleepMs * 1000);
}

fwrite($client, $reply);
fclose($client);
fclose($server);
