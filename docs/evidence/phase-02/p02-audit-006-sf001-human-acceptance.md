# P02-AUDIT-006 / SF-001 — Human Independent Reviewer / Project Owner acceptance

Controller-supplied **human** governance decision. Evidence/governance record
only. **Not** merge. **Not** Ready for Review. **Not** G-08-04. **Not**
P02-AUDIT-007. **Not** a claim that the repository has zero High findings.

No reviewer name, title, organization, timestamp, signature, Security-team
membership, Privacy-team membership, or credential was supplied beyond the
fields below. None is invented here.

## Freshness at recording

PR **#36** head before this evidence commit:

- reviewed candidate SHA: `ccc95cddca6691781485c47ba4b5d21d872bca09`
- reviewed tree: `6535523e5e7fdd88625f7bb78641a2d83c5daffd`
- base `main`: `03ca1d64685647d31f7358cbc0865f040235d0d7`
- PR state: Draft, not merged

This file is recorded in a later evidence-only commit. That later HEAD is
**not** the reviewed candidate. ISR-015 binds
`reviewed_candidate_sha` / `reviewed_tree` to the values above.

## Human decision (as supplied)

| Field | Value |
| --- | --- |
| Reviewer type | Human Independent Reviewer / Project Owner |
| Decision | APPROVED |
| Scope | SF-001 / P02-AUDIT-006 remediation candidate |
| Reviewed candidate | `ccc95cddca6691781485c47ba4b5d21d872bca09` |
| Reviewed tree | `6535523e5e7fdd88625f7bb78641a2d83c5daffd` |
| Technical QA verdict | `SF001_FORGE8_CANDIDATE_QA_PASS_WITH_NONBLOCKING_FINDINGS` |
| Nonblocking QA findings | QA-SF001-001, QA-SF001-002, QA-SF001-003, QA-SF001-004, QA-SF001-005, QA-SF001-006 — accepted as nonblocking for this SF-001 candidate |

Finding bodies for QA-SF001-001 through QA-SF001-006 were not supplied with
this decision. The IDs and the nonblocking disposition are preserved. No
finding text is invented.

## Canonical authority split

| Item | Result of this decision |
| --- | --- |
| SF-001 independent acceptance (graph ABSENT + independent technical QA + this human approval) | **APPROVED** for the reviewed candidate |
| P02-AUDIT-006 | **CLOSED** |
| G-08-04 (threat model and data classification security/privacy approval) | **OPEN** / `EXTERNAL_HUMAN` — not in scope |
| P02-AUDIT-007 (complete G-08-04 review) | **OPEN** / `EXTERNAL_HUMAN` — not in scope |
| P02-AUDIT-005 / T46 / Profile Claim | **UNCHANGED** |
| Promotion | **not authorized** (`promotion_allowed: false`) |

Authority for the machine-readable SF-001 state is
[`infra/security/exceptions/SF-001.json`](../../../infra/security/exceptions/SF-001.json).

G-08-04 remains the independent Security/Privacy workshop/sign-off for the
Phase 00 threat model and data classification. The supplied Project Owner
decision does not name Security-team membership, Privacy-team membership, or
dual Security+Privacy approval, and is explicitly scoped to SF-001 /
P02-AUDIT-006. It is not recorded as G-08-04 closure.

The P02-AUDIT-004 threat-register snapshot still lists P02-T49 as OPEN. That
row is the frozen AUDIT-004 completeness oracle. Live finding authority for
extract-zip is SF-001.json after this acceptance.

## Historical facts preserved

- Original High severity
- Package `extract-zip` version `2.0.1`
- CVE-2026-56876 / GHSA-jmr9-qjv8-65gv
- Historical MERGE_ONLY merge exception (`historical_scope: MERGE_ONLY`,
  `historical_merge_exception_active: false`, `scope: HISTORICAL_MERGE_ONLY`)
- Graph status ABSENT
- Unscoped extract-zip must remain absent from both npm lockfiles
- Those CVE/GHSA IDs must not return to `infra/security/trivy-merge.ignore`

## Explicit non-claims

- Not G-08-04 CLOSED
- Not P02-AUDIT-007 CLOSED
- Not Privacy approval
- Not Security-team membership
- Not Phase 02 PASS
- Not zero High findings
- Not Profile Claim / T46 / P02-AUDIT-005 change
- Not production promotion
- Not merge of PR #36
