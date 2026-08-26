# AI internal contract

Typed, authenticated commands from Laravel Core to the isolated FastAPI
service (ADR 0001, ADR 0009, Phase 00 § "Queue ownership across PHP and Python").

Phase 00 ships the envelope, authentication, deadline, and fail-closed
execution. Product tools arrive in Phase 16.

## Rules

1. FastAPI has no Core PostgreSQL credentials and never writes Core tables.
2. Laravel starts Python work through this HTTP command. Horizon never carries
   a PHP job that Python deserializes.
3. Every command carries `command_id`, `idempotency_key`, `schema_version`,
   and `deadline_at`. A timeout is an unknown outcome to reconcile, not
   permission to create a second task.
4. Payloads are object references and scope identifiers. Clinical text,
   prompts, credentials, and national IDs are forbidden.
5. Missing, empty, or wrong `Authorization: Bearer` tokens are `401`.
6. An expired deadline is `504 DEADLINE_EXCEEDED`.
7. Phase 00 returns `501 COMMAND_NOT_ENABLED` for a well-formed command.

## Files

- `command.v1.schema.json` — inbound command
