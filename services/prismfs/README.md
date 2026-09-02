# PrismFS

PrismFS projects immutable objects from S3-compatible storage into a
policy-aware, read-only filesystem. Linux applications consume the namespace
through FUSE; SMB clients consume that same FUSE mount through Samba.

The current vertical slice is executable end to end:

```text
RustFS object -> namespace + policy -> range cache -> FUSE -> POSIX / Samba -> SMB client
```

Denied paths are removed from directory listings and rejected on direct
lookup. Reads are range-based and cached in process. FUSE and Samba both
enforce read-only behavior.

## Quick start

Prerequisites are Docker with Compose, `/dev/fuse`, and `smbclient`. Rust is
only needed for native development and `make check`.

```bash
make e2e
```

That command builds the service, provisions a disposable RustFS bucket and
fixtures, mounts PrismFS, reads the public fixture through POSIX and SMB,
checks policy hiding and write rejection, and removes its containers and
volume.

For an interactive standalone stack:

```bash
make dev-up
make endpoints
make dev-logs
make dev-down
```

Host ports are assigned dynamically and the Compose project name includes a
hash of the checkout path, so parallel checkouts do not collide. `make
endpoints` prints the actual S3 and SMB URLs.

Machine-specific Compose changes can live in the ignored
`dev/compose.override.yaml`; the wrapper loads it automatically after the base
file.

For the quickest filesystem loop, mount on the host while using an isolated
containerized RustFS backend:

```bash
make mount
# another terminal
cat mnt/public/hello.txt
```

`Ctrl-C` unmounts FUSE and removes the disposable backend.

## Configuration

`dev/namespace.yaml` is the versioned namespace manifest. Each file maps one
absolute virtual path to an immutable object reference:

```yaml
version: 1
files:
  - path: /public/hello.txt
    object:
      bucket: prismfs-dev
      key: fixtures/hello.txt
      size: 43
      version: null
```

The runtime reads standard `AWS_*` settings understood by the Rust
`object_store` adapter. `PRISMFS_S3_BUCKET` must match the manifest bucket.
Run `prismfs mount --help` for mount, tenant, and policy options. A
comma-delimited `PRISMFS_DENY_PREFIX` or repeatable `--deny-prefix` hides
paths and their descendants.

Samba is deliberately an adapter over the mounted namespace, not a second
storage path. The combined development container runs both processes as the
FUSE mount owner, avoiding host `allow_other` configuration. Its published
share is guest-only and read-only; production identity integration is a later
control-plane concern.

## Workspace boundaries

- `prismfs-core` — paths, manifests, nodes, namespace contracts
- `prismfs-policy` — authorization actions and policies
- `prismfs-storage` — range readers and the S3-compatible adapter
- `prismfs-cache` — object-range cache contracts
- `prismfs-telemetry` — audit events and stable metric vocabulary
- `prismfs-fuse` — inode mapping and Linux FUSE callbacks
- `prismfs-smb` — safe Samba configuration generation
- `prismfs-server` — executable composition root

Use `make check` for the full format, lint, and test gate. `make s3-test`
independently runs the storage compatibility test against disposable RustFS.

PrismFS also lives under `services/prismfs` in the private Olsyn Asset Library
monorepo and is published as a public Git subtree. Development remains fully
standalone from either checkout.
