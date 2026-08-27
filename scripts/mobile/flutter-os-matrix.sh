#!/usr/bin/env bash
set -euo pipefail

# Flutter OS/device matrix evidence. Prints what this host can actually run.
# Does not invent Android/iOS backup results.

root="$(cd "$(dirname "$0")/../.." && pwd)"
out="${1:-$root/docs/evidence/security-review/flutter-os-matrix-2026-08-26.txt}"
mkdir -p "$(dirname "$out")"

{
  echo "flutter_os_matrix"
  echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "host_os=$(uname -s)"
  echo "host_arch=$(uname -m)"
  if command -v flutter >/dev/null 2>&1; then
    echo "flutter_bin=$(command -v flutter)"
    flutter --version || true
    echo "--- devices ---"
    flutter devices || true
    echo "--- package tests ---"
    (cd "$root/packages/flutter/authentication" && flutter test) || echo "authentication_tests_failed"
    echo "--- os backup restore ---"
    echo "android_emulator=not_run"
    echo "ios_simulator=not_run"
    echo "os_backup_restore=not_run host_is_linux"
    echo "status=PARTIAL_HOST_TESTS_ONLY"
  else
    echo "flutter_bin=absent"
    echo "android_emulator=not_run"
    echo "ios_simulator=not_run"
    echo "os_backup_restore=not_run"
    echo "status=BLOCKED_ON_HOST"
  fi
} | tee "$out"
