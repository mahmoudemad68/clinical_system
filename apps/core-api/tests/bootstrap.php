<?php

declare(strict_types=1);

/*
 * PHPUnit force="true" updates getenv(), but a shell APP_ENV=local still
 * remains in $_ENV. Laravel env() reads $_ENV first, which would bind
 * AppendAuditEvent to pgsql_audit during tests and skip InMemoryStoreObject.
 */
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
