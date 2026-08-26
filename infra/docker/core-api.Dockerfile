# syntax=docker/dockerfile:1.7
#
# Laravel core-api on FrankenPHP (plan.md section 3: Octane + FrankenPHP).
#
# Multi-stage so the runtime image carries no Composer, no build toolchain, and
# no dev dependencies. ADR 0008 requires digest-pinned bases; the digests below
# are resolved by infra/docker/pin-base-images.sh and must not be edited by hand.
#
# Alpine, not Debian: the Debian frankenphp image ships unfixed High/Critical
# perl CVEs that fail Trivy with ignore-unfixed=false. Alpine still carries
# gobinary findings inside the frankenphp binary until upstream rebuilds.

# ---------------------------------------------------------------- base stage
FROM dunglas/frankenphp:1-php8.3-alpine@sha256:049b8d8356efceb93c91ed42866de890534310bcef4ad4dde902029e4a0d20c3 AS base

# Apply Alpine OpenSSL fixes that exist on the 3.24 repository (CVE-2026-14456).
RUN apk upgrade --no-cache libcrypto3 libssl3 openssl

# PHP extensions the platform actually needs:
#   pdo_pgsql  PostgreSQL, the source of truth
#   intl       locale-aware formatting for Arabic/English at the edge
#   bcmath     exact arithmetic helpers
#   zip        artifact and export handling
#   pcntl      Octane worker signal handling
#   opcache    required for any acceptable PHP throughput
#
# NOT installed: ext-redis. predis is used instead, so the image needs no PECL
# build for Redis. Revisit only with measured evidence that predis is the
# bottleneck; a native extension is a real gain but also a real build cost.
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        zip \
        pcntl \
        opcache \
        sockets

WORKDIR /app

# ------------------------------------------------------------ vendor stage
FROM base AS vendor

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# Copy only the manifests first so the dependency layer is cached and rebuilds
# only when dependencies actually change.
COPY apps/core-api/composer.json apps/core-api/composer.lock ./

# --frozen-lockfile equivalent: the install must reproduce the committed lock
# exactly. A lockfile that would change here fails the build (ADR 0008).
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

# ------------------------------------------------------------ runtime stage
FROM base AS runtime

ARG APP_VERSION=0.0.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_TIMESTAMP=unknown

ENV APP_VERSION=${APP_VERSION} \
    BUILD_COMMIT=${BUILD_COMMIT} \
    BUILD_TIMESTAMP=${BUILD_TIMESTAMP} \
    APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp

COPY --from=vendor /app/vendor ./vendor
COPY apps/core-api/ ./

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -f /usr/bin/composer

COPY infra/docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY infra/docker/php/limits.ini  /usr/local/etc/php/conf.d/zz-limits.ini

# Non-root. The phase file requires non-root and read-only containers where
# compatible. Laravel needs write access to storage and bootstrap/cache only.
RUN adduser -D -u 10001 -H -s /sbin/nologin clinic \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R clinic:clinic storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache

USER clinic

EXPOSE 8080

# Liveness only. Readiness is evaluated by the orchestrator against /ready,
# which checks critical dependencies; using /ready here would restart a healthy
# process during a transient database blip.
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/live || exit 1

ENTRYPOINT ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8080"]
