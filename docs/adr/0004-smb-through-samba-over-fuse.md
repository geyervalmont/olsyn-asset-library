# ADR 0004: expose PrismFS to SMB through Samba over FUSE

- Status: accepted
- Date: 2026-09-02

## Context

Revit and other Windows clients need ordinary path-addressable files, commonly
through an SMB share and UNC paths. PrismFS already exposes its namespace to
Linux through FUSE. Implementing a native Samba VFS module immediately would
create a second filesystem adapter and a substantially larger integration
surface before the namespace, policy, and storage contracts have matured.

## Decision

Mount PrismFS once through FUSE and configure Samba to publish that mounted
namespace as a read-only share. Keep Samba-specific configuration in the
`prismfs-smb` crate and keep all object reads on the existing PrismFS path.

The development container runs PrismFS and Samba with compatible mount
ownership. The guest-only development share is not a production identity
model.

## Consequences

- POSIX and SMB clients exercise the same namespace, policy, storage, cache,
  and telemetry implementation.
- There is no second direct S3 path whose behaviour can drift from FUSE.
- The end-to-end development test can prove RustFS through an actual SMB
  client.
- Production authentication and user-to-policy mapping still need
  control-plane integration.
- A native Samba VFS adapter can be reconsidered if performance or deployment
  evidence justifies it; it would be a separate adapter crate.
