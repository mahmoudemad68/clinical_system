-- Least-privilege database roles (Phase 00 §4.1).
--
-- Runs once, on first initialization of an empty data directory.
--
-- The separation matters: the application role must not be able to change the
-- schema. If it can, a SQL injection defect escalates from "read or modify rows"
-- to "drop the audit table". The migration role carries DDL rights and is used
-- only by the migration job, never by a serving process. Reporting is read-only
-- and cannot see the tables holding raw sensitive columns.
--
-- Passwords here are local development values only. Real credentials come from
-- the secret manager, are separate per environment and per workload, and are
-- never committed (plan.md section 169).

\set ON_ERROR_STOP on

-- --------------------------------------------------------------- extensions
-- postgis: geography(POINT) for clinic locations and pharmacy branches.
-- citext:  case-insensitive text where the semantics are genuinely defined.
--          NOT for national IDs, barcodes, or opaque identifiers, which are
--          compared byte for byte (phase file, database conventions).
-- pgcrypto: gen_random_bytes for server-side key material where needed.
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- -------------------------------------------------------------------- roles

-- Owns the schema. Used by the migration job only.
CREATE ROLE clinic_migrator WITH LOGIN PASSWORD 'local_dev_only_not_a_secret';

-- The serving application. DML only, no DDL.
CREATE ROLE clinic_app WITH LOGIN PASSWORD 'local_dev_only_not_a_secret';

-- Queue workers. Same rights as the app today, separated so their traffic is
-- attributable and their privileges can diverge later without a rewrite.
CREATE ROLE clinic_worker WITH LOGIN PASSWORD 'local_dev_only_not_a_secret';

-- Read-only reporting. Never granted access to tables holding raw sensitive
-- columns; analytics reads de-identified projections (module catalog).
CREATE ROLE clinic_reporter WITH LOGIN PASSWORD 'local_dev_only_not_a_secret';

-- --------------------------------------------------------------- ownership

ALTER SCHEMA public OWNER TO clinic_migrator;

-- Revoke the permissive default. PostgreSQL 15+ already removes CREATE on
-- public from PUBLIC, but stating it makes the intent explicit and survives a
-- restore onto an older major version.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO clinic_app, clinic_worker, clinic_reporter;
GRANT CREATE, USAGE ON SCHEMA public TO clinic_migrator;

-- ------------------------------------------------------- default privileges
-- Applied to objects the migrator creates from now on, so a new table does not
-- need a follow-up GRANT that someone will forget.

ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO clinic_app, clinic_worker;

ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO clinic_app, clinic_worker;

ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO clinic_reporter;

-- Explicitly withhold DDL from the serving roles. This is the control that
-- turns a SQL injection defect from catastrophic into merely serious.
REVOKE CREATE ON SCHEMA public FROM clinic_app, clinic_worker, clinic_reporter;

-- -------------------------------------------------------------- connections
-- Bound each role so a runaway worker pool cannot exhaust connections and take
-- the serving path down with it. Production uses PgBouncer in front of these
-- (plan.md section 138); the per-role cap is a second line of defence.
ALTER ROLE clinic_app       CONNECTION LIMIT 40;
ALTER ROLE clinic_worker    CONNECTION LIMIT 30;
ALTER ROLE clinic_reporter  CONNECTION LIMIT 10;
ALTER ROLE clinic_migrator  CONNECTION LIMIT 5;

-- Statement timeouts. A serving query that runs longer than this is a defect,
-- and letting it run holds locks that make everything else worse.
ALTER ROLE clinic_app       SET statement_timeout = '10s';
ALTER ROLE clinic_worker    SET statement_timeout = '60s';
ALTER ROLE clinic_reporter  SET statement_timeout = '120s';
-- The migrator gets a longer ceiling but not an unlimited one: a migration that
-- exceeds this needs the expand/backfill/contract treatment, not more patience.
ALTER ROLE clinic_migrator  SET statement_timeout = '300s';

-- Never let a serving role sit in an open transaction holding locks.
ALTER ROLE clinic_app       SET idle_in_transaction_session_timeout = '15s';
ALTER ROLE clinic_worker    SET idle_in_transaction_session_timeout = '60s';

-- All roles think in UTC. Business time zone conversion happens at the edge.
ALTER ROLE clinic_app       SET timezone = 'UTC';
ALTER ROLE clinic_worker    SET timezone = 'UTC';
ALTER ROLE clinic_reporter  SET timezone = 'UTC';
ALTER ROLE clinic_migrator  SET timezone = 'UTC';
