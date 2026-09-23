#!/usr/bin/env bash
# Canonical Core API schema upgrade.
#
# 1. Privileged PostgreSQL role-level provisioning (clinic_owner when
#    DB_OWNER_USERNAME is set; otherwise the current migrator identity).
# 2. Application migrations as clinic_migrator.
#
# A BASE cluster with clinic_audit_writer.rolconnlimit = 10 must finish at 40.
# If the privileged change cannot be applied, this script fails and must not
# record the raise-audit-writer migration as complete.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/apps/core-api"

php artisan platform:provision-postgres-roles
php artisan migrate --database=pgsql_migrator --force
