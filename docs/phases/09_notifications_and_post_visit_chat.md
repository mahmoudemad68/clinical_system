# Phase 09 — Notifications and Post-Visit Chat

## Objective

Deliver durable, privacy-safe notification delivery and encounter-scoped text chat. A completed consultation opens exactly one doctor-patient chat window for 48 hours; after the deadline the thread becomes read-only while its history remains available to its authorized participants.

The phase makes push and realtime delivery observable and retryable without making either transport authoritative. PostgreSQL stores notification intent, delivery attempts, chat messages, and the write-window deadline. Reverb, Firebase Cloud Messaging, and the SMS provider are replaceable delivery adapters.

## Plan traceability

- Section 47, lines 1569-1604: doctor-patient text chat opens after a completed consultation, remains writable for 48 hours, then becomes read-only without deleting history.
- Sections 99-101, lines 2879-2947: private realtime channels, FCM notification types, SMS restricted to registration OTP, and recorded delivery states.
- Sections 102-104, lines 2949-3027: separated queue lanes, Horizon monitoring, transactional outbox, and reliable post-commit fan-out.
- Sections 107 and 113-115, lines 3081-3107 and 3275-3320: idempotency, Redis limitations, and avoidance of PHI caches.
- Sections 117, 119-122, lines 3346-3467: network isolation, rate limits, append-only audit, and sensitive-log prohibition.
- Sections 141-143, lines 3857-3915: core availability isolation, health checks, and delivery/realtime monitoring.
- Sections 152 and 156-157, lines 4085-4110 and 4182-4239: safe client retry behavior, test layers, and authorization-denial tests.
- Sections 171-174, lines 4503-4622: V1 exclusions, data ownership, consistency, and asynchronous-work rules.

## Entry criteria and dependencies

- Phase 01 provides authenticated users, device sessions, device-token revocation, OTP policy, and actor resolution.
- Phase 03 provides appointment identity and patient/doctor relationships.
- Phases 04-05 provide the atomic `CompleteConsultationService` and authoritative encounter status.
- Phases 06-07 emit prescription and lab events without embedding clinical content in event payloads.
- Phase 00 provides the outbox, Horizon lanes, Reverb authorization, OpenAPI, audit, telemetry, and idempotency contracts.
- Product/security approve notification wording that does not expose medical facts on a locked screen.

## Non-goals

- No emergency specialist chat, group chat, attachments, voice/video, typing history, message editing, deletion, or unsend.
- No chat before a completed consultation and no configurable extension beyond the fixed V1 48-hour window.
- No SMS for reminders, marketing, chat, prescriptions, labs, or delay notifications; SMS remains registration OTP only.
- No claim of guaranteed FCM delivery. Provider acceptance is distinct from device receipt.
- No Windows/Linux FCM dependency for Electron doctor or pharmacy desktops; those clients use authorized main-process realtime and privacy-safe in-app/local presentation.
- No clinical advice automation or AI participation in chat.

## Laravel module ownership and services

### Ownership

    Clinical/Visit workflow
      owns encounter completion and participant identity

    Chat module
      owns threads, participants, messages, the 48-hour deadline,
      read-only transitions, message authorization, and chat audit

    Notifications module
      owns notification intent, channel selection, templates,
      delivery attempts, retry policy, and provider result normalization

    IAM module
      owns device sessions and push-token lifecycle

    External integrations
      Reverb, FCM, SMS provider, clock, and queue implementations

The `CompleteConsultationService` calls `ChatService` inside the same PostgreSQL transaction that completes the encounter. It creates or confirms the one chat thread and writes the outbox event. Push, realtime broadcast, and analytics happen only after commit.

### Module services and external integrations

    ChatService
      ensureForCompletedEncounter(encounter_id, patient_id, doctor_id, opened_at)

    ChatAccessPolicy
      mayRead(actor_id, thread_id)
      maySend(actor_id, thread_id, now)

    NotificationService
    DeliveryAttemptService

    PushSender
      send(device_token_reference, template_payload, deadline)

    SmsOtpSender
      sendOtp(phone_reference, message, deadline)

    RealtimePublisher
      publishPrivate(channel, event_reference)

    Clock

- Chat does not import Encounter Eloquent models; `CompleteConsultationService` supplies verified IDs and time.
- Notifications consume versioned events and never write prescription, lab, appointment, queue, or chat state.
- Provider SDK errors are translated to stable transient, permanent-token, rejected, timeout, and unavailable categories.
- Templates receive approved scalar fields only. They cannot query arbitrary tables or render raw clinical text.

## Packages and runtime components

Versions are selected and locked under Phase 00 policy.

### Laravel/PHP

- Laravel Notifications for channel-neutral notification construction.
- Laravel Horizon and Redis for the notifications lane and failed-job visibility.
- Laravel Reverb for private chat and per-user event channels.
- laravel-notification-channels/fcm, or an ADR-approved maintained FCM adapter, behind PushSender.
- The selected Egyptian SMS SDK/API behind `SmsOtpSender`; provider types never escape the integration class.
- PostgreSQL, Laravel encryption/KMS adapter, Prometheus, Laravel Telescope (local), and Sentry with content capture disabled.
- deptrac/deptrac, Larastan/PHPStan, and Pest/PHPUnit for boundary and behavior enforcement.

### Flutter patient mobile

- firebase_messaging for patient Android/iOS push.
- flutter_local_notifications for foreground/local presentation where approved.
- The Phase 00 Reverb/Pusher-protocol adapter for private realtime chat.
- Riverpod, Dio, Freezed/JSON serialization, secure storage, and localization packages.
- If a minimal encrypted thread cache is approved, use Drift over sqlite3 v3 native hooks configured for an approved SQLCipher or SQLite3MultipleCiphers build; verify encryption, packaging, migrations, and key handling on every supported Android/iOS target.

### Electron doctor desktop

- React, TypeScript, TanStack Query, Zod, MUI, i18next, the generated TypeScript client, Vitest, React Testing Library, MSW, WebdriverIO with `@wdio/electron-service` for packaged-app E2E, and axe-core.
- Electron main owns device credentials, authenticated REST and Reverb connections, channel construction, reconnect/backoff/sequence tracking, and generic OS notification presentation. Preload exposes typed recipient-safe events, refetch signals, and bounded notification actions only.
- If a bounded doctor thread cache is approved, it uses the Phase 05 reviewed main-owned utility-process encrypted SQLite adapter and key lifecycle in a purpose/retention-scoped store. Main authorizes its typed cache capability; the renderer never receives a database or utility-process capability. No chat body, key, token, database/path primitive, or generic event channel reaches the renderer.

Do not store FCM server credentials, Reverb secrets, or SMS credentials in clients. A device token is treated as sensitive metadata and never appears in logs. Electron desktop device credentials remain in main-process secure storage and never cross preload or enter renderer/Web Storage.

## Persistent schemas, invariants, and indexes

### PostgreSQL

    chat_threads
      id UUIDv7 primary key
      encounter_id UUID not null unique
      patient_profile_id UUID not null
      doctor_profile_id UUID not null
      status enum WRITABLE | READ_ONLY
      opened_at timestamptz not null
      writable_until timestamptz not null
      became_read_only_at timestamptz nullable
      created_at / updated_at timestamptz
      version bigint not null

    chat_messages
      id UUIDv7 primary key
      thread_id UUID not null
      sender_profile_type enum PATIENT | DOCTOR
      sender_profile_id UUID not null
      ordinal bigint not null
      body_ciphertext text not null
      body_key_version string not null
      body_hash bytea not null
      created_at timestamptz not null
      client_message_id UUID nullable

    notifications
      id UUIDv7 primary key
      recipient_user_id UUID not null
      type string not null
      template_version string not null
      resource_type / resource_id nullable
      urgency enum NORMAL | HIGH
      created_at timestamptz

    notification_deliveries
      id UUIDv7 primary key
      notification_id UUID not null
      channel enum PUSH | IN_APP | SMS_OTP
      destination_reference UUID nullable
      status enum CREATED | QUEUED | SENT | DELIVERED | FAILED_PERMANENT |
                  FAILED_RETRYABLE | SUPPRESSED
      provider_message_reference string nullable
      attempt_count integer
      next_attempt_at / sent_at / delivered_at / failed_at timestamptz nullable
      safe_error_code string nullable
      idempotency_key string not null

    user_push_tokens
      id UUIDv7 primary key
      user_device_id UUID not null
      platform enum ANDROID | IOS | MACOS | WEB
      token_ciphertext text not null
      token_hash bytea not null
      status enum ACTIVE | INVALID | REVOKED
      last_registered_at / last_success_at / revoked_at timestamptz nullable

Indexes and constraints:

- Unique chat_threads(encounter_id) prevents duplicate post-visit threads.
- Unique chat_messages(thread_id, ordinal) preserves deterministic ordering.
- Unique chat_messages(thread_id, sender_profile_id, client_message_id) where client_message_id is not null prevents retry duplicates.
- chat_threads(patient_profile_id, opened_at desc) and chat_threads(doctor_profile_id, opened_at desc).
- notification_deliveries(status, next_attempt_at) for worker claims.
- Unique notification_deliveries(idempotency_key, channel, destination_reference).
- Unique user_push_tokens(token_hash); index active tokens by user_device_id.

### Hard invariants

1. A thread exists only for a completed encounter and exactly the encounter patient and doctor.
2. writable_until equals the server completion time plus 48 hours; clients cannot choose it.
3. The send policy compares the authoritative database deadline with an injected server clock. A delayed read-only job is not required for correctness.
4. After the deadline or explicit read-only transition, all sends fail; history remains readable to the two participants.
5. Messages are append-only. No edit/delete endpoint exists.
6. Push payloads contain a notification ID, safe type, and opaque resource reference only—never diagnosis, prescription text, lab result, national ID, or chat body.
7. SMS_OTP can be created only by the IAM OTP use case.
8. Provider success never changes authoritative clinical/chat state.

## Detailed success, failure, concurrency, and data flows

### Complete consultation and open chat

1. CompleteConsultationService locks the encounter/appointment and validates the doctor and current state.
2. It completes the encounter, revokes full-history access, advances the queue, and calls OpenPostVisitThread.
3. Chat calculates writable_until from the injected completion timestamp and inserts the unique thread.
4. The transaction inserts encounter-completed, chat-opened, and notification outbox records with minimal payloads.
5. Commit makes the thread visible.
6. Consumers create patient/doctor in-app intents and publish an opaque chat-opened realtime event.
7. Duplicate service invocation or outbox delivery resolves through encounter uniqueness and event idempotency.

### Send message

1. Client submits thread ID, body, client_message_id, record version, and Idempotency-Key.
2. Laravel authenticates the device and loads participant/thread state server-side.
3. Policy verifies exact participant membership and now less than writable_until.
4. Validate normalized Unicode text, non-empty length, maximum bytes/code points, and prohibited control characters.
5. In one transaction, lock the thread, recheck deadline/version, allocate the next ordinal, encrypt/insert the message, audit metadata, and add a chat-message-created outbox event.
6. Commit returns the canonical message ID/time.
7. Reverb publishes message ID/thread ID only; authorized clients fetch the encrypted-at-rest body through the API.

### Delivery failure and retry

- Invalid/unregistered FCM token: mark the token INVALID and the attempt permanently failed; do not retry it.
- Provider timeout/eligible 429/5xx: mark retryable and schedule capped exponential backoff plus jitter within the notification deadline.
- Permanent provider rejection or malformed destination: fail permanently with a safe error code.
- Duplicate provider request after unknown timeout: reuse the delivery idempotency/provider key and reconcile status rather than create another intent.
- Reverb unavailable: the outbox remains/retries; clients recover through REST pagination.
- Redis/Horizon unavailable: committed notification/chat truth remains in PostgreSQL and resumes later.

### Thread expiry

A bounded periodic job may transition expired WRITABLE rows to READ_ONLY using compare-and-set for UX and metrics. Authorization still checks writable_until, so a late or failed job cannot extend chat. The job emits no per-message content and is replay-safe.

## API, event, and job contracts

### Public API

    GET  /api/v1/chat/threads
    GET  /api/v1/chat/threads/{thread_id}
    GET  /api/v1/chat/threads/{thread_id}/messages?cursor=...
    POST /api/v1/chat/threads/{thread_id}/messages

    POST   /api/v1/devices/{device_id}/push-tokens
    DELETE /api/v1/devices/{device_id}/push-tokens/{token_id}
    GET    /api/v1/notifications?cursor=...
    POST   /api/v1/notifications/{notification_id}/read

Stable errors include CHAT_NOT_PARTICIPANT, CHAT_READ_ONLY, CHAT_WINDOW_EXPIRED, CHAT_MESSAGE_INVALID, CHAT_VERSION_CONFLICT, DEVICE_REVOKED, and PUSH_TOKEN_INVALID.

### Events

- chat.post_visit_thread_opened.v1: thread, encounter, participant user references, opened/writable timestamps.
- chat.message_created.v1: thread ID, message ID, sender profile reference, ordinal, created_at; no body.
- chat.thread_became_read_only.v1: thread ID and effective timestamp.
- notification.intent_created.v1 and notification.delivery_state_changed.v1: IDs, channel, safe type/status/error only.
- Prescription, lab, appointment, delay, queue, follow-up, and correction events create notification intents through an explicit type-to-template policy.

### Jobs

- DispatchNotificationDelivery, ReconcileUnknownDelivery, ExpireChatThreads, and PurgeRevokedPushTokens.
- Jobs carry IDs/schema versions, reload records, claim with leases/row locks, honor deadlines, and are idempotent.
- Horizon owns these Laravel jobs. No Python queue is involved.

## Client work

### Patient Flutter

- Register/rotate push tokens only after an authenticated device session; revoke on logout/device removal.
- Notification taps route using type plus opaque resource ID and always refetch/reauthorize.
- Chat list/thread UI shows the exact server deadline and read-only state, handles reconnect/pagination, optimistic pending messages keyed by client_message_id, and reconciles canonical outcomes.
- Store only a bounded encrypted cache. Clear it on logout, revocation, retention expiry, or account unlink.
- Arabic/English strings, RTL layout, screen-reader labels, offline indicators, and keyboard-safe composition are required.

### Doctor Electron desktop

- Subscribe to authorized doctor/thread channels, but treat REST as recovery/source for message content.
- Show read-only state and deadline; do not allow a local clock to authorize sending.
- Do not depend on FCM for Windows/Linux. Electron main uses authorized realtime and a generic privacy-approved local-notification adapter for the target OS.
- The sandboxed React renderer cannot choose channel names/participants, read credentials, open arbitrary URLs, or invoke generic IPC. A sequence gap, renderer reload, or reconnect triggers an authoritative REST refetch.

### React browser admin

- No chat-content viewer. Operations may see aggregate delivery health and opaque failed delivery IDs only under an audited support capability.

## Security and privacy controls

- Authorize thread list, read, pagination, send, and every private channel subscription.
- Encrypt chat bodies and push tokens with key versions; separate key access from database access.
- Use generic lock-screen push text and require app authentication before displaying content.
- Rate-limit message sends by actor/device/thread and bound body, pagination, and connection sizes.
- Sanitize rendered text; V1 is plain text, not HTML/Markdown or executable links.
- Block resource enumeration by returning safe 404/403 behavior and opaque UUIDs.
- Audit thread open/read-only transitions, participant reads where policy requires, sends, denials, token changes, and provider failures without recording bodies or tokens.
- Keep chat/notification content out of logs, traces, metrics, events, crash reports, analytics, and support dashboards.
- Enforce TLS, Reverb private-channel authorization, secret-manager provider credentials, webhook signature verification if delivery receipts are used, replay windows, and provider egress allowlists.
- Electron uses a packaged local renderer, restrictive CSP, context isolation, renderer sandbox, no Node integration, sender-validated typed IPC, navigation/new-window/permission denial, safe external-link allowlists, and no remote content/webview. Renderer compromise cannot subscribe to an arbitrary channel or reach OS notification/storage/network credentials.
- Define configurable chat/message/delivery retention and legal-hold behavior with privacy and security owners before production; record conservative assumptions and continue without legal sign-off.

## Test plan

### Unit and property tests

- Exact 48-hour boundary with controlled UTC clock, including one microsecond before/at/after expiry.
- Participant/read/send policy, state transitions, template allowlist, channel selection, retry classification, backoff cap, token invalidation, and safe payload generation.
- Unicode/Arabic property tests prove body size normalization and push payloads never contain classified canaries.

### Integration tests

- Real PostgreSQL proves one thread per encounter, concurrent ordinal allocation, duplicate client_message handling, deadline recheck under lock, encryption metadata, and outbox atomicity.
- Redis/Horizon tests cover retry, dead-letter, duplicate delivery, queue restart, and no lost committed intent.
- Reverb private-channel tests prove unauthorized subscription denial and reconnect recovery.
- FCM/SMS adapters run against fakes/sandboxes with timeout, 429, invalid token, unknown outcome, and signed receipt cases.
- WebdriverIO with `@wdio/electron-service` packaged Electron tests cover main-process REST/Reverb recovery, renderer reload, reconnect gap, logout/revoke disconnect, generic local notifications, bounded encrypted-cache lifecycle, and preload rejection of forged sender/schema/channel operations on each supported OS.

### Contract tests

- OpenAPI-generated Dart patient-mobile and TypeScript Electron clients cover cursor pagination and stable errors.
- Electron main/preload contracts reject arbitrary channels/participants/URLs/headers, forged senders, unknown event fields, oversized payloads, stale sequence, and responses containing credentials, paths, keys, or server secrets.
- Every PushSender/SmsOtpSender adapter passes the same deadline, idempotency, typed-error, and redaction contract.
- Event schema compatibility and replay tests prove consumers do not require chat/clinical content.

### End-to-end tests

- Completing a consultation creates one 48-hour thread; patient and doctor exchange text, receive opaque events, and history remains after read-only.
- Secretary, admin, another doctor, another patient, revoked device, and unauthenticated user cannot list/read/send/subscribe.
- Prescription correction and queue-delay events create correct generic patient notifications without clinical text.
- FCM/Reverb outage leaves messages visible through REST and does not affect encounter completion.
- Doctor Electron restart, renderer crash/reload, notification click, and network flap recover canonical history without duplicate sends, stale writable authorization, or cross-thread content.

### System, performance, and security tests

- Soak concurrent private connections/message fan-out within Phase 21 targets; measure event latency, reconnect storms, queue age, and PostgreSQL contention.
- Inject Redis, Reverb, FCM, SMS, and worker failure/recovery and prove no duplicate thread/message/intent.
- Test BOLA/BFLA, forged channel names, cursor tampering, replay, message flood, oversized/Unicode payloads, stored-XSS strings, token theft/revocation, webhook forgery, and sensitive-log canaries.
- Attempt stored-XSS-to-IPC/Node escalation, forged IPC sender/event/channel, token/cache/path extraction, unsafe navigation/external link, notification spoofing, and reconnect amplification in Electron; all fail closed.
- Restore PostgreSQL and replay outbox/deliveries without duplicate user-visible messages.

## Observability, migration, and rollout

Metrics include chat opens/sends/denials/expiry, active realtime connections, event latency, notification intents and outcomes by bounded type/channel/error, queue depth/age/retry/dead-letter, invalid-token rate, and provider latency. IDs, body text, phone numbers, tokens, and medical facts are prohibited labels.

Rollout:

1. Expand schemas and deploy read-disabled modules/adapters.
2. Enable device-token registration and synthetic delivery tests.
3. Enable in-app notifications, then patient push for a small cohort.
4. Enable post-visit chat for internal/synthetic encounters, then an allowlisted clinic cohort.
5. Monitor authorization denials, queue age, provider failures, message latency, and sensitive-data canaries.
6. Rollback disables new sends/opening while retaining readable history and queued/auditable state; schema contraction waits for retention completion.

Migration scripts are backward-compatible, resumable, and avoid generating chat threads for historical encounters unless an explicit audited backfill is approved.

## Acceptance and exit gate

- A completed encounter creates exactly one correctly scoped 48-hour thread in the same strong-consistency workflow.
- At/after writable_until every send is denied even if the expiry worker or client clock is wrong.
- Authorized participants can recover complete ordered history; all other roles and tenants receive zero content.
- Push/SMS/realtime provider failures retry or terminate by typed policy without affecting core workflows or duplicating messages/intents.
- SMS is technically restricted to OTP and all medical/chat push payloads are generic.
- Unit, property, integration, contract, E2E, system, load, restore, and security suites pass with no critical/high finding.
- Retention, encryption/key rotation, dashboards, alerts, runbooks, migrations, generated clients, accessibility/localization, and privacy/security approval are evidenced.
- No emergency chat, attachments, message mutation, or other V1-excluded feature is enabled.
