"""Internal Laravel → FastAPI authentication."""

from __future__ import annotations

import hmac

from fastapi import Header, HTTPException, status

from clinic_ai.api.dependencies import SettingsDep


def require_internal_token(
    settings: SettingsDep,
    authorization: str | None = Header(default=None),
) -> None:
    """Reject missing, empty, or wrong bearer tokens.

    Timing-safe compare. An empty configured token is still a denial: the
    internal contract is never open, including local, because health probes
    are the only unauthenticated routes.
    """
    expected = settings.internal_token.get_secret_value()
    if not expected:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={"error": {"code": "UNAUTHENTICATED"}},
        )

    if authorization is None or not authorization.startswith("Bearer "):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={"error": {"code": "UNAUTHENTICATED"}},
        )

    provided = authorization.removeprefix("Bearer ")
    if not hmac.compare_digest(provided, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={"error": {"code": "UNAUTHENTICATED"}},
        )
