"""FastAPI application factory for the clinic AI service.

Phase 00 scope: the service exists, starts, validates its configuration, reports
liveness and readiness, and proves it is isolated from the core. Nothing else.

Isolation properties asserted by tests rather than left to convention:
  * no core database credential is present in the environment;
  * no route mutates core state;
  * an outage here leaves core readiness at 200 (gate G-02-04).
"""

from __future__ import annotations

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from clinic_ai import __version__
from clinic_ai.api import health
from clinic_ai.api.dependencies import get_settings

logger = logging.getLogger("clinic_ai")

# Environment variables that must never be present in this process. Their
# presence would mean the AI service can reach the core database directly,
# which ADR 0001 forbids outright.
FORBIDDEN_ENV_PREFIXES = ("DB_", "DATABASE_URL", "REDIS_QUEUE_")


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    """Validate configuration and isolation before serving a single request."""
    import os

    settings = get_settings()

    leaked = [
        name
        for name in os.environ
        if any(name.startswith(prefix) for prefix in FORBIDDEN_ENV_PREFIXES)
    ]
    if leaked and settings.environment != "local":
        # Fail to start. A silent warning here would leave the isolation
        # boundary broken in production while everything looked healthy.
        raise RuntimeError(
            "Core datastore credentials are present in the AI service environment. "
            "The AI service must not be able to reach core storage directly (ADR 0001). "
            f"Offending variable names: {sorted(leaked)}"
        )

    logger.info(
        "ai-service starting",
        extra={"service": settings.service_name, "version": settings.version},
    )

    yield

    logger.info("ai-service stopping")


def create_app() -> FastAPI:
    """Build the application."""
    settings = get_settings()

    app = FastAPI(
        title="Clinic AI Service",
        version=__version__,
        lifespan=lifespan,
        # No interactive docs outside local/development. The internal contract
        # is not a public API, and publishing its shape helps nobody who should
        # be calling it.
        docs_url="/docs" if settings.environment in ("local", "development") else None,
        redoc_url=None,
        openapi_url="/openapi.json"
        if settings.environment in ("local", "development")
        else None,
    )

    app.include_router(health.router)

    @app.exception_handler(Exception)
    async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
        """Return a safe error, never an exception detail.

        The AI service handles untrusted model output and untrusted retrieved
        content. An unhandled exception message could contain either, so the
        body carries a stable code and nothing else.
        """
        logger.exception("unhandled error", extra={"path": request.url.path})

        return JSONResponse(
            status_code=500,
            content={"error": {"code": "INTERNAL_ERROR"}},
        )

    return app


def app_factory() -> Any:
    """Entry point for `uvicorn clinic_ai.main:app_factory --factory`."""
    return create_app()
