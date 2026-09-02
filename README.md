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

`make bootstrap` installs application dependencies in containers, starts
PostgreSQL, Redis, Mailpit, and RustFS, creates the local S3 bucket, applies
Laravel migrations, and registers Gerrymander routes. `make dev` runs the
same idempotent preparation and then keeps the Vite Plus development server
in the foreground.

Local services use trusted Gerrymander domains and do not publish application,
database, Redis, mail dashboard, or object-storage ports on the host:

- https://asset-library.test — Laravel
- https://vite.asset-library.test — Vite/HMR
- https://mail.asset-library.test — Mailpit
- https://s3.asset-library.test — S3 API
- https://storage.asset-library.test — RustFS console

Useful commands:

```bash
make prism-check       # format, lint, and test all PrismFS crates
make prism-s3-test     # exercise PrismFS byte-range reads against RustFS
make web-test          # run Laravel checks in Sail
make up                # start services without foreground Vite
make down              # stop services without deleting volumes
```

The local RustFS credentials and bucket are development-only defaults in the
example environment. Production credentials must never reuse them.
