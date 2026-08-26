# Data classification policy

Required by Phase 00 §5.1. Every field, event, log line, metric, cache entry,
and file in this platform carries exactly one classification. The classification
determines where the value may travel, how long it is kept, and who may read it.

The `Classification` enum in
`apps/core-api/app/Modules/Platform/Domain/ValueObjects/Classification.php`
is the executable form of this document. A rule stated here and not encoded
there is a rule that will be forgotten.

## Levels

| Level | Meaning | Examples |
| --- | --- | --- |
| `public` | Publishable without restriction | Medication catalogue, specialty list, clinic opening hours |
| `internal` | Operational, no personal dimension | Queue depth, outbox backlog, build version, feature flag state |
| `personal` | Identifies or relates to a person | Name, phone, address, appointment time, invoice, device identifier |
| `sensitive` | Clinical or otherwise sensitive personal data | Diagnoses, clinical notes, prescriptions, lab results, chat messages, national ID |
| `credential` | Secrets and keys | Passwords, tokens, API keys, encryption keys, HMAC keys |

There is no level above `credential` and none below `public`. A field that
seems to need one is misclassified.

## What each level permits

| Level | In telemetry? | As a metric label? | Cacheable? | In an event payload? |
| --- | --- | --- | --- | --- |
| `public` | yes | yes | yes | yes |
| `internal` | yes | yes | yes | yes |
| `personal` | identifier only | **never** | reviewed exception only | identifier only |
| `sensitive` | identifier only | **never** | reviewed exception only | identifier only |
| `credential` | **never** | **never** | **never** | **never** |

Three of these deserve their reasoning stated, because they are the ones people
push back on:

**Metric labels are never personal.** Label cardinality is unbounded, and metrics
are retained far longer and queried far more widely than log bodies. A
`patient_id` label does not merely leak an identifier; it creates one time series
per patient, which breaks the metrics backend and leaks simultaneously.

**Telemetry carries identifiers, not values.** A trace may say
`patient_id=0199a5c8-…`. It may not say `patient_name=…` or
`diagnosis=…`. An identifier is resolvable only by someone who already has
database access and a reason; a value is readable by anyone with log access.

**PHI is not cached by default.** ADR 0007. A reviewed exception must encrypt the
content, sharply bound TTL and access, and prove deletion and invalidation, and
it is recorded in the cache inventory rather than decided in a pull request.

## National IDs

A category of their own, because they are the highest-risk identifier in the
system and the rules are absolute (`docs/phases/README.md` invariant 5):

- encrypted at rest for recovery;
- stored additionally as a keyed HMAC for exact matching;
- the raw value never appears in a log, a trace, an analytics table, a cache, a
  URL, an event payload, a test fixture, or an AI prompt;
- the HMAC key is versioned, and rotation re-derives every index entry.

`PatternRedactor` carries a value pattern for the 14-digit Egyptian format
precisely because key-based redaction cannot catch one typed into a free-text
clinical note, which is how it actually escapes.

## Required inventory entry

Every new field, event, log line, metric, cache entry, file type, and AI payload
needs a row in [`data-inventory.md`](data-inventory.md) recording:

1. classification;
2. purpose — why the platform holds it at all;
3. lawful or business basis;
4. which roles may read it;
5. retention period and what happens at expiry;
6. encryption at rest and in transit;
7. the named owner of the deletion and anonymization decision.

A field with no inventory entry fails review. This is not bureaucracy: the
inventory is what makes a deletion request, a retention change, or a breach
assessment answerable in hours rather than weeks.

## Who decides

Engineering classifies a field's obvious level. Engineering does **not** decide:

- retention periods for clinical or financial records;
- what constitutes a lawful basis;
- whether an anonymization method is sufficient;
- whether regulated data may leave the country for AI processing.

Those require the accountable privacy, legal, and clinical owners named in the
Phase 00 entry criteria. The conservative default holds until they record a
decision: minimize, quarantine, and do not send.

## Status

**Owner-accepted engineering draft (G-08-04, 2026-08-26).** Named security and
privacy owner: Mahmoud. Assessor/remediator separation is lost. Nothing here
should be cited as a compliance position. Independent re-review remains Phase 22.
