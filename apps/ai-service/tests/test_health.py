"""Health, metrics, and Qdrant isolation for the AI stub."""

from __future__ import annotations

import respx
from httpx import Response


def test_live_does_not_probe_qdrant(client) -> None:
    with respx.mock(assert_all_called=False) as router:
        qdrant = router.get("http://qdrant.invalid:6333/readyz").mock(return_value=Response(200))
        response = client.get("/live")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "alive"
    assert "data" not in body
    assert qdrant.call_count == 0


def test_ready_probes_qdrant_and_can_be_unready(client) -> None:
    with respx.mock(assert_all_called=True) as router:
        router.get("http://qdrant.invalid:6333/readyz").mock(return_value=Response(503))
        response = client.get("/ready")

    assert response.status_code == 503
    assert response.json()["status"] == "not_ready"


def test_metrics_are_unenveloped(client) -> None:
    response = client.get("/metrics")
    assert response.status_code == 200
    assert "clinic_ai_up" in response.text
    assert "patient" not in response.text


def test_internal_routes_do_not_use_the_health_token_by_accident(
    client, internal_token: str
) -> None:
    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": f"Bearer {internal_token}wrong"},
        json={},
    )
    assert response.status_code == 401
