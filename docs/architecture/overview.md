# Architecture overview

This document is the compact technical map. Read the
[`project overview`](../project-overview.md) first for the product problem,
domain language, legacy lessons, and intended delivery sequence.

The asset library separates product decisions, file storage, processing, and
file delivery. Each has one owner.

```text
                    Laravel + Livewire + API
                   /            |             \
                  v             v              v
        PostgreSQL / Redis    workers    namespace + policy
                               |              |
                               v              v
                    S3-compatible storage -> PrismFS
                                                  |
                                        FUSE -> Samba -> SMB/UNC
                                                  |
                                                  v
                                          Revit and other clients

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

## Current PrismFS boundary

The standalone development slice currently:

1. Resolve and list a deterministic virtual namespace.
2. Read object byte ranges from an S3-compatible service.
3. Authorize each operation through a policy interface.
4. Cache without changing namespace or storage semantics.
5. Emit structured audit events and stable metric names.
6. Expose the composition through FUSE and through Samba over that mount.
7. Prove RustFS to FUSE, POSIX, Samba, and an SMB client end to end.

The namespace now has a control-plane source: a *drive* in Laravel renders
the current version of every material it may see as a manifest, and
`prismfs mount --manifest-url … --manifest-token …` fetches it with the
drive's bearer token and re-fetches it on an interval (cheap 304s through
`ETag`). The file manifest and deny-prefix policy remain development
adapters. Production still needs identity mapping, a push-based invalidation
transport instead of polling, and deployment design. A native
Samba VFS adapter remains optional future work. Writes are outside PrismFS's
intended delivery boundary unless a later architectural decision changes it.

## Direction of authority

```text
Laravel records and decisions
        |
        +---- references ----> immutable S3 objects
        |
        +---- publishes -----> logical namespace + policy
                                      |
                                      v
                                   PrismFS
                                      |
                                      v
                              read-only client view
```

PrismFS can cache bytes and namespace results, but it cannot promote a
candidate, change canonical material identity, or infer permission from an S3
key. Workers can create candidates, but only the control plane can approve and
publish them.
