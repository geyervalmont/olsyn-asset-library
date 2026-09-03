# Two images from one build:
#   --target runtime  prismfs binary with FUSE, for a pod or a bare host
#   --target samba    the same plus Samba and the entrypoint that exports the
#                     mount as a read-only share (dev drive server, or a
#                     single-container deployment)
FROM rust:1.98-bookworm AS builder

WORKDIR /source
COPY Cargo.toml Cargo.lock rust-toolchain.toml rustfmt.toml ./
COPY crates ./crates
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/source/target \
    cargo build --locked --release --bin prismfs \
    && cp target/release/prismfs /tmp/prismfs

FROM debian:bookworm-slim AS runtime

RUN apt-get update \
    && apt-get install --no-install-recommends -y ca-certificates fuse3 \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /srv/prismfs

COPY --from=builder /tmp/prismfs /usr/local/bin/prismfs
COPY dev/prismfs-entrypoint.sh /usr/local/bin/prismfs-entrypoint
RUN chmod 0755 /usr/local/bin/prismfs-entrypoint

ENV PRISMFS_MOUNTPOINT=/srv/prismfs
ENTRYPOINT ["/usr/local/bin/prismfs-entrypoint"]
CMD ["mount"]

FROM runtime AS samba

RUN apt-get update \
    && apt-get install --no-install-recommends -y samba smbclient \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/samba

COPY dev/prismfs-samba-entrypoint.sh /usr/local/bin/prismfs-samba-entrypoint
RUN chmod 0755 /usr/local/bin/prismfs-samba-entrypoint

ENTRYPOINT ["/usr/local/bin/prismfs-samba-entrypoint"]
