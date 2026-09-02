# Olsyn Asset Library

Monorepo for the Olsyn material-library control plane and PrismFS data plane.
Development is currently focused on PrismFS; the Laravel application exists as
the future management/API surface.

## Repository map

```text
apps/web/                       Laravel 13 + Livewire control plane
services/prismfs/               Rust workspace for the filesystem data plane
infrastructure/local/           Local RustFS object storage
docs/architecture/              System design
docs/adr/                       Architectural decisions
scripts/                        Repeatable developer workflows
```

PrismFS is split into crates from day one so that filesystem concerns do not
accumulate in `prismfs-core`:

```text
prismfs-core        namespace and shared domain types
prismfs-policy      authorization contracts and decisions
prismfs-storage     object reads and S3-compatible storage adapters
prismfs-cache       cache contracts and implementations
prismfs-telemetry   logs, metrics, traces, and audit vocabulary
prismfs-fuse        Linux FUSE adapter
prismfs-smb         safe read-only Samba configuration
prismfs-server      executable composition root
```

## Local development

Prerequisites: Docker, Rustup, and
[Gerrymander](https://github.com/Nano112/gerrymander). PHP, Composer, and
Node do not need to be installed on the host.

```bash
make bootstrap
make dev
```

`make bootstrap` installs application dependencies with Bun in the Sail
container, starts PostgreSQL, Redis, Mailpit, RustFS, and the Vite Plus HMR
service, creates the local S3 bucket, applies Laravel migrations, and registers
Gerrymander routes. `make dev` runs the same idempotent preparation and then
follows the Vite service logs; closing it does not stop hot reloading.
CSS updates are hot-swapped, while Blade, Livewire, and PHP changes trigger a
browser reload through the same Gerry-managed TLS endpoint.

Local services use trusted Gerrymander domains and do not publish application,
database, Redis, mail dashboard, or object-storage ports on the host:

- https://asset-library.test — Laravel
- https://vite.asset-library.test — Vite/HMR
- https://mail.asset-library.test — Mailpit
- https://s3.asset-library.test — S3 API
- https://storage.asset-library.test/rustfs/console/ — RustFS console

The idempotent development seed creates the `Olsyn` tenant and a local account
at `test@example.com` with password `password`.

Useful commands:

```bash
make prism-check       # format, lint, and test all PrismFS crates
make prism-e2e         # prove RustFS -> FUSE -> POSIX and SMB end to end
make prism-s3-test     # exercise PrismFS byte-range reads against RustFS
make hmr-check         # verify Vite and Laravel are connected through Gerry
make web-test          # run Laravel checks in Sail
make up                # start services with Vite running in the background
make down              # stop services without deleting volumes
```

PrismFS is mirrored to its public repository with Git subtree history. From a
clean monorepo, `make prism-publish` pushes `services/prismfs` to the public
`prismfs` remote; `make prism-update` pulls public standalone changes back.

The local RustFS credentials and bucket are development-only defaults in the
example environment. Production credentials must never reuse them.
