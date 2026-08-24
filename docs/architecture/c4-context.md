# C4 Level 1 — System context

Scope: the clinic platform as one system, its human actors, and the external
systems it depends on. Source: `plan.md` sections 1–5, 94, 100–101, 151;
`docs/phases/00_cross_cutting_architecture_and_delivery_contract.md`.

```mermaid
C4Context
    title Clinic platform — system context

    Person(patient, "Patient", "Books appointments, checks in, views own records, finds medicine")
    Person(doctor, "Doctor", "Runs consultations, writes clinical notes and prescriptions")
    Person(secretary, "Clinic secretary", "Manages check-in and queue. No clinical-record access")
    Person(pharmacyStaff, "Pharmacy staff", "Inventory, purchasing, POS, returns")
    Person(admin, "Platform admin", "Verification, medication catalog, health visibility. No PHI access")

    System(clinic, "Clinic platform", "Appointments, queue, clinical records, prescriptions, labs, pharmacy, and assistive AI for Egypt")

    System_Ext(fcm, "Firebase Cloud Messaging", "Push delivery to patient and clinician devices")
    System_Ext(sms, "SMS provider", "Registration OTP only")
    System_Ext(maps, "Google Maps", "Directions to a clinic location or pharmacy branch")
    System_Ext(llm, "LLM / embedding provider", "Text generation and embeddings behind owned ports")
    System_Ext(extPharmacy, "External pharmacy systems", "Read-only mirrored stock and product data")
    System_Ext(objectStore, "Managed object storage", "Private storage for medical files and originals")

    Rel(patient, clinic, "Uses", "HTTPS / JSON, Flutter mobile")
    Rel(doctor, clinic, "Uses", "HTTPS / JSON + WebSocket, Flutter desktop")
    Rel(secretary, clinic, "Uses", "HTTPS / JSON + WebSocket, Flutter desktop")
    Rel(pharmacyStaff, clinic, "Uses", "HTTPS / JSON, Flutter desktop")
    Rel(admin, clinic, "Uses", "HTTPS / JSON, React admin over session cookie")

    Rel(clinic, fcm, "Sends push notifications", "HTTPS, post-commit via outbox")
    Rel(clinic, sms, "Sends OTP", "HTTPS, provider port")
    Rel(clinic, llm, "Requests generation and embeddings", "HTTPS, AI service only")
    Rel(clinic, extPharmacy, "Pulls stock mirrors", "HTTPS, adapter, read-only")
    Rel(clinic, objectStore, "Stores and serves originals", "HTTPS, private, signed URLs")
    Rel(patient, maps, "Opens directions", "Deep link from the client")
```

## Boundary rules visible at this level

1. Clients never connect directly to PostgreSQL, Redis, object storage, Qdrant,
   or any provider API. Every external call originates server-side.
2. The SMS provider is used for registration OTP only (`plan.md` section 100).
   No clinical or appointment content leaves over SMS.
3. Maps integration is a client-side deep link. The platform does not build a
   navigation engine and does not send patient location to a map provider on the
   server's behalf (`plan.md` sections 150–151).
4. External pharmacy systems are read-only sources. The platform never mutates a
   partner's system (Phase 15 constraint, stated here because it is a boundary
   property).
5. The LLM provider is reachable only from the AI service, never from the core
   (ADR 0001).
6. Admin is an actor without clinical-record access (`docs/phases/README.md`
   invariant 7). This is a system-context property, not only an internal one.

## Trust boundaries

| Boundary | Crossing | Controls |
| --- | --- | --- |
| Public internet → gateway | All client traffic | TLS, request-size limits, coarse abuse controls |
| Gateway → core | Authenticated requests | Session/device token, correlation ID, deny-by-default policy |
| Core → data stores | Private network only | Per-workload credentials, no public exposure |
| Core → AI service | Internal contract | Authenticated, versioned, deadline-bound, minimal references |
| AI service → provider | Egress | Provider port, no raw PHI, budget and timeout controls |
| Admin browser → core | Session and CSRF | HTTP-only cookie, CSRF token, secure headers, no token in local storage |
