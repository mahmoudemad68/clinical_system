# syntax=docker/dockerfile:1.7
#
# FastAPI AI service (plan.md section 3, ADR 0001).
#
# This image is deliberately minimal. The AI service is the process that talks
# to model providers and handles untrusted retrieved content, so its blast
# radius is kept as small as possible: no compiler in the runtime layer, no
# shell utilities beyond what Python needs, non-root, read-only friendly.

FROM python:3.12-slim-bookworm@sha256:0f5b26b9518d002b6173fd61daad821fa340635ebfec5bba471013f9ca114579 AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# ------------------------------------------------------------- deps stage
FROM base AS deps

# Build tooling lives only in this stage and never reaches the runtime image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY apps/ai-service/requirements.txt ./

# --require-hashes: every package must match a recorded hash, so a compromised
# or substituted artifact fails the build rather than shipping (ADR 0008).
RUN python -m venv /opt/venv \
    && /opt/venv/bin/pip install --require-hashes -r requirements.txt

# ----------------------------------------------------------- runtime stage
FROM base AS runtime

ARG APP_VERSION=0.0.0-dev
ARG BUILD_COMMIT=unknown

ENV PATH="/opt/venv/bin:$PATH" \
    AI_VERSION=${APP_VERSION} \
    AI_ENVIRONMENT=production

COPY --from=deps /opt/venv /opt/venv
COPY apps/ai-service/src/ ./src/
COPY apps/ai-service/pyproject.toml ./

ENV PYTHONPATH=/app/src

RUN useradd --uid 10002 --create-home --shell /usr/sbin/nologin aiservice \
    && chown -R aiservice:aiservice /app

USER aiservice

EXPOSE 8001

# Liveness only, matching core-api's reasoning: readiness belongs to the
# orchestrator, and restarting on a dependency blip makes outages worse.
HEALTHCHECK --interval=10s --timeout=3s --start-period=15s --retries=3 \
    CMD python -c "import sys,urllib.request; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8001/live', timeout=2).status == 200 else 1)"

ENTRYPOINT ["uvicorn", "clinic_ai.main:app_factory", "--factory", \
            "--host", "0.0.0.0", "--port", "8001", \
            "--no-server-header", "--proxy-headers"]
