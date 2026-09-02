# Architecture overview

The asset library separates its control plane from its data plane.

```text
                         control plane

               Laravel + Livewire + API
                          |
                PostgreSQL / Redis
                          |
                    invalidation
                          |
                          v
S3-compatible storage -> PrismFS core -> FUSE -> Samba -> SMB/UNC -> Revit
                          |
                  logs / metrics / traces
```

Laravel owns product and tenant concepts: materials, representations, views,
permissions, approval state, and management workflows. Object storage owns the
bytes. PrismFS projects metadata and policy into a read-oriented filesystem
namespace. Samba is the SMB server; FUSE is PrismFS's first adapter to the Linux
filesystem interface consumed by Samba.

PrismFS does not mirror S3 keys directly. A virtual path resolves to a logical
node and, for a file, an object reference. That keeps tenant-specific views,
flattened Revit layouts, aliases, and future application-specific projections
independent of object layout.

Processing and conversion workers remain separate from PrismFS. They may create
thumbnails, Revit representations, MDL files, or other derivatives and publish
their metadata through the control plane.

## Initial delivery boundary

The first PrismFS milestone is read-only and local-development focused:

1. Resolve and list a deterministic virtual namespace.
2. Read object byte ranges from an S3-compatible service.
3. Authorize each operation through a policy interface.
4. Cache without changing namespace or storage semantics.
5. Emit structured audit events and stable metric names.
6. Expose the composition through the FUSE adapter.

Writes, Samba-native VFS integration, production identity mapping, and cache
invalidation transport are later milestones and must not leak into the core
interfaces prematurely.
