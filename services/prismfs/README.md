# PrismFS

A policy-aware virtual filesystem for projecting object storage into dynamic
namespaces.

PrismFS is currently an early, read-only workspace scaffold. Its crate
boundaries and core contracts are intentional: FUSE mounting and control-plane
integration will be implemented as vertical slices without moving adapter
concerns into `prismfs-core`.

## Workspace

- `prismfs-core` — namespace contracts and shared domain types
- `prismfs-policy` — authorization contracts and decisions
- `prismfs-storage` — object reads and S3-compatible adapters
- `prismfs-cache` — cache contracts and implementations
- `prismfs-telemetry` — logs, metrics, traces, and audit vocabulary
- `prismfs-fuse` — Linux FUSE adapter
- `prismfs-server` — executable composition root

## Development

Install Rust through rustup; `rust-toolchain.toml` pins the project toolchain.

```bash
make check
make doctor
```

The ignored S3 compatibility test writes a temporary object, reads an exact
byte range, and removes the object. Copy `.env.example` to `.env` and point it
at any development S3-compatible service before running:

```bash
set -a
. ./.env
set +a
make s3-test
```

Within the Olsyn Asset Library monorepo, `make bootstrap` provisions the
RustFS fixture and `make prism-s3-test` supplies its Gerrymander endpoint.
