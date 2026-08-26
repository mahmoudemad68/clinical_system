"""Authenticated internal command endpoint. Phase 00 refuses execution."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated, Any, Literal

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field

from clinic_ai.api.auth import require_internal_token

router = APIRouter(
    prefix="/internal/v1",
    tags=["internal"],
    dependencies=[Depends(require_internal_token)],
)


class CommandPayload(BaseModel):
    model_config = ConfigDict(extra="forbid")

    object_ref: str | None = Field(default=None, max_length=160)
    scope: str | None = Field(default=None, max_length=64)


class InternalCommand(BaseModel):
    model_config = ConfigDict(extra="forbid")

    command_id: str
    command_type: str = Field(pattern=r"^[a-z][a-z0-9._-]{0,63}$")
    schema_version: Literal[1]
    idempotency_key: str = Field(min_length=16, max_length=255)
    deadline_at: datetime
    correlation_id: str
    payload: CommandPayload


def _parse_deadline(deadline_at: datetime, deadline_header: str | None) -> datetime:
    if deadline_at.tzinfo is None:
        deadline_at = deadline_at.replace(tzinfo=UTC)
    else:
        deadline_at = deadline_at.astimezone(UTC)

    if deadline_header:
        try:
            header_deadline = datetime.fromisoformat(deadline_header.replace("Z", "+00:00"))
        except ValueError as exc:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail={"error": {"code": "MALFORMED_REQUEST"}},
            ) from exc
        if header_deadline.tzinfo is None:
            header_deadline = header_deadline.replace(tzinfo=UTC)
        deadline_at = min(deadline_at, header_deadline.astimezone(UTC))

    return deadline_at


@router.post("/commands")
async def submit_command(
    command: InternalCommand,
    x_deadline_at: Annotated[str | None, Header()] = None,
) -> dict[str, Any]:
    """Accept a well-formed command and refuse to execute it in Phase 00."""
    deadline = _parse_deadline(command.deadline_at, x_deadline_at)
    if deadline <= datetime.now(UTC):
        raise HTTPException(
            status_code=status.HTTP_504_GATEWAY_TIMEOUT,
            detail={"error": {"code": "DEADLINE_EXCEEDED"}},
        )

    raise HTTPException(
        status_code=status.HTTP_501_NOT_IMPLEMENTED,
        detail={"error": {"code": "COMMAND_NOT_ENABLED"}},
    )
