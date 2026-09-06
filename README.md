# Olsyn Asset Library

Olsyn Asset Library is a governed platform for collecting, improving,
reviewing, publishing, and using architectural material assets. Laravel and
Livewire provide the multi-tenant control plane; object storage owns the bytes;
and PrismFS projects approved assets into stable, policy-aware filesystem views
for Revit and other path-based clients.

Start with [`docs/project-overview.md`](docs/project-overview.md) for the
product vision, domain boundaries, PrismFS's role, lessons retained from the
legacy prototype, current state, and delivery sequence. The
[`docs/README.md`](docs/README.md) file indexes the supporting documentation.

## Repository map

```text
apps/web/                       Laravel 13 + Livewire control plane
services/prismfs/               Rust workspace for the filesystem data plane
integrations/revit/             pyRevit extension, its Revit-independent client, Unix harness
infrastructure/local/           Local RustFS object storage and the VM bridge
deploy/                         Kubernetes manifests and systemd units for PrismFS
docs/architecture/              System design
docs/adr/                       Architectural decisions
docs/project-overview.md        Canonical product and project narrative
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

The idempotent development seed creates the `Olsyn` tenant and four local
accounts, all with password `password`: `admin@example.com` (super-admin),
`test@example.com` (editor), `viewer@example.com` (viewer), and
`solo@example.com` (no workspace). See
[`docs/architecture/laravel-control-plane.md`](docs/architecture/laravel-control-plane.md)
for how accounts, tenants, roles, and super-admin access fit together.
Registration is open locally; new accounts verify their email through Mailpit
and start without a workspace until an admin adds them.

Useful commands:

```bash
make prism-check       # format, lint, and test all PrismFS crates
make prism-e2e         # prove RustFS -> FUSE -> POSIX and SMB end to end
make prism-s3-test     # exercise PrismFS byte-range reads against RustFS
make hmr-check         # verify Vite and Laravel are connected through Gerry
make web-test          # run Laravel checks in Sail
make web-seed          # apply migrations and refresh the development seed
make web-shell         # open a shell in the Laravel container
make up                # start services with Vite running in the background
make down              # stop services without deleting volumes
```

PrismFS is mirrored to its public repository with Git subtree history. From a
clean monorepo, `make prism-publish` pushes `services/prismfs` to the public
`prismfs` remote; `make prism-update` pulls public standalone changes back.

The local RustFS credentials and bucket are development-only defaults in the
example environment. Production credentials must never reuse them.

## Reading order for someone new

1. [`docs/project-overview.md`](docs/project-overview.md) for what this is and why.
2. [`docs/architecture/material-domain.md`](docs/architecture/material-domain.md)
   for the domain: codes, variants, representations, versions, provenance,
   visibility and drives, the workers, the JSON API, the inspector, the quality
   view, and the legacy import.
3. [`integrations/revit/README.md`](integrations/revit/README.md) for how a
   material reaches Revit, and how to exercise the whole loop on Linux without
   Revit installed.
4. [`services/prismfs/README.md`](services/prismfs/README.md) for the data
   plane, the SMB drive server and the container images.

Not in the repository: the legacy corpus (`apps/web/storage/app/legacy/`, kept
out because it is 75 GB of supplier files), real `.env` files, and drive
tokens. Every one of those has a committed `.example` alongside it.
