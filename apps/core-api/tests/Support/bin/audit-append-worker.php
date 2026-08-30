<?php

declare(strict_types=1);

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($payload)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'invalid_payload', 'sqlstate' => null]));
    exit(1);
}

$ready = (string) ($payload['ready_path'] ?? '');
$go = (string) ($payload['go_path'] ?? '');
if ($ready === '' || $go === '') {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'missing_barrier', 'sqlstate' => null]));
    exit(1);
}

file_put_contents($ready, '1');
$deadline = microtime(true) + 10;
while (! is_file($go)) {
    if (microtime(true) > $deadline) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'barrier_timeout', 'sqlstate' => null]));
        exit(2);
    }
    usleep(500);
}

$host = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1'));
$port = (string) (getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '5432'));
$database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? 'clinic_test'));
$username = (string) (getenv('DB_AUDIT_USERNAME') ?: ($_ENV['DB_AUDIT_USERNAME'] ?? 'clinic_audit_writer'));
$password = (string) (getenv('DB_AUDIT_PASSWORD') ?: ($_ENV['DB_AUDIT_PASSWORD'] ?? 'local_dev_only_not_a_secret'));

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("SET lock_timeout = '8s'");
    $pdo->exec("SET statement_timeout = '15s'");
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT clinic_append_audit_event(?::uuid, ?, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::timestamptz)',
    );
    $stmt->execute([
        (string) ($payload['id'] ?? ''),
        (string) ($payload['event_name'] ?? 'test.audit.concurrent'),
        $payload['actor_id'] ?? null,
        $payload['actor_type'] ?? null,
        (string) ($payload['object_type'] ?? 'user'),
        (string) ($payload['object_id'] ?? ''),
        (string) ($payload['metadata'] ?? '{"reason_code":"concurrent"}'),
        (string) ($payload['occurred_at'] ?? gmdate('Y-m-d H:i:s.uP')),
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $sqlstate = null;
    if ($e instanceof PDOException) {
        $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
    }
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'error' => $e::class,
        'sqlstate' => $sqlstate,
        'id' => $payload['id'] ?? null,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'error' => null,
    'sqlstate' => null,
    'id' => $payload['id'] ?? null,
], JSON_THROW_ON_ERROR));
