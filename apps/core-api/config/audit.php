<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit chain configuration
|--------------------------------------------------------------------------
|
| PostgreSQL owns per-row SHA-256 chaining. External checkpoints are signed
| with Ed25519 using a private key that is never stored in PostgreSQL.
| Verification uses the configured public key. See ADR 0015 and
| docs/runbooks/audit-chain-checkpoint.md.
|
| A local disk is repository evidence only. It is not an immutable store.
| Production should point AUDIT_CHECKPOINT_DISK at object storage with
| object-lock/WORM (or an equivalent) and keep the private key in a secret
| manager or HSM that database owners and migrators do not receive.
|
*/

return [

    'checkpoint' => [
        'enabled' => (bool) env('AUDIT_CHECKPOINT_ENABLED', false),
        'required' => (bool) env('AUDIT_CHECKPOINT_REQUIRED', false),
        'disk' => (string) env('AUDIT_CHECKPOINT_DISK', 'audit_checkpoints'),
        'prefix' => (string) env('AUDIT_CHECKPOINT_PREFIX', 'checkpoints'),
        'key_id' => (string) env('AUDIT_CHECKPOINT_KEY_ID', 'v1'),
        'private_key' => (string) env('AUDIT_CHECKPOINT_PRIVATE_KEY', ''),
        'private_key_file' => (string) env('AUDIT_CHECKPOINT_PRIVATE_KEY_FILE', ''),
        'public_key' => (string) env('AUDIT_CHECKPOINT_PUBLIC_KEY', ''),
        'public_key_file' => (string) env('AUDIT_CHECKPOINT_PUBLIC_KEY_FILE', ''),
    ],

];
