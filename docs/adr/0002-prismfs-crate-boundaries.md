# ADR 0002: PrismFS crate boundaries from day one

- Status: accepted, extended by ADR 0004
- Date: 2026-09-01

## Decision

Create all seven initially intended PrismFS crates immediately: core, policy,
storage, cache, telemetry, FUSE, and server. New transport adapters remain
separate crates rather than accumulating in core; ADR 0004 later added the
`prismfs-smb` Samba configuration adapter.

`prismfs-core` contains only namespace contracts and shared domain types. It
must not depend on FUSE, S3, a database client, or observability exporters.
Adapters depend inward on contracts; the server crate is the composition root.

## Consequences

The initial crates are intentionally small, but dependency direction is visible
and enforceable from the first commit. The current Samba adapter configures a
share over the FUSE mount. A future native Samba VFS adapter, if justified,
would remain a separate adapter rather than changing core contracts.
