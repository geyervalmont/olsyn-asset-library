# ADR 0003: RustFS for local S3-compatible storage

- Status: accepted for local development
- Date: 2026-09-01

## Context

PrismFS needs a real S3-compatible target for integration testing without using
production buckets. MinIO is not the desired project dependency. RustFS,
Garage, and SeaweedFS were considered.

## Decision

Use a single-node RustFS container and pin the image to `1.0.0-rc.4`. The local
stack creates a durable Docker volume and the `prismfs-dev` bucket automatically.
No storage ports are published directly; Gerrymander provides the trusted S3
and console domains.

RustFS is still pre-GA, so it is a development fixture rather than a production
architecture commitment. PrismFS talks through an object-storage contract and
must remain compatible with AWS S3 and other S3-compatible implementations.

## Alternatives

Garage 2.3 offers an attractive lightweight single-node mode, but its documented
S3 compatibility gaps make it a secondary fixture for now. SeaweedFS is useful
when its filer and distributed topology are required, but its multi-component
development stack adds no value to the first PrismFS milestone.

## References

- https://github.com/rustfs/rustfs/releases/tag/1.0.0-rc.4
- https://docs.rustfs.com/en/installation
- https://garagehq.deuxfleurs.fr/documentation/quick-start/
- https://github.com/seaweedfs/seaweedfs/tree/master/docker
