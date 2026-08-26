"""Shared fixtures for the AI service suite."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from clinic_ai.api.dependencies import get_settings
from clinic_ai.main import create_app

TEST_INTERNAL_TOKEN = "phase00-test-internal-token"


@pytest.fixture
def internal_token() -> str:
    return TEST_INTERNAL_TOKEN


@pytest.fixture
def app(monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setenv("AI_ENVIRONMENT", "local")
    monkeypatch.setenv("AI_INTERNAL_TOKEN", TEST_INTERNAL_TOKEN)
    monkeypatch.setenv("AI_OTEL_ENABLED", "false")
    monkeypatch.setenv("AI_QDRANT_URL", "http://qdrant.invalid:6333")
    get_settings.cache_clear()
    application = create_app()
    yield application
    get_settings.cache_clear()


@pytest.fixture
def client(app) -> TestClient:
    return TestClient(app)
