# ADR 0003 — OpenAPI and event schemas are the contract source of truth

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, API contracts, backend, all client owners
- **Phase:** 00
- **Supersedes / Superseded by:** none

## Context

`plan.md` section 105 makes the OpenAPI specification the source of truth for
HTTP contracts and requires generated type-safe clients. Section 106 fixes the
response envelope, cursor pagination, and ISO-8601 UTC timestamps. Four clients
consume these contracts, and two of them (Flutter desktop) ship to machines that
may not update promptly, so a silent breaking change is a field incident rather
than a build failure.

## Decision

`packages/contracts/openapi/openapi.yaml` is authoritative for HTTP shape.
`packages/contracts/events/` holds JSON Schemas authoritative for event
envelopes and payloads. `packages/contracts/ai_internal/` holds the internal
Laravel-to-FastAPI contract.

The specification is written first and reviewed as the interface change. Server
code and client code both conform to it; neither generates it. CI validates the
document, lints it against a shared ruleset, detects breaking changes against
the `main` branch version, and regenerates the TypeScript and Dart clients.

Every response uses the envelope:

```json
{ "data": {}, "meta": {}, "errors": [], "request_id": "uuid-v7" }
```

Compatibility rules:

- Additive optional fields are compatible within a schema version.
- A removal, a type change, a narrowed enum, or a new required field is
  breaking; it requires a new version and a dual-read migration period.
- Removals require deprecation telemetry showing no live consumer before the
  removal ships, in a later phase and a later release.

## Consequences

### Positive

- The interface is reviewed as an artifact, not inferred from controllers.
- Client and server disagreement becomes a CI failure rather than a runtime 500.
- Generated clients remove an entire class of hand-written mapping bugs.

### Negative / accepted cost

- Writing the specification before the handler is slower for small changes.
- Generators impose their own idioms on client code; generated DTOs must stay at
  the network edge and map into client domain models rather than leaking through
  the UI (phase file, "Client architecture").

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| Specification drifts from implementation | Contract tests run generated clients against a running API in CI |
| A breaking change ships unnoticed | `oasdiff`-class breaking-change detection gates the pull request |
| Generated code is hand-edited | Generated directories are gitignored, marked `linguist-generated`, and regenerated in CI |
| Event schema evolves incompatibly | Consumers must accept current and previous compatible versions; a version-rejection test proves unknown incompatible versions fail safely |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Generate OpenAPI from Laravel annotations | Makes the server implementation authoritative; a breaking change becomes visible only after it is written, and clients cannot review the interface first |
| Hand-written clients per platform | Four hand-written mappings of the same contract; drift is certain and mapping bugs are invisible until runtime |
| GraphQL | Does not remove the compatibility problem, adds query-cost and authorization-per-field complexity to a system where deny-by-default object authorization is the central invariant |

## Migration and rollback impact

Forward: initial. New endpoints add paths; existing paths follow the
compatibility rules above.

Rollback: a released contract version is never withdrawn. A bad change is
superseded by a compatible follow-up.

## Verification

- OpenAPI validation and lint in CI.
- Breaking-change detection against `main`.
- Generated Dart and TypeScript clients compile and pass contract tests against
  a running API.
- Event consumers accept previous compatible versions and reject unknown
  incompatible versions safely.

## Review requirement

Engineering and API contracts. Any change touching authorization semantics also
requires security review.
