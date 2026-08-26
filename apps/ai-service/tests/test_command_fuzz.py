"""Property tests for the internal command parser (Phase 00 AI baseline)."""

from __future__ import annotations

from hypothesis import given, settings
from hypothesis import strategies as st


def test_garbage_bodies_never_execute_and_never_500(client, internal_token: str) -> None:
    @given(st.binary(min_size=0, max_size=2048))
    @settings(max_examples=40, deadline=None)
    def inner(payload: bytes) -> None:
        response = client.post(
            "/internal/v1/commands",
            headers={
                "Authorization": f"Bearer {internal_token}",
                "Content-Type": "application/json",
            },
            content=payload,
        )
        assert response.status_code != 500
        assert response.status_code >= 400
        body = response.text.lower()
        assert "traceback" not in body
        assert "prompt" not in body

    inner()
