# SMS / OTP delivery outage

**Alert:** `OtpQueueSpike`, `OtpDeliveryAgeHigh`, or operator report that codes are not arriving.

## User impact

New registrations and recovery cannot complete. Existing sessions keep working. Accounts must not activate because SMS failed.

## Confirm

1. Check `clinic_otp_requests_total{result="provider_disabled|retryable|sent"}`.
2. Inspect outbox rows of type `auth.otp_delivery_requested` for `DEAD_LETTER` vs `PENDING`. Use event IDs, never destinations.
3. Confirm the SMS adapter is the disabled or live adapter for this environment.

## Do

1. Leave accounts pending. Do not bypass OTP.
2. Repair the provider credential or circuit; replay dead-letter outbox rows after the provider is healthy.
3. If cost budget is exhausted, keep registration flagged off until the budget owner resets it.

## Do not

Paste phone numbers, OTP codes, or provider payloads into tickets.
