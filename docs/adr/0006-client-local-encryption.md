# ADR 0006 — Client-side local storage encryption

- **Status:** Accepted, with an open compatibility spike
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, mobile, desktop, security, privacy
- **Phase:** 00
- **Supersedes / Superseded by:** none

## Context

Three Flutter clients hold local state. `plan.md` section 153 requires the
doctor desktop local outbox to be encrypted, section 155 allows clinical drafts
to be written locally during a transient disconnection, and section 29 covers
consultation local resilience. The phase file requires local databases to store
the minimum needed, encrypt sensitive rows or database files, and expose sync
state.

A doctor desktop machine and a patient phone are outside the server trust
boundary. A clinical draft at rest on such a device is sensitive personal
clinical data under the classification in `docs/data-classification/`.

The phase file names a specific hazard: `sqlcipher_flutter_libs` is end of life
and must not be adopted. Drift over `sqlite3` v3 with native hooks for SQLCipher
or SQLite3MultipleCiphers is the stated direction, and adoption still requires a
target-OS compatibility spike.

## Decision

Local persistence uses Drift over `sqlite3` version 3, configured through native
hooks for full-database encryption using SQLCipher or SQLite3MultipleCiphers.
`sqlcipher_flutter_libs` is not used.

Key material is held by `flutter_secure_storage`, backed by the Keychain on
iOS/macOS, the Keystore on Android, DPAPI on Windows, and the Secret Service on
Linux. The database key never appears in Dart source, in logs, in crash reports,
or in a backup export.

Stored locally, at minimum:

- clinical drafts not yet synchronized;
- the local outbox described in `plan.md` section 153;
- cached reference data with no personal content;
- sync state and conflict markers.

Not stored locally: authentication secrets beyond the platform-secure token
store, full medical histories for browsing convenience, or any record whose only
copy would be the device.

The following remain open and are conditions of this ADR, tracked as gate
G-06-01:

1. A target-OS compatibility spike across Android, iOS, Windows, macOS, and
   Linux desktop.
2. Documented key rotation, recovery, backup exclusion, and migration tests.

Until the spike closes, no client may ship a build that writes clinical content
to local storage.

## Consequences

### Positive

- Device loss or theft does not expose clinical drafts at rest.
- Full-database encryption avoids per-column key handling in client code.
- Platform key stores keep key material out of application-controlled files.

### Negative / accepted cost

- Native encryption hooks add per-platform build complexity and a real
  possibility of platform-specific breakage on toolchain upgrades.
- An unrecoverable key means unrecoverable local drafts; recovery behavior must
  be explicit rather than silent data loss.
- Encrypted SQLite carries a measurable read/write cost.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| The chosen cipher package breaks on one target OS | Compatibility spike before adoption; the ADR does not close until all five targets pass |
| Local database is included in a device cloud backup | Explicit backup exclusion flags per platform, verified by test |
| Key rotation loses existing drafts | Documented rotation with re-encryption migration and a test that proves drafts survive |
| A draft is treated as authoritative | Server remains authoritative for final clinical state; the UI shows explicit sync status (`plan.md` section 155) |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| `sqlcipher_flutter_libs` | End of life; the phase file forbids it |
| Application-level field encryption over plain SQLite | Every query path must remember to encrypt; a single missed write leaks, and indexes over encrypted fields are impractical |
| No local persistence at all | Contradicts `plan.md` sections 29, 153, and 155, which require the doctor to keep working through a transient disconnection |
| Rely on full-disk encryption only | Not guaranteed enabled on desktop targets and not under application control |

## Migration and rollback impact

Forward: first client release that persists clinical content must ship with
encryption already enabled. There is no unencrypted-to-encrypted migration path
for production data, because no unencrypted production data may exist.

Rollback: disabling encryption is not permitted.

## Verification

- Compatibility spike results recorded for all five target platforms.
- Test: database file bytes do not contain a known canary string written through
  the encrypted store.
- Test: key rotation preserves existing drafts.
- Test: backup exclusion flag is set on each platform that supports one.
- MASVS storage checks in the mobile release gate.

## Review requirement

Engineering, security, and privacy. This ADR cannot move from "Accepted, with an
open compatibility spike" to "Accepted" without the spike evidence.
