# Database connections

**Alert:** `DatabaseConnectionsNearLimit` · **Severity:** warning · **Owner:** postgresql

> **Status: stub.** This runbook is referenced by an alert rule but has not been
> written. Writing it requires the operational experience the platform does not
> have yet — there is no staging environment and the alert has never fired.
>
> A stub is recorded here deliberately rather than leaving a dangling link: the
> gap is visible, and the alert cannot quietly reference a document that does
> not exist.
>
> Tracked as G-07-04 in `docs/evidence/phase-00/evidence-ledger.md`.

## Before this is usable it must state

1. What the alert means in terms of user-visible impact.
2. How to confirm the condition, with the exact query or command.
3. The likely causes, ordered by how often they actually occur.
4. What to do for each, diagnosis before action.
5. When to escalate, and to whom.
6. What not to do — the tempting action that makes it worse.

## Standing rules that apply regardless

- Use correlation IDs, never patient data, in tickets and incident channels.
- Never disable redaction to debug.
- Prefer understanding over restarting.
