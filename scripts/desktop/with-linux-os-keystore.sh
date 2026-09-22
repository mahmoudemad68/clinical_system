#!/usr/bin/env bash
# Start a session Secret Service so Electron safeStorage can select
# gnome_libsecret. This does not enable Linux basic_text.
#
# Headless CI / cloud agents: wrap the Forge Doctor practice E2E with this
# script. Developer machines that already have an unlocked login keyring
# should not nest a second D-Bus session.
set -euo pipefail

required=0
flag="${CLINIC_REQUIRE_DOCTOR_PRACTICE_E2E:-}"
flag_lc="$(printf '%s' "$flag" | tr '[:upper:]' '[:lower:]')"
if [[ "$flag_lc" == "1" || "$flag_lc" == "true" || "$flag_lc" == "yes" || "$flag_lc" == "on" ]]; then
  required=1
fi

if [[ $# -lt 1 ]]; then
  echo "usage: with-linux-os-keystore.sh <command> [args...]" >&2
  exit 2
fi

if [[ "$(uname -s)" != "Linux" ]]; then
  exec "$@"
fi

if [[ "${CLINIC_LINUX_KEYSTORE_NESTED:-}" == "1" ]]; then
  exec "$@"
fi

if ! command -v dbus-run-session >/dev/null 2>&1 || ! command -v gnome-keyring-daemon >/dev/null 2>&1; then
  if [[ "$required" -eq 1 ]]; then
    echo "dbus-run-session and gnome-keyring-daemon are required when CLINIC_REQUIRE_DOCTOR_PRACTICE_E2E is enabled." >&2
    echo "Install gnome-keyring dbus-x11 libsecret-1-0." >&2
    exit 1
  fi
  exec "$@"
fi

export CLINIC_LINUX_KEYSTORE_NESTED=1
export XDG_CURRENT_DESKTOP="${XDG_CURRENT_DESKTOP:-GNOME}"
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/tmp/runtime-$(id -u)}"
mkdir -p "$XDG_RUNTIME_DIR"
chmod 700 "$XDG_RUNTIME_DIR"

exec dbus-run-session -- bash -c '
  set -euo pipefail
  required="$1"
  shift

  import_keyring_env() {
    local line
    while IFS= read -r line; do
      if [[ "$line" =~ ^[A-Z_][A-Z0-9_]+= ]]; then
        export "$line"
      fi
    done <<< "$1"
  }

  # CI-only local keyring passphrase. Not a production secret.
  import_keyring_env "$(printf "%s" "ci-keyring" | gnome-keyring-daemon --unlock --replace --components=secrets 2>/dev/null || true)"
  if [[ -z "${GNOME_KEYRING_CONTROL:-}" ]]; then
    import_keyring_env "$(gnome-keyring-daemon --start --components=secrets 2>/dev/null || true)"
    printf "%s" "ci-keyring" | gnome-keyring-daemon --unlock >/dev/null 2>&1 || true
  fi

  if command -v secret-tool >/dev/null 2>&1; then
    if ! printf "%s" "clinic-ci" | secret-tool store --label="clinic-ci-keystore" service clinic.ci user e2e; then
      if [[ "$required" -eq 1 ]]; then
        echo "secret-tool could not store into gnome-keyring; Electron safeStorage would fail closed." >&2
        exit 1
      fi
    else
      secret-tool clear service clinic.ci user e2e >/dev/null 2>&1 || true
    fi
  elif [[ "$required" -eq 1 ]]; then
    echo "secret-tool is required when CLINIC_REQUIRE_DOCTOR_PRACTICE_E2E is enabled so the keystore can be probed." >&2
    exit 1
  fi
  exec "$@"
' bash "$required" "$@"
