# ADR 0002: PrismFS crate boundaries from day one

- Status: accepted
- Date: 2026-09-01

## Decision

Create all seven intended PrismFS crates immediately: core, policy, storage,
cache, telemetry, FUSE, and server.

`prismfs-core` contains only namespace contracts and shared domain types. It
must not depend on FUSE, S3, a database client, or observability exporters.
Adapters depend inward on contracts; the server crate is the composition root.

## Consequences

The initial crates are intentionally small, but dependency direction is visible
and enforceable from the first commit. A future Samba VFS adapter will be a new
adapter crate; it is not part of the initial workspace.
