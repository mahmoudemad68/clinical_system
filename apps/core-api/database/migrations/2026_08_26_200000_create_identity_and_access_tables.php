<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 01 identity, session, OTP, MFA, link, grant, and audit schema.
 *
 * There are no production users. The Laravel stub `users` / email reset
 * tables are replaced in this expand step. Notifications morphs move to UUID
 * so inbox rows can reference identity primary keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->binary('phone_e164_encrypted');
            $table->binary('phone_lookup_hmac');
            $table->unsignedSmallInteger('phone_key_version')->default(1);
            $table->string('password_hash');
            $table->string('account_type', 16);
            $table->string('status', 16);
            $table->string('language', 8);
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->timestampTz('phone_verified_at', 6)->nullable();
            $table->timestampTz('last_authenticated_at', 6)->nullable();
            $table->boolean('bootstrap_exempt')->default(false);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_account_type_check
                CHECK (account_type IN ('patient', 'doctor', 'pharmacy', 'secretary', 'admin'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_status_check
                CHECK (status IN ('pending_phone', 'active', 'suspended', 'locked', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_language_check
                CHECK (language IN ('ar', 'en'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_active_phone_verified_check
                CHECK (
                    status <> 'active'
                    OR phone_verified_at IS NOT NULL
                    OR bootstrap_exempt = true
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX users_phone_lookup_hmac_active_unique
                ON users (phone_lookup_hmac)
                WHERE status IN ('pending_phone', 'active', 'suspended', 'locked')
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX users_status_created_at_index
                ON users (status, created_at)
        SQL);

        Schema::create('identity_national_ids', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->binary('national_id_encrypted');
            $table->binary('national_id_lookup_hmac');
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE identity_national_ids
                ADD CONSTRAINT identity_national_ids_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_national_ids_hmac_unique
                ON identity_national_ids (national_id_lookup_hmac)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_national_ids_user_unique
                ON identity_national_ids (user_id)
        SQL);

        Schema::table('sessions', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE INDEX sessions_user_id_index ON sessions (user_id)
        SQL);

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropMorphs('notifiable');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->uuidMorphs('notifiable');
        });

        Schema::create('user_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('platform', 16);
            $table->string('device_label', 120);
            $table->binary('token_hash')->nullable();
            $table->binary('refresh_token_hash')->nullable();
            $table->binary('previous_refresh_token_hash')->nullable();
            $table->uuid('refresh_family_id')->nullable();
            $table->unsignedInteger('refresh_generation')->default(0);
            $table->unsignedBigInteger('credential_version');
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('refresh_expires_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->binary('push_token_ciphertext')->nullable();
            $table->string('created_ip_prefix', 64)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE user_devices
                ADD CONSTRAINT user_devices_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE user_devices
                ADD CONSTRAINT user_devices_platform_check
                CHECK (platform IN ('android', 'ios', 'windows', 'macos', 'linux', 'web'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_devices_active_token_hash_unique
                ON user_devices (token_hash)
                WHERE token_hash IS NOT NULL AND revoked_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_devices_active_refresh_hash_unique
                ON user_devices (refresh_token_hash)
                WHERE refresh_token_hash IS NOT NULL AND revoked_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX user_devices_user_revoked_expires_index
                ON user_devices (user_id, revoked_at, expires_at)
        SQL);

        Schema::create('otp_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('purpose', 32);
            $table->binary('subject_lookup_hmac');
            $table->binary('code_hash');
            $table->binary('code_ciphertext');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('invalidated_at', 6)->nullable();
            $table->string('requested_ip_prefix', 64)->nullable();
            $table->binary('device_fingerprint_hmac')->nullable();
            $table->string('provider_message_reference', 128)->nullable();
            $table->string('locale', 8);
            $table->binary('destination_ciphertext');
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->string('delivery_status', 24)->default('pending');
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE otp_requests
                ADD CONSTRAINT otp_requests_purpose_check
                CHECK (purpose IN ('registration', 'phone_change', 'recovery', 'profile_claim'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE otp_requests
                ADD CONSTRAINT otp_requests_delivery_status_check
                CHECK (delivery_status IN ('pending', 'sent', 'retryable', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX otp_requests_subject_purpose_created_index
                ON otp_requests (subject_lookup_hmac, purpose, created_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX otp_requests_open_challenges_index
                ON otp_requests (expires_at)
                WHERE consumed_at IS NULL AND invalidated_at IS NULL
        SQL);

        Schema::create('mfa_factors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('factor_type', 16);
            $table->binary('secret_ciphertext');
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->unsignedBigInteger('last_used_counter')->nullable();
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
            $table->timestampTz('disabled_at', 6)->nullable();
            $table->uuid('disabled_by')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mfa_factors
                ADD CONSTRAINT mfa_factors_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mfa_factors
                ADD CONSTRAINT mfa_factors_type_check
                CHECK (factor_type IN ('totp'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mfa_factors_one_active_totp
                ON mfa_factors (user_id)
                WHERE factor_type = 'totp' AND disabled_at IS NULL
        SQL);

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('factor_id');
            $table->binary('code_hash');
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mfa_recovery_codes
                ADD CONSTRAINT mfa_recovery_codes_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mfa_recovery_codes
                ADD CONSTRAINT mfa_recovery_codes_factor_fk
                FOREIGN KEY (factor_id) REFERENCES mfa_factors (id) ON DELETE CASCADE
        SQL);

        Schema::create('mfa_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('client_class', 32);
            $table->string('platform', 16);
            $table->string('device_label', 120);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mfa_challenges
                ADD CONSTRAINT mfa_challenges_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('device_id')->nullable();
            $table->string('session_kind', 16);
            $table->binary('session_hash');
            $table->string('assurance_level', 32);
            $table->boolean('csrf_established')->default(false);
            $table->timestampTz('idle_expires_at', 6)->nullable();
            $table->timestampTz('absolute_expires_at', 6);
            $table->unsignedBigInteger('credential_version');
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestampTz('last_seen_at', 6);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auth_sessions
                ADD CONSTRAINT auth_sessions_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE auth_sessions
                ADD CONSTRAINT auth_sessions_kind_check
                CHECK (session_kind IN ('device', 'admin_cookie'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX auth_sessions_session_hash_unique
                ON auth_sessions (session_hash)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX auth_sessions_user_active_index
                ON auth_sessions (user_id, absolute_expires_at)
                WHERE revoked_at IS NULL
        SQL);

        Schema::create('identity_profile_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('profile_type', 32);
            $table->uuid('profile_id');
            $table->string('link_status', 16);
            $table->string('assurance_level', 32);
            $table->uuid('proof_reference')->nullable();
            $table->timestampTz('linked_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE identity_profile_links
                ADD CONSTRAINT identity_profile_links_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE identity_profile_links
                ADD CONSTRAINT identity_profile_links_status_check
                CHECK (link_status IN ('pending', 'active', 'revoked', 'disputed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE identity_profile_links
                ADD CONSTRAINT identity_profile_links_type_check
                CHECK (profile_type IN ('patient', 'doctor', 'clinic_staff', 'pharmacy_membership'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_profile_links_active_profile_unique
                ON identity_profile_links (profile_type, profile_id)
                WHERE link_status = 'active'
        SQL);

        Schema::create('contextual_access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id');
            $table->string('capability', 120);
            $table->string('resource_type', 80);
            $table->uuid('resource_id');
            $table->string('context_type', 80);
            $table->uuid('context_id');
            $table->timestampTz('valid_from', 6)->nullable();
            $table->timestampTz('valid_until', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->string('reason_code', 64);
            $table->string('issued_by_type', 32);
            $table->uuid('issued_by_id');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE contextual_access_grants
                ADD CONSTRAINT contextual_access_grants_actor_fk
                FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contextual_access_grants_lookup_index
                ON contextual_access_grants (
                    actor_user_id, capability, resource_type, resource_id, context_type, context_id
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contextual_access_grants_active_unique
                ON contextual_access_grants (
                    actor_user_id, capability, resource_type, resource_id, context_type, context_id
                )
                WHERE revoked_at IS NULL
        SQL);

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name', 120);
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->string('object_type', 80);
            $table->uuid('object_id');
            $table->jsonb('metadata');
            $table->binary('previous_hash')->nullable();
            $table->binary('row_hash');
            $table->timestampTz('occurred_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_no_update_delete
                CHECK (true)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX audit_events_object_index
                ON audit_events (object_type, object_id, occurred_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX audit_events_actor_index
                ON audit_events (actor_id, occurred_at DESC)
                WHERE actor_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_app') THEN
                    REVOKE UPDATE, DELETE ON audit_events FROM clinic_app;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_worker') THEN
                    REVOKE UPDATE, DELETE ON audit_events FROM clinic_worker;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_reporter') THEN
                    REVOKE UPDATE, DELETE ON audit_events FROM clinic_reporter;
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('contextual_access_grants');
        Schema::dropIfExists('identity_profile_links');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_factors');
        Schema::dropIfExists('otp_requests');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('identity_national_ids');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropMorphs('notifiable');
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->morphs('notifiable');
        });

        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
        });
    }
};
