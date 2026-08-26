# Lost device

**Alert:** operator/support request, not a metric.

## User impact

A phone or desktop may still hold a hashed-at-rest refresh token wrapped by the OS keystore.

## Confirm

The caller authenticates on another device and lists sessions. Identify the lost device by platform and label only.

## Do

1. Revoke that session (`DELETE /api/v1/auth/sessions/{id}`) or revoke all.
2. Ask the user to sign out locally if the device is still in hand: Flutter clears secure storage; Electron main process deletes the wrapped credential file.
3. Confirm subsequent refresh from the lost device returns `401`.

## Do not

Ask the user to paste a token. Do not collect the precise IP.
