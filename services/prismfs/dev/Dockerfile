# syntax=docker/dockerfile:1.7
FROM rust:1.98-bookworm AS builder

WORKDIR /source
COPY . .
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/source/target \
    cargo build --locked --release --bin prismfs \
    && cp target/release/prismfs /tmp/prismfs

FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install --no-install-recommends -y ca-certificates fuse3 samba smbclient \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /tmp/prismfs /usr/local/bin/prismfs
COPY dev/prismfs-samba-entrypoint.sh /usr/local/bin/prismfs-samba-entrypoint

RUN chmod 0755 /usr/local/bin/prismfs-samba-entrypoint \
    && mkdir -p /srv/prismfs /run/samba

ENTRYPOINT ["/usr/local/bin/prismfs-samba-entrypoint"]
