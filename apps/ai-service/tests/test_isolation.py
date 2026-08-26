"""Isolation: the AI service must not start with Core datastore credentials."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from clinic_ai.api.dependencies import get_settings
from clinic_ai.main import create_app


def test_staging_refuses_core_database_environment_variables(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("AI_ENVIRONMENT", "staging")
    monkeypatch.setenv("AI_INTERNAL_TOKEN", "staging-token-not-a-secret")
    monkeypatch.setenv("AI_QDRANT_API_KEY", "staging-qdrant-not-a-secret")
    monkeypatch.setenv("DB_HOST", "postgres")
    get_settings.cache_clear()

    application = create_app()
    with pytest.raises(RuntimeError, match="Core datastore"):
        with TestClient(application):
            pass

    get_settings.cache_clear()


def test_local_health_does_not_expose_core_routes(client) -> None:
    response = client.get("/api/v1/health")
    assert response.status_code == 404
    response = client.post("/internal/v1/commands")
    assert response.status_code != 200
