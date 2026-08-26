<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ISR-001 / ISR-002 / ISR-007 / ISR-008 follow-up schema.
 *
 * Least-privilege grants, consumed refresh ledger, cookie/session replay
 * envelope columns, recovery state machine, and serialized audit sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auth_refresh_consumptions')) {
            Schema::create('auth_refresh_consumptions', function (Blueprint $table): void {
                $table->uuid('family_id');
                $table->binary('token_hash');
                $table->unsignedInteger('generation');
                $table->timestampTz('consumed_at', 6);
                $table->primary(['family_id', 'token_hash']);
            });

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS auth_refresh_consumptions_token_hash_unique
                    ON auth_refresh_consumptions (token_hash)
            SQL);

            DB::statement(<<<'SQL'
                CREATE INDEX IF NOT EXISTS auth_refresh_consumptions_family_index
                    ON auth_refresh_consumptions (family_id, consumed_at DESC)
            SQL);
        }

        if (Schema::hasTable('user_devices') && ! Schema::hasColumn('user_devices', 'refresh_replay_ciphertext')) {
            Schema::table('user_devices', function (Blueprint $table): void {
                $table->binary('refresh_replay_ciphertext')->nullable();
                $table->binary('refresh_replay_idempotency_hmac')->nullable();
                $table->timestampTz('refresh_replay_expires_at', 6)->nullable();
            });
        }

        if (! Schema::hasTable('recovery_requests')) {
            Schema::create('recovery_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('otp_id');
                $table->string('status', 24);
                $table->string('new_password_hash');
                $table->timestampTz('cooling_off_until', 6)->nullable();
                $table->timestampTz('applied_at', 6)->nullable();
                $table->timestampTz('created_at', 6);
                $table->timestampTz('updated_at', 6);
            });

            DB::statement(<<<'SQL'
                ALTER TABLE recovery_requests
                    ADD CONSTRAINT recovery_requests_user_fk
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE recovery_requests
                    ADD CONSTRAINT recovery_requests_status_check
                    CHECK (status IN ('cooling_off', 'manual_review', 'applied', 'rejected', 'expired'))
            SQL);
        }

        if (Schema::hasTable('audit_events') && ! Schema::hasColumn('audit_events', 'chain_sequence')) {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('chain_sequence')->nullable();
            });

            DB::statement(<<<'SQL'
                WITH numbered AS (
                    SELECT id, row_number() OVER (ORDER BY occurred_at, id) AS seq
                    FROM audit_events
                )
                UPDATE audit_events AS a
                SET chain_sequence = numbered.seq
                FROM numbered
                WHERE a.id = numbered.id
            SQL);

            $next = (int) (DB::selectOne('SELECT COALESCE(MAX(chain_sequence), 0) + 1 AS n FROM audit_events')->n ?? 1);
            DB::statement('CREATE SEQUENCE IF NOT EXISTS audit_events_chain_sequence_seq START WITH '.$next);
            DB::statement("ALTER TABLE audit_events ALTER COLUMN chain_sequence SET DEFAULT nextval('audit_events_chain_sequence_seq')");
            DB::statement('ALTER TABLE audit_events ALTER COLUMN chain_sequence SET NOT NULL');
        } else {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS audit_events_chain_sequence_seq');
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS audit_events_chain_sequence_unique ON audit_events (chain_sequence)');

        DB::statement('CREATE SCHEMA IF NOT EXISTS reporting');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW reporting.account_status_counts AS
            SELECT account_type, status, count(*)::bigint AS n
            FROM users
            GROUP BY account_type, status
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW reporting.session_kind_counts AS
            SELECT session_kind, count(*)::bigint AS n
            FROM auth_sessions
            WHERE revoked_at IS NULL
            GROUP BY session_kind
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW reporting.audit_event_name_counts AS
            SELECT event_name, date_trunc('day', occurred_at) AS day, count(*)::bigint AS n
            FROM audit_events
            GROUP BY event_name, date_trunc('day', occurred_at)
        SQL);

        $this->ensureRoles();
        $this->applyLeastPrivilege();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS reporting.audit_event_name_counts');
        DB::statement('DROP VIEW IF EXISTS reporting.session_kind_counts');
        DB::statement('DROP VIEW IF EXISTS reporting.account_status_counts');

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('chain_sequence');
        });
        DB::statement('DROP SEQUENCE IF EXISTS audit_events_chain_sequence_seq');

        Schema::dropIfExists('recovery_requests');
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropColumn([
                'refresh_replay_ciphertext',
                'refresh_replay_idempotency_hmac',
                'refresh_replay_expires_at',
            ]);
        });
        Schema::dropIfExists('auth_refresh_consumptions');
    }

    private function ensureRoles(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_app') THEN
                    CREATE ROLE clinic_app WITH LOGIN PASSWORD 'local_dev_only_not_a_secret' CONNECTION LIMIT 40;
                    ALTER ROLE clinic_app SET timezone = 'UTC';
                    ALTER ROLE clinic_app SET statement_timeout = '10s';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_worker') THEN
                    CREATE ROLE clinic_worker WITH LOGIN PASSWORD 'local_dev_only_not_a_secret' CONNECTION LIMIT 30;
                    ALTER ROLE clinic_worker SET timezone = 'UTC';
                    ALTER ROLE clinic_worker SET statement_timeout = '60s';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_reporter') THEN
                    CREATE ROLE clinic_reporter WITH LOGIN PASSWORD 'local_dev_only_not_a_secret' CONNECTION LIMIT 10;
                    ALTER ROLE clinic_reporter SET timezone = 'UTC';
                    ALTER ROLE clinic_reporter SET statement_timeout = '120s';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_audit_writer') THEN
                    CREATE ROLE clinic_audit_writer WITH LOGIN PASSWORD 'local_dev_only_not_a_secret' CONNECTION LIMIT 10;
                    ALTER ROLE clinic_audit_writer SET timezone = 'UTC';
                    ALTER ROLE clinic_audit_writer SET statement_timeout = '10s';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_backup') THEN
                    CREATE ROLE clinic_backup WITH LOGIN PASSWORD 'local_dev_only_not_a_secret' CONNECTION LIMIT 5;
                    ALTER ROLE clinic_backup SET timezone = 'UTC';
                END IF;
            END
            $$;
        SQL);
    }

    private function applyLeastPrivilege(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_migrator') THEN
                    EXECUTE 'ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public REVOKE SELECT ON TABLES FROM clinic_reporter';
                    EXECUTE 'ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM clinic_worker';
                    EXECUTE 'ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO clinic_app';
                    EXECUTE 'ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO clinic_app';
                END IF;
            END
            $$;
        SQL);

        $appTables = [
            'users',
            'identity_national_ids',
            'identity_profile_links',
            'user_devices',
            'otp_requests',
            'mfa_factors',
            'mfa_recovery_codes',
            'mfa_challenges',
            'auth_sessions',
            'auth_refresh_consumptions',
            'recovery_requests',
            'contextual_access_grants',
            'outbox_events',
            'idempotency_keys',
            'platform_diagnostics',
            'features',
            'platform_config_audits',
            'notifications',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
        ];

        foreach ($appTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', $table);
            $this->revokeIfRole('clinic_reporter', 'ALL', $table);
            $this->revokeIfRole('clinic_worker', 'ALL', $table);
        }

        foreach (['jobs', 'job_batches', 'failed_jobs', 'outbox_events', 'notifications'] as $table) {
            if (Schema::hasTable($table)) {
                $this->grantIfRole('clinic_worker', 'SELECT, INSERT, UPDATE, DELETE', $table);
            }
        }

        if (Schema::hasTable('otp_requests')) {
            $this->grantIfRole('clinic_worker', 'SELECT, UPDATE', 'otp_requests');
        }

        if (Schema::hasTable('audit_events')) {
            DB::statement('REVOKE ALL ON TABLE audit_events FROM PUBLIC');
            $this->revokeIfRole('clinic_app', 'ALL', 'audit_events');
            $this->revokeIfRole('clinic_worker', 'ALL', 'audit_events');
            $this->revokeIfRole('clinic_reporter', 'ALL', 'audit_events');
            $this->grantIfRole('clinic_app', 'SELECT, INSERT', 'audit_events');
            $this->grantIfRole('clinic_audit_writer', 'SELECT, INSERT', 'audit_events');
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_app') THEN
                    EXECUTE 'GRANT USAGE ON SCHEMA public TO clinic_app';
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_worker') THEN
                    EXECUTE 'GRANT USAGE ON SCHEMA public TO clinic_worker';
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_reporter') THEN
                    EXECUTE 'GRANT USAGE ON SCHEMA public TO clinic_reporter';
                    EXECUTE 'GRANT USAGE ON SCHEMA reporting TO clinic_reporter';
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_audit_writer') THEN
                    EXECUTE 'GRANT USAGE ON SCHEMA public TO clinic_audit_writer';
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_backup') THEN
                    EXECUTE 'GRANT USAGE ON SCHEMA public TO clinic_backup';
                END IF;
            END
            $$;
        SQL);
        DB::statement('GRANT SELECT ON reporting.account_status_counts TO clinic_reporter');
        DB::statement('GRANT SELECT ON reporting.session_kind_counts TO clinic_reporter');
        DB::statement('GRANT SELECT ON reporting.audit_event_name_counts TO clinic_reporter');
        $this->grantIfRole('clinic_backup', 'SELECT', 'users');
    }

    private function grantIfRole(string $role, string $privileges, string $table): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'GRANT {$privileges} ON TABLE {$table} TO {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }

    private function revokeIfRole(string $role, string $privileges, string $table): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'REVOKE {$privileges} ON TABLE {$table} FROM {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }
};
