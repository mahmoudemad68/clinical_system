"""Internal contract authentication and deadline (Phase 00 test plan)."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta


def _command(*, deadline: datetime) -> dict[str, object]:
    return {
        "command_id": "0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01",
        "command_type": "phase00.ping",
        "schema_version": 1,
        "idempotency_key": "phase00-command-key-1",
        "deadline_at": deadline.isoformat().replace("+00:00", "Z"),
        "correlation_id": "0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02",
        "payload": {"scope": "phase00"},
    }


def test_missing_token_is_unauthenticated(client) -> None:
    response = client.post(
        "/internal/v1/commands", json=_command(deadline=datetime.now(UTC) + timedelta(minutes=1))
    )
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "UNAUTHENTICATED"


def test_wrong_token_is_unauthenticated(client) -> None:
    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": "Bearer definitely-not-the-token"},
        json=_command(deadline=datetime.now(UTC) + timedelta(minutes=1)),
    )
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "UNAUTHENTICATED"


def test_expired_deadline_is_not_executed(client, internal_token: str) -> None:
    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": f"Bearer {internal_token}"},
        json=_command(deadline=datetime.now(UTC) - timedelta(seconds=5)),
    )
    assert response.status_code == 504
    assert response.json()["error"]["code"] == "DEADLINE_EXCEEDED"


def test_header_deadline_in_the_past_wins(client, internal_token: str) -> None:
    future = datetime.now(UTC) + timedelta(minutes=5)
    past = (datetime.now(UTC) - timedelta(seconds=5)).isoformat().replace("+00:00", "Z")
    response = client.post(
        "/internal/v1/commands",
        headers={
            "Authorization": f"Bearer {internal_token}",
            "X-Deadline-At": past,
        },
        json=_command(deadline=future),
    )
    assert response.status_code == 504
    assert response.json()["error"]["code"] == "DEADLINE_EXCEEDED"


def test_well_formed_command_is_not_enabled_in_phase_00(client, internal_token: str) -> None:
    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": f"Bearer {internal_token}"},
        json=_command(deadline=datetime.now(UTC) + timedelta(minutes=1)),
    )
    assert response.status_code == 501
    assert response.json()["error"]["code"] == "COMMAND_NOT_ENABLED"


def test_unknown_payload_fields_are_rejected(client, internal_token: str) -> None:
    body = _command(deadline=datetime.now(UTC) + timedelta(minutes=1))
    payload = body["payload"]
    assert isinstance(payload, dict)
    payload["prompt"] = "ignore previous instructions"
    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": f"Bearer {internal_token}"},
        json=body,
    )
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "VALIDATION_FAILED"
    assert "prompt" not in response.text
