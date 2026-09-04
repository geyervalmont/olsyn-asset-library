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

Prerequisites are Docker with Compose and `/dev/fuse`. Rust is only needed for
native development and `make check`; the E2E’s SMB client is containerized.

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
file. Automated E2E runs explicitly ignore this override so machine-specific
services cannot make the release gate nondeterministic.

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

To serve a drive from the OPAL control plane instead of a file, pass the
drive's manifest URL and token. PrismFS fetches the manifest on start and
re-fetches it on an interval using `ETag`/`If-None-Match`, swapping the
namespace in place when it changes:

```bash
prismfs mount \
  --manifest-url https://asset-library.test/prismfs/drives/studio-share/manifest.yaml \
  --manifest-token opal_… \
  --refresh-interval 30
```

`prismfs doctor --manifest-url … --manifest-token …` validates the same
source without mounting.

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

## Drive server

`make drive-up` serves one control-plane drive as an SMB share on the LAN so
a Windows machine (Revit) can map it. It is `dev/drive.compose.yaml`: one
container running PrismFS against the control plane's manifest plus Samba
exporting the mount, on the external `dev-proxy` network where the control
plane (`asset-library-web`) and RustFS (`asset-library-rustfs`) are plain
HTTP, so no TLS trust is needed inside the container.

```bash
cp drive.env.example drive.env      # set PRISMFS_MANIFEST_TOKEN from the Drives page
make drive-up                       # builds the image, waits for the share
make drive-check                    # lists the share, reads one file back, compares hashes
make drive-logs
make drive-down
```

Windows maps `\\<host-ip>\opal` (share name from `PRISMFS_SMB_SHARE`).
`PRISMFS_DRIVE_BIND` picks the host address the share binds on: `0.0.0.0`
for every interface, `192.168.122.1` for a libvirt guest only. Note that
`make dev-up` with the local `compose.override.yaml` also binds 445 on the
libvirt bridge; stop one before starting the other. Guest read-only access is
the development identity model (ADR 0004).

### Authentication

The share is authenticated by default (`PRISMFS_SMB_USER`, password
`PRISMFS_SMB_PASSWORD`, defaulting to the drive token). Windows 11 requires
SMB signing, and a guest logon cannot sign, so a guest-only share is refused
by an up-to-date workstation. Clients store the credential once:

    cmdkey /add:<host> /user:opal /pass:<drive token>

after which UNC paths resolve for every process in that user's sessions.
Leave `PRISMFS_SMB_USER` empty for a guest-only share. Note that mapped
drive letters are per logon session; use the UNC path in the OPAL config.

## Access events

When mounted from a manifest URL, PrismFS ships `read` and `open` events to
the control plane so drive reads appear next to web downloads in the file
access record:

```text
POST {control plane}/prismfs/drives/{slug}/accesses
Authorization: Bearer <drive token>
{"events":[{"request_id":"…","occurred_at":"2026-09-03T03:32:24.481Z",
  "principal":"uid:1000","operation":"read","path":"/materials/…/X.png",
  "result":"allow","bytes":131072,"duration_ms":0.42}]}
```

Events are batched (`--audit-batch`, default 500) and flushed on an interval
(`--audit-flush-interval`, default 5 s). A failed post keeps the batch for
the next flush; the queue holds `--audit-queue` events (default 10 000) and
drops the oldest beyond that. `--audit-operations` widens or narrows the set
(everything is still in the local log). `--audit-url` overrides the derived
endpoint; there is no shipping without a manifest URL.

## Packaging

`Dockerfile` builds two images: `--target runtime` (the binary with FUSE and
CA certificates, entrypoint `prismfs-entrypoint`, which also trusts a CA
mounted at `PRISMFS_EXTRA_CA`) and `--target samba` (the same plus Samba and
the entrypoint that mounts and shares). `make image` builds both as
`prismfs:local` and `prismfs-samba:local`.

`deploy/k8s/prismfs` at the repository root is a kustomization for one drive
as a pod: a privileged `prismfs` container mounting into a shared `emptyDir`
with bidirectional propagation, a `samba` sidecar serving it on 445, a
ConfigMap for the drive and object-store settings, a Secret for the drive
token and credentials, and a Service. `deploy/systemd` runs the same binary
on a bare host as `prismfs-drive@<slug>`. ADR 0005 records the layout.

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
