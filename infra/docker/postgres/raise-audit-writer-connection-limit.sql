-- Privileged role-level change. Run as clinic_owner (or an equivalent
-- cluster owner), then run application migrations.
--
-- clinic_migrator may carry CREATEROLE without ADMIN OPTION on roles it did
-- not create, so ALTER ROLE clinic_audit_writer from the migration job is
-- not a reliable upgrade path on an existing volume.
-- Initdb already applies CONNECTION LIMIT 40 on a new data directory.
-- Canonical PHP equivalent: php artisan platform:provision-postgres-roles
\set ON_ERROR_STOP on
ALTER ROLE clinic_audit_writer CONNECTION LIMIT 40;
