# A sensitive-value canary reached the telemetry pipeline

**Alert:** `RedactionCanaryDetected` · **Severity:** critical · **Owner:** security

## What this means

The pre-export assertion found an unredacted sensitive value — a national ID,
token, phone number, or clinical text pattern — on its way out of the process.

**Treat this as a potential disclosure, not a bug report.** If the assertion
caught it at the boundary, similar values may already have reached the log store
through a path the assertion does not cover.

## User impact

None visible. That is what makes it dangerous.

## Confirm

1. Which signal fired: log, trace, or error report.
2. Which service and which version.
3. Which rule matched — the metric carries the rule name, not the value. **Do
   not go looking for the value itself.**

## Act

1. **Contain.** If the value class is `national_id`, `credential`, or clinical
   text, stop the export pipeline for the affected service before continuing.
   Losing telemetry for an hour is cheaper than continuing to write PHI to a
   log store with a different access model and retention period.

2. **Find the source, not the value.** Use the correlation ID and the rule name
   to identify the emitting code path. A redaction gap is nearly always one of:
   - a new field name that no key rule covers;
   - free text carrying a structured identifier, which is why value patterns
     exist alongside key rules;
   - a library logging its own request or response outside the application's
     redaction path;
   - an exception message quoting its input.

3. **Fix at the source and at the redactor.** Both. Stopping the specific field
   fixes today; adding the rule fixes the next field like it.

4. **Add a canary.** Every gap becomes a case in
   `apps/core-api/tests/Unit/Platform/RedactionCanaryTest.php`, asserting the
   specific rule fires. Assert the emitted hint, not merely that the value
   disappeared: a canary that passes because a *different* rule happened to
   catch it leaves the intended rule untested. That exact defect was found
   during Phase 00 and is recorded as defect 6 in the evidence ledger.

5. **Assess disclosure.** With the privacy owner: what reached the log store,
   who can read it, how long it is retained, and whether it requires
   notification. Engineering does not make that determination alone.

6. **Purge if required**, with the privacy owner's direction and a record of
   what was removed.

## Never

- Never disable redaction to reproduce the issue.
- Never paste the leaked value into a ticket, chat, or commit message. Doing so
  copies the disclosure into systems with even weaker controls.
- Never close this alert as "not reproducible". The assertion does not fire
  speculatively.
