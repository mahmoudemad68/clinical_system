# Outbox backlog is growing

**Alerts:** `OutboxBacklogGrowing`, `OutboxOldestEventStale` · **Severity:** warning · **Owner:** realtime-jobs

## What this means

Committed changes are waiting for their side effects. **No data is lost** — the
outbox exists precisely so these survive — but notifications, realtime updates,
and analytics are delayed.

Two alerts, deliberately, because they catch different failures:

- **Depth** (`> 1000`) catches "workers cannot keep up".
- **Age** (`> 300s`) catches "one poisoned row is starving behind a repeatedly
  failing consumer". Depth can look healthy while the oldest event is hours old.

Age is the more important of the two.

## Confirm

```sql
SELECT status, count(*),
       min(available_at) AS oldest_due,
       max(attempts)     AS worst_attempts
FROM outbox_events
WHERE status IN ('PENDING', 'FAILED', 'CLAIMED')
GROUP BY status;

-- What is actually failing?
SELECT event_type, last_error_class, count(*)
FROM outbox_events
WHERE status = 'FAILED'
GROUP BY event_type, last_error_class
ORDER BY count(*) DESC
LIMIT 10;
```

## Act

**Many rows `CLAIMED` with an expired lease** — workers died. Another worker
recovers them automatically on its next pass; confirm the pass is running:

```sql
SELECT count(*) FROM outbox_events
WHERE status = 'CLAIMED' AND lease_expires_at < now();
```

If that number is not falling, no worker is running. Check the worker
deployment.

**Many rows `FAILED` with the same `last_error_class`** — a downstream
dependency is down. The backoff is capped and jittered, so the queue will drain
on its own once the dependency recovers. Fix the dependency; do not clear the
backlog.

**Rows `PENDING` and due, but nothing is claiming them** — worker capacity.
Confirm workers are running and scale out. `SKIP LOCKED` means additional
workers take disjoint sets without coordination, so scaling is safe.

**Depth healthy but age climbing** — one event type is failing repeatedly.
Identify it from the query above and treat it as its own problem; do not scale
workers, which will not help.

## Do not

- Do not delete pending rows to clear the alert. Each one is a committed change
  whose effect has not happened.
- Do not raise `max_attempts` to stop rows dead-lettering. Dead-lettering is the
  system telling you something needs a human.
