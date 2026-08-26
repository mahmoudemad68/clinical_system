# Identity key rotation failure

See also [emergency-credential-rotation](emergency-credential-rotation.md). Identity HMAC and AES-GCM keys are separate from `APP_KEY` (ADR 0013).

## Confirm

`/ready` configuration check fails when `identity.hmac.keys.1` or `identity.encryption.keys.1` is empty.

## Do

1. Dual-read: add key version N+1, keep N readable.
2. New writes use the current version. Backfill is a later resumable job; do not dump plaintext.
3. Retire N only after dual-read verification.

## Do not

Log decrypted phones or National IDs. Do not reuse `APP_KEY` as the identity key.
