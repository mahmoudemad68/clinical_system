---
name: clinic-realtime-jobs-delivery
description: Implement Laravel Reverb, Horizon, Redis/outbox consumers, notifications, chat delivery, schedulers, retries, and provider adapters for clinic workflows. Use for post-commit asynchronous/realtime delivery, not Python AI workers or authoritative domain transitions.
---

# Clinic Realtime Jobs Delivery

Deliver committed clinic events reliably without turning realtime, queues, caches, or providers into business truth. Design for at-least-once execution, reconnects, provider uncertainty, and operator-visible recovery.

## Ownership and routing

This skill owns Laravel-side transactional-outbox consumers, Horizon queues, Redis queue coordination, Reverb private channels, notification intents and attempts, push/SMS adapters, schedules, retry/dead-letter/repair behavior, and delivery observability.

The originating domain owns its state transition and event meaning. PostgreSQL owns durable claim/idempotency constraints. Chat owns thread/message truth in Laravel; Reverb only signals that authorized clients should fetch current data. Analytics and search projections own their derived records. Python AI workers and their queue are explicitly outside this skill.

Never make an appointment, access grant, encounter, prescription, stock balance, invoice, payment, refund, or external mirror correct by hoping a later job runs.

## Required phase sources

Always read [Phase 00](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md). Then read the phase that originates and consumes the event/job.

Primary sources are [Phase 04](../../../docs/phases/04_realtime_queue_and_consultation_control.md) and [Phase 09](../../../docs/phases/09_notifications_and_post_visit_chat.md). Conditional sources include:

- [Phase 03](../../../docs/phases/03_scheduling_availability_and_booking.md), [Phase 06](../../../docs/phases/06_prescriptions_reminders_and_printing.md), and [Phase 07](../../../docs/phases/07_labs_files_reports_and_referrals.md) for reminders and user notifications;
- [Phase 11](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [Phase 12](../../../docs/phases/12_purchasing_and_goods_receipt.md), and [Phase 13](../../../docs/phases/13_pos_invoices_returns_and_refunds.md) for post-commit pharmacy effects;
- [Phase 15](../../../docs/phases/15_external_pharmacy_integrations.md) for the Laravel integrations lane and freshness jobs;
- [Phase 16](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md) for the hard PHP/Python queue boundary;
- [Phase 20](../../../docs/phases/20_admin_analytics_and_system_health.md), [Phase 21](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [Phase 22](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [Phase 23](../../../docs/phases/23_disaster_recovery_release_and_production.md) for projections, capacity, assurance, replay, and recovery.

## Queue and event boundaries

- Domain changes and outbox rows commit atomically in PostgreSQL. A dispatcher/consumer begins only after commit; never broadcast or call a provider from an open business transaction.
- Laravel Horizon owns only Laravel/PHP job classes. Use named lanes such as `critical`, `notifications`, `files`, `integrations`, `analytics`, `reports`, `backups`, and Laravel-side AI orchestration according to Phase 00.
- Python receives an authenticated, versioned JSON command over the approved internal API/protocol and enqueues it through an AI-owned typed `TaskQueue`. It uses a separate namespace/instance, worker library, schema, retry budget, and dead-letter path. Neither runtime deserializes or acknowledges the other's payload.
- Event payloads contain stable IDs, schema version, minimal safe facts, correlation/causation IDs, and no clinical document, chat body, lab result, national ID, credential, raw integration row, or AI prompt/output.
- A job contains stable IDs, schema version, deadline, idempotency key, and safe configuration version. It reloads state and rechecks eligibility. Do not serialize Eloquent models or provider SDK objects.
- Consumers are idempotent by event/job/effect identity. “Exactly once” is an observable effect invariant backed by uniqueness/reconciliation, not a transport guarantee.

## Delivery and realtime invariants

- Claim outbox/delivery rows with bounded leases or `FOR UPDATE SKIP LOCKED`; store attempt count, next attempt, safe error class, processed/dead-letter time, and repair provenance.
- Retry only typed transient failures with capped exponential backoff plus jitter and an operation deadline. Permanent validation/auth/token failures stop. Unknown provider outcomes reconcile using the same provider/idempotency reference before another send.
- Reverb channels are private/presence only when required and authorized server-side for the exact actor/resource. Channel names, event payloads, and metrics must not disclose protected facts.
- Realtime events are invalidation/state hints. On subscribe, reconnect, gap, or version mismatch, clients fetch an authorized REST snapshot/cursor and discard stale events.
- Post-visit chat is writable only for the exact encounter patient and doctor until the server-authored 48-hour database deadline. A late expiry job cannot extend access. Messages are append-only, encrypted at rest, ordered by server ordinal, and event payloads omit the body.
- Push contains a notification ID, safe type, and opaque resource reference only. Lock-screen text remains generic. SMS is technically restricted to IAM OTP in V1.
- Invalid FCM tokens become inactive/permanent failure; provider success or failure never changes authoritative clinical or pharmacy state.
- Redis/Reverb/FCM/SMS/Horizon outage may delay delivery but cannot lose committed intent. REST recovery and outbox replay remain available.

## Implementation workflow

1. Read the originating and consuming phase contracts. Identify the authoritative committed record, event schema, intended effect identity, deadline, classification, and acceptable delay.
2. Confirm the producer writes the outbox in its existing business transaction. If the originating invariant depends on the consumer succeeding, route the work back to an application coordinator instead of adding a job.
3. Implement a small consumer that loads current state, claims an idempotent effect, invokes one narrow adapter, normalizes the result, and records success/retry/permanent/dead-letter status.
4. Define adapter timeouts, retryable status set, maximum attempts/deadline, jitter, concurrency, rate limit, cancellation, circuit/bulkhead behavior where supported, and operator repair procedure.
5. For Reverb, implement channel authorization, sanitized/versioned payload, reconnect snapshot, ordering/version handling, and backpressure limits together.
6. Instrument bounded queue depth/age, oldest outbox age, attempts, dead letters, provider latency/error class, connection count, reconnects, and event latency. IDs, bodies, phone numbers, tokens, coordinates, and raw errors are prohibited labels.
7. Roll out provider/notification/realtime features disabled, prove synthetic delivery and canary redaction, then enable by bounded cohort. Rollback stops new dispatch while retaining durable intent and readable Core state.

## Observable verification

Provide evidence for the affected lane and adapter:

- a rolled-back business transaction emits/broadcasts nothing, while a committed outbox row is eventually processed;
- duplicate events, jobs, provider callbacks, and client retries create one effect; same identity with conflicting payload fails safely;
- kill a worker after provider call/before acknowledgement, expire its lease, and prove recovery reconciles rather than duplicates;
- exhaust transient retries and observe a redacted dead-letter plus bounded operator replay; permanent failures do not loop;
- stop Redis, Horizon, Reverb, and each provider independently; Core truth remains correct and REST/restart recovery succeeds;
- unauthorized, wrong-tenant, revoked-device, expired-chat, and forged-channel subscriptions receive no event or content;
- reconnect storms, fan-out, queue backlog, and provider rate limiting meet the active Phase 21 targets on production-shaped synthetic load;
- canary clinical/chat/token/phone content appears in none of Redis payload inspection, logs, traces, metrics, Sentry, push payloads, or event schemas;
- a contract test proves Python rejects Laravel job payloads and Horizon cannot consume AI commands.

Report event/job versions, queues, effect-idempotency key, retry/dead-letter policy, provider/reconnect behavior, load/failure tests, and remaining operational risks.
