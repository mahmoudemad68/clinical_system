# Accountable owners

Recorded 2026-08-26 against Phase 00 entry criteria (one accountable human each
for clinical, pharmacy, privacy/legal, security, and operations).

| Role | Person |
| --- | --- |
| Clinical | Mahmoud |
| Pharmacy | Mahmoud |
| Privacy / legal | Mahmoud |
| Security | Mahmoud |
| Operations | Mahmoud |

GitHub CODEOWNERS still uses `@clinic/...` team handles. Those teams do not
exist, so branch protection cannot enforce this file. This document is the
human source of truth until an organization and usernames exist.

## Independence

The same person implements the platform and holds every approval role.
Assessor/remediator separation is **lost**. That is recorded, not hidden.

- Phase 00 G-08-04 records owner acceptance of the engineering threat model
  and data inventory, not independent assurance and not statutory compliance.
- Independent re-review remains a Phase 22 obligation when a separate
  reviewer exists.
- ADR 0006 still forbids local clinical storage until G-06-01 is `PASS` on all
  five target platforms. Linux evidence is recorded; Windows, macOS, Android,
  and iOS have not run.
- Packaged Electron E2E (G-02-10) is still missing and is not waived.
