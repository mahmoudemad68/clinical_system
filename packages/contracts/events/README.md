# Event contracts

Authoritative JSON Schemas for every event written to `outbox_events` and
delivered to a consumer. Required by Phase 00 §3.4.

## Layout

```text
envelope.schema.json                     the envelope every event uses
<module>/<event_name>.v<N>.schema.json   payload schema, one file per version
```

`<module>` is the owning module in snake_case, matching the `event_type`
namespace. `platform.diagnostics_round_trip_recorded` lives at
`platform/diagnostics_round_trip_recorded.v1.schema.json`.

Every payload version keeps its own file. A `v2` never overwrites `v1`, because
consumers must keep accepting the previous compatible version during a dual-read
migration.

## Compatibility rules

These mirror ADR 0003 and apply to the payload, not only the envelope.

**Compatible within a version**

- Adding an optional property.
- Widening an enum to accept more values, only when every existing consumer
  ignores unknown members.
- Relaxing a `maxLength` upward, or a `minimum` downward.
- Adding documentation.

**Breaking — requires a new version and a dual-read migration**

- Removing or renaming a property.
- Changing a property's type.
- Adding a required property.
- Narrowing an enum, a length, or a numeric bound.
- Changing the meaning of an existing property while keeping its name and type.
  This is the most dangerous kind, because no schema check detects it. It
  requires a new version anyway.

The last rule has teeth: if `status` stops meaning what it meant, that is a
breaking change even though every automated check passes.

## Migration procedure

1. Publish `v<N+1>` alongside `v<N>`.
2. Deploy consumers that accept both. Prove it with the dual-version consumer
   test before any producer emits `v<N+1>`.
3. Switch the producer to `v<N+1>`.
4. Wait out the retention and retry window for `v<N>`, including dead-letter
   replay. An event replayed from the dead-letter queue after the old schema was
   deleted is an outage.
5. Remove `v<N>` support in a later phase, once deprecation telemetry shows no
   consumer has seen it.

## Rules that are not negotiable

1. **Minimal payloads.** Events carry identifiers and the few non-sensitive
   facts a consumer genuinely needs. A consumer that needs more re-reads it from
   the owning module under its own authorization. Whole patient, prescription,
   lab, chat, or AI payloads never travel in an event.
2. **No credentials.** `classification` cannot be `credential`. A token, key, or
   password in an event payload is a defect, not a configuration choice.
3. **`event_id` is the consumer idempotency key.** Delivery is at least once.
   Every consumer must be idempotent on `event_id`, and reuse of an `event_id`
   for a different fact breaks that contract.
4. **Past tense.** `appointment.booked`, never `appointment.book` or
   `appointment.booking_requested`. An event states a fact that is already true
   and already committed.
5. **`occurred_at` is transaction time**, assigned inside the originating
   transaction. Publication time is a worker concern and is recorded separately
   on the outbox row.
6. **Unknown incompatible versions fail safely.** A consumer that receives a
   `schema_version` it does not support rejects the event to the dead-letter
   state with `UNSUPPORTED_SCHEMA_VERSION`. It never guesses, and it never
   acknowledges an event it did not process.

## Validation

`npm run contracts:events` validates that:

- every schema is a valid JSON Schema 2020-12 document;
- every filename matches the `event_type` and version inside it;
- every payload schema sets `additionalProperties: false`;
- no schema declares `classification: credential`;
- every schema carries the `x-clinic` block naming owning module,
  classification, consumers, and retention.

This runs in CI on every pull request.
