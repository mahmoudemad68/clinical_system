<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', env('APP_ENV') === 'production' ? 'verify-full' : 'prefer'),
            'sslrootcert' => env('DB_SSLROOTCERT', env('PGSSLROOTCERT')) ?: null,
            'sslcert' => env('DB_SSLCERT') ?: null,
            'sslkey' => env('DB_SSLKEY') ?: null,
        ],

        /*
        |----------------------------------------------------------------------
        | Migration connection
        |----------------------------------------------------------------------
        |
        | DDL rights live here and nowhere else (Phase 00 §4.1). The serving
        | roles cannot alter the schema, so a SQL injection defect in a request
        | path cannot escalate from reading rows to dropping the audit table.
        |
        | Used only by the migration job: `php artisan migrate --database=pgsql_migrator`.
        | No serving process, queue worker, or scheduler may select it.
        |
        */
        'pgsql_migrator' => [
            'driver' => 'pgsql',
            'url' => env('DB_MIGRATION_URL', env('DB_URL')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_MIGRATION_USERNAME', 'clinic_migrator'),
            'password' => env('DB_MIGRATION_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', env('APP_ENV') === 'production' ? 'verify-full' : 'prefer'),
            'sslrootcert' => env('DB_SSLROOTCERT', env('PGSSLROOTCERT')) ?: null,
            'sslcert' => env('DB_SSLCERT') ?: null,
            'sslkey' => env('DB_SSLKEY') ?: null,
        ],

        'pgsql_worker' => [
            'driver' => 'pgsql',
            'url' => env('DB_WORKER_URL', env('DB_URL')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_WORKER_USERNAME', 'clinic_worker'),
            'password' => env('DB_WORKER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', env('APP_ENV') === 'production' ? 'verify-full' : 'prefer'),
            'sslrootcert' => env('DB_SSLROOTCERT', env('PGSSLROOTCERT')) ?: null,
            'sslcert' => env('DB_SSLCERT') ?: null,
            'sslkey' => env('DB_SSLKEY') ?: null,
        ],

        'pgsql_reporter' => [
            'driver' => 'pgsql',
            'url' => env('DB_REPORTER_URL', env('DB_URL')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_REPORTER_USERNAME', 'clinic_reporter'),
            'password' => env('DB_REPORTER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'reporting,public',
            'sslmode' => env('DB_SSLMODE', env('APP_ENV') === 'production' ? 'verify-full' : 'prefer'),
            'sslrootcert' => env('DB_SSLROOTCERT', env('PGSSLROOTCERT')) ?: null,
            'sslcert' => env('DB_SSLCERT') ?: null,
            'sslkey' => env('DB_SSLKEY') ?: null,
        ],

        'pgsql_audit' => [
            'driver' => 'pgsql',
            'url' => env('DB_AUDIT_URL', env('DB_URL')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_AUDIT_USERNAME', 'clinic_audit_writer'),
            'password' => env('DB_AUDIT_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', env('APP_ENV') === 'production' ? 'verify-full' : 'prefer'),
            'sslrootcert' => env('DB_SSLROOTCERT', env('PGSSLROOTCERT')) ?: null,
            'sslcert' => env('DB_SSLCERT') ?: null,
            'sslkey' => env('DB_SSLKEY') ?: null,
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        /*
        |----------------------------------------------------------------------
        | Named Redis roles
        |----------------------------------------------------------------------
        |
        | Four separate connections from day one (plan.md sections 113-114).
        | Locally they share one instance and differ only by database index;
        | production moves queues onto their own instance so queue pressure
        | cannot starve realtime delivery. Because callers already address a
        | named connection, that move is configuration and not a code change.
        |
        | Nothing here is ever a source of truth. An empty Redis after restart
        | must degrade performance temporarily, never lose a medical or
        | business record (ADR 0007).
        |
        */

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'queue' => [
            'url' => env('REDIS_QUEUE_URL', env('REDIS_URL')),
            'host' => env('REDIS_QUEUE_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_QUEUE_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_QUEUE_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_QUEUE_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_QUEUE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'realtime' => [
            'url' => env('REDIS_REALTIME_URL', env('REDIS_URL')),
            'host' => env('REDIS_REALTIME_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_REALTIME_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_REALTIME_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_REALTIME_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_REALTIME_DB', '2'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'ratelimit' => [
            'url' => env('REDIS_RATELIMIT_URL', env('REDIS_URL')),
            'host' => env('REDIS_RATELIMIT_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_RATELIMIT_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_RATELIMIT_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_RATELIMIT_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_RATELIMIT_DB', '3'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
