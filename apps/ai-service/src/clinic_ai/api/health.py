"""Health and readiness endpoints for the AI service.

Mirrors the core-api contract shape (LivenessResult / ReadinessResult in
packages/contracts/openapi/openapi.yaml) so an orchestrator treats both services
identically.

The critical/optional split is inverted relative to core-api, and deliberately
so: Qdrant is optional for *core* readiness but critical for this service's own
readiness, because an AI service that cannot retrieve cannot do its job. It
still only reports itself unready — the core treats this whole service as
optional, so nothing else is dragged down (ADR 0001).
"""

from __future__ import annotations

import time
from typing import Any, Literal

import httpx
from fastapi import APIRouter, Response

from clinic_ai.api.dependencies import SettingsDep
from clinic_ai.config.settings import Settings

router = APIRouter(tags=["operational"])

CheckStatus = Literal["pass", "degraded", "fail"]


def _check(name: str, critical: bool, status: CheckStatus, started_at: float) -> dict[str, Any]:
    return {
        "name": name,
        "critical": critical,
        "status": status,
        "duration_ms": int((time.perf_counter() - started_at) * 1000),
    }


async def _probe_qdrant(settings: Settings) -> dict[str, Any]:
    """Bounded liveness probe for Qdrant.

    Hard timeout: this runs inside the readiness path, and a slow dependency
    must not make the probe itself time out. An orchestrator reads a timed-out
    readiness call as "not ready" and starts cycling healthy instances.
    """
    started = time.perf_counter()

    headers: dict[str, str] = {}
    api_key = settings.qdrant_api_key.get_secret_value()
    if api_key:
        headers["api-key"] = api_key

    try:
        async with httpx.AsyncClient(timeout=2.0) as client:
            response = await client.get(
                f"{settings.qdrant_url.rstrip('/')}/readyz",
                headers=headers,
            )
        status: CheckStatus = "pass" if response.status_code == 200 else "fail"
    except httpx.HTTPError:
        # Swallowed on purpose. A readiness body is reachable by anything that
        # can reach the port, and a connection error names hosts and sometimes
        # credentials.
        status = "fail"

    return _check("qdrant", critical=True, status=status, started_at=started)


@router.get("/live", summary="Liveness probe")
async def live(settings: SettingsDep) -> dict[str, Any]:
    """Report only that this process is alive.

    Checks nothing else. A liveness probe that touches a dependency restarts
    healthy processes during a transient outage, turning a small problem into a
    large one.
    """
    return {
        "status": "alive",
        "service": settings.service_name,
        "version": settings.version,
    }


@router.get("/ready", summary="Readiness probe")
async def ready(settings: SettingsDep, response: Response) -> dict[str, Any]:
    """Report whether this process can serve traffic."""
    started = time.perf_counter()

    checks: list[dict[str, Any]] = [
        _check("configuration", critical=True, status="pass", started_at=started),
        await _probe_qdrant(settings),
    ]

    ready_now = not any(c["critical"] and c["status"] == "fail" for c in checks)
    response.status_code = 200 if ready_now else 503

    return {
        "status": "ready" if ready_now else "not_ready",
        "service": settings.service_name,
        "version": settings.version,
        "checks": checks,
    }
