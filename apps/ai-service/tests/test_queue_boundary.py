"""PHP/Python queue isolation (Phase 00 / ADR 0009)."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

PHP_SERIALIZED_LARAVEL_JOB = 'O:40:"Illuminate\\Queue\\CallQueuedHandler":1:{s:7:"command";N;}'


def test_php_serialized_job_is_rejected(client, internal_token: str) -> None:
    response = client.post(
        "/internal/v1/commands",
        headers={
            "Authorization": f"Bearer {internal_token}",
            "Content-Type": "application/json",
        },
        content=PHP_SERIALIZED_LARAVEL_JOB,
    )
    assert response.status_code in {400, 401, 422}
    assert "Illuminate" not in response.text
    assert "CallQueuedHandler" not in response.text


def test_php_serialized_job_is_not_acknowledged_as_a_command(client, internal_token: str) -> None:
    body = {
        "command_id": "0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01",
        "command_type": "phase00.ping",
        "schema_version": 1,
        "idempotency_key": "phase00-command-key-1",
        "deadline_at": (datetime.now(UTC) + timedelta(minutes=1))
        .isoformat()
        .replace("+00:00", "Z"),
        "correlation_id": "0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02",
        "payload": {"scope": PHP_SERIALIZED_LARAVEL_JOB},
    }
    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": f"Bearer {internal_token}"},
        json=body,
    )
    assert response.status_code in {422, 501}
    assert response.status_code != 200
    assert "CallQueuedHandler" not in response.text
