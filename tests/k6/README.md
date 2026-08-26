# Auth abuse harness (Phase 01)

Bounded k6 scenarios for credential stuffing, OTP flooding, and refresh reuse.
This is **not** a Phase 21 SLO test and must only target an isolated synthetic
environment.

```bash
k6 run tests/k6/auth-abuse.js
```

Thresholds fail the run if the API starts returning 5xx under the capped load.
Enumeration content is not asserted here; Pest covers identical error envelopes.
