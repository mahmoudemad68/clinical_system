# Outbox events have exhausted their retries

**Alert:** `OutboxDeadLetterPresent` · **Severity:** critical · **Owner:** realtime-jobs

## What this means

A change was committed to the database, and the side effect it promised never
happened. Not "is delayed" — did not happen, and will not without intervention.

Depending on the event type this can mean a patient was not told their
appointment moved, a prescription notification never went out, or an analytics
projection is permanently missing a record.

## User impact

Silent. The originating action appeared to succeed to whoever performed it, and
nothing in the product indicates the follow-up did not.

## Confirm

```sql
SELECT event_type, last_error_class, count(*), min(created_at) AS oldest
FROM outbox_events
WHERE status = 'DEAD_LETTER'
GROUP BY event_type, last_error_class
ORDER BY count(*) DESC;
```

`last_error_class` is a stable label, never a provider message. Common values:

| Label | Meaning |
| --- | --- |
| `no_consumer_registered` | Deployment mistake: the event type has no handler |
| `unsupported_schema_version` | A producer emitted a version consumers do not accept — usually a dual-read migration done in the wrong order |
| `malformed_payload` | Payload does not match its schema |
| anything else | The consumer threw repeatedly |

## Act

**Do not prune. Do not clear the alert by deleting rows.** These rows are the
only record that the effect was lost.

1. **Identify the blast radius.** How many events, which types, over what
   window. Correlate with a deploy.

2. **`no_consumer_registered`:** a consumer was removed or never registered.
   Restore the registration, deploy, then replay (step 4).

3. **`unsupported_schema_version`:** the producer moved ahead of the consumers.
   The migration procedure is publish `v(N+1)`, deploy consumers that accept
   both, *then* switch the producer. Deploy the consumer support, then replay.

4. **Replay explicitly**, never in bulk without looking:

   ```sql
   -- Inspect first.
   SELECT event_id, event_type, occurred_at, attempts, last_error_class
   FROM outbox_events
   WHERE status = 'DEAD_LETTER' AND event_type = '<type>'
   ORDER BY occurred_at;

   -- Return a reviewed set to the pool.
   UPDATE outbox_events
   SET status = 'PENDING', attempts = 0, available_at = now(),
       last_error_class = NULL
   WHERE event_id IN ('<explicit>', '<list>');
   ```

   Consumers are idempotent on `event_id`, so a replay of an event that
   partially applied will not double its effect. That guarantee is what makes
   replay safe; if you are replaying an event whose consumer you are not certain
   is idempotent, verify before replaying, not after.

5. **If the effect can no longer be delivered usefully** — a queue-position
   notification for an appointment that finished yesterday — record the decision
   to drop it, with the event IDs, and then update the rows. Do not delete them.

## Escalate

If dead letters involve prescriptions, lab results, or anything a clinician may
have relied on, escalate to the clinical owner immediately. A missed clinical
notification is a patient-safety question, not an operations question.
