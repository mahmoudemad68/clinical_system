<?php

declare(strict_types=1);

/**
 * One-shot clamd INSTREAM stub. Prints the bound address, accepts one client,
 * and writes the configured reply. Used only in isolated tests.
 */
$reply = (string) ($argv[1] ?? "stream: OK\n");
$sleepMs = (int) ($argv[2] ?? 0);

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    fwrite(STDERR, "bind_failed\n");
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
fflush(STDOUT);

$client = @stream_socket_accept($server, 8);
if (! is_resource($client)) {
    exit(0);
}

if ($sleepMs > 0) {
    usleep($sleepMs * 1000);
}

stream_get_contents($client);
fwrite($client, $reply);
fclose($client);
fclose($server);
