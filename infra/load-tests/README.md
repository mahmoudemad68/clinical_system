# Load tests

k6 scenarios (plan.md section 160). Phase 00 provides the harness and a
foundation smoke scenario; the per-workflow scenarios arrive with the phases
that build those workflows.

**Nothing here establishes a performance conclusion.** Capacity, SLO, and
saturation analysis belong to Phase 21 and to `clinic-observability-performance`.
Running these against a laptop proves the harness works, not that the platform
meets its targets.

## Scenarios

| File | Covers | Phase |
| --- | --- | --- |
| `scenarios/foundation-smoke.js` | health, readiness, version | 00 |
| *login, doctor search, booking, medicine search, prescription search, queue websocket, POS, medical record* | plan.md section 160 | 03–14 |

## Run

```bash
k6 run -e BASE_URL=http://localhost:8080 infra/load-tests/scenarios/foundation-smoke.js
```

## Thresholds

Thresholds encode the SLOs from `plan.md` section 132 so a regression fails the
run rather than being noticed in a graph later:

| Operation | p95 target |
| --- | --- |
| Normal API read | 250 ms |
| Normal API write | 400 ms |
| Appointment availability | 300 ms |
| Medicine text search | 300 ms |
| Medicine + geo search | 500 ms |
| Queue realtime event | 1 s |
| Start consultation | 300 ms |
| POS sale | 400 ms |
| RAG retrieval | 700 ms |

These are **acceptance targets to be tested**, not claims. `plan.md` section 133
is explicit that using Laravel does not guarantee them.

## Rules

- Synthetic data only. A load test never runs against real patient data, and
  staging may not clone raw production medical records (`plan.md` section 165).
- Never point a load test at production without the Phase 21 authorization.
- A failing threshold is a finding, not a reason to raise the threshold.
