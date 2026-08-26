# Disputed profile link

Profile claim stays flag-gated off until product, privacy, security, and support enable it (ADR 0011).

## If the flag is off

`POST` with purpose `profile_claim` returns the same hidden 404 as other disabled features. Do nothing that confirms a candidate exists.

## If the flag is later enabled

1. A second active link is a constraint violation. Leave the unique active link in place.
2. Record `MANUAL_REVIEW_REQUIRED` without disclosing the other account.
3. Only an approved operator workflow with separation of duties may revoke or attach.

Phase 02 owns the patient registry implementation. The current `PatientIdentityRegistry` is unavailable on purpose.
