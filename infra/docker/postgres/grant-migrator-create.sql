-- Operator one-shot for volumes initialized before clinic_migrator received
-- CREATE on the serving database. Run as clinic_owner, then re-run migrations.
-- Initdb already applies this on a new data directory.
GRANT CREATE ON DATABASE clinic TO clinic_migrator;
