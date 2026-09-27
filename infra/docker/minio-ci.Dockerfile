# syntax=docker/dockerfile:1.7
#
# CI / local-dev MinIO Community fixture built from pinned official GitHub
# source. This is not production object-store configuration and is not AIStor.
#
# Why source-build: quay.io/minio anonymous pulls return 401 Unauthorized
# (2026-09), Docker Hub minio repositories are gone, and dl.min.io returns 410.
# The previously pinned Community release is still published as GitHub source.
#
# Integrity: ADD --checksum of the GitHub commit archive, go.sum / GOSUMDB for
# modules, and digest-pinned golang/alpine bases.

ARG GOLANG_IMAGE=golang:1.24.2-alpine3.21@sha256:7772cb5322baa875edd74705556d08f0eeca7b9c4b5367754ce3f2f00041ccee
ARG ALPINE_IMAGE=alpine:3.21.3@sha256:a8560b36e8b8210634f77d9f7f9efd7ffa463e380b75e2e74aff4511df3ef88c

FROM ${GOLANG_IMAGE} AS golang
ENV CGO_ENABLED=0 \
    GOTOOLCHAIN=local \
    GOPROXY=https://proxy.golang.org,direct \
    GOSUMDB=sum.golang.org \
    GOFLAGS=-trimpath
RUN apk add --no-cache ca-certificates

FROM golang AS minio-build
ADD --checksum=sha256:7eb30a913fea30f18069abf194e1e78e4983b558cc526911ae1c11396a9859a5 \
    https://github.com/minio/minio/archive/0d7408fc9969caf07de6a8c3a84f9fbb10a6739e.tar.gz \
    /tmp/minio.tar.gz
RUN mkdir -p /src && tar -C /src --strip-components=1 -xzf /tmp/minio.tar.gz
WORKDIR /src
# Same release identity as the previous quay Community pin.
RUN go build -tags kqueue -ldflags "-s -w \
        -X github.com/minio/minio/cmd.Version=2025-04-22T22:12:26Z \
        -X github.com/minio/minio/cmd.CopyrightYear=2025 \
        -X github.com/minio/minio/cmd.ReleaseTag=RELEASE.2025-04-22T22-12-26Z \
        -X github.com/minio/minio/cmd.CommitID=0d7408fc9969caf07de6a8c3a84f9fbb10a6739e \
        -X github.com/minio/minio/cmd.ShortCommitID=0d7408fc9969" \
        -o /out/minio

FROM golang AS mc-build
ADD --checksum=sha256:4cd13e34daeeb8481c3ba8686b082f161b8dc1f7aad52d715a706a587349c6ae \
    https://github.com/minio/mc/archive/b00526b153a31b36767991a4f5ce2cced435ee8e.tar.gz \
    /tmp/mc.tar.gz
RUN mkdir -p /src && tar -C /src --strip-components=1 -xzf /tmp/mc.tar.gz
WORKDIR /src
RUN go build -tags kqueue -ldflags "-s -w \
        -X github.com/minio/mc/cmd.Version=2025-04-16T18:13:26Z \
        -X github.com/minio/mc/cmd.ReleaseTag=RELEASE.2025-04-16T18-13-26Z \
        -X github.com/minio/mc/cmd.CommitID=b00526b153a31b36767991a4f5ce2cced435ee8e \
        -X github.com/minio/mc/cmd.ShortCommitID=b00526b153a3" \
        -o /out/mc

FROM ${ALPINE_IMAGE} AS minio
RUN apk add --no-cache ca-certificates wget
COPY --from=minio-build /out/minio /usr/bin/minio
RUN chmod 0755 /usr/bin/minio
EXPOSE 9000 9001
VOLUME ["/data"]
ENTRYPOINT ["/usr/bin/minio"]
CMD ["server", "/data", "--console-address", ":9001"]

FROM ${ALPINE_IMAGE} AS mc
RUN apk add --no-cache ca-certificates
COPY --from=mc-build /out/mc /usr/bin/mc
RUN chmod 0755 /usr/bin/mc
ENTRYPOINT ["/usr/bin/mc"]
