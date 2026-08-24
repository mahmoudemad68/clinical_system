"""Shared FastAPI dependencies.

Settings are loaded once and cached. Reloading per request would let a partial
environment change take effect mid-flight, so the process reads its
configuration once at startup and keeps it frozen.
"""

from __future__ import annotations

from functools import lru_cache
from typing import Annotated

from fastapi import Depends

from clinic_ai.config.settings import Settings, load_settings


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Return the validated settings for this process."""
    return load_settings()


SettingsDep = Annotated[Settings, Depends(get_settings)]
