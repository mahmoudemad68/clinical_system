"""Operational Prometheus exposition for the AI service."""

from __future__ import annotations

from fastapi import APIRouter
from fastapi.responses import PlainTextResponse

from clinic_ai.api.dependencies import SettingsDep

router = APIRouter(tags=["operational"])


@router.get("/metrics", response_class=PlainTextResponse)
async def metrics(settings: SettingsDep) -> str:
    """Bounded labels only. No query, prompt, or object identifiers."""
    service = settings.service_name
    version = settings.version
    return (
        "# HELP clinic_ai_up 1 if this process is serving\n"
        "# TYPE clinic_ai_up gauge\n"
        f'clinic_ai_up{{service="{service}",version="{version}"}} 1\n'
    )
