# Olsyn Asset Library: project overview

This is the canonical introduction to the project: the problem it solves, the
product we intend to build, PrismFS's place in it, and the boundaries that
should guide implementation. Component READMEs and architecture decision
records provide the lower-level detail.

## The short version

Olsyn Asset Library is a governed material platform for collecting, improving,
reviewing, publishing, and using architectural material assets.

The platform treats a material as structured product knowledge with stable
identity, provenance, physical properties, representations, review state, and
platform mappings. It does not treat a folder or filename as the material.

Laravel is the control plane. It owns tenants, users, material records,
workflows, permissions, approvals, and publication decisions. S3-compatible
object storage owns the source and derived bytes. Processing workers create
derivatives. PrismFS is the data plane: it projects approved object references
into stable, policy-aware, read-only filesystem views that applications such as
Revit can consume through ordinary paths.

## The problem

Architectural materials are more than texture images. A useful material may
combine supplier and product information, colourways, real-world dimensions,
reference imagery, albedo and PBR maps, rendering parameters,
application-specific representations, provenance, quality evidence, and
approval history.

Without a governing system, that information tends to spread across filenames,
shared drives, local scripts, rendering tools, spreadsheets, and people's
memory. The predictable results are:

- duplicate or ambiguous material identity;
- perspective or reference photography being mistaken for a usable texture;
- derived assets with no traceable source or generation settings;
- uncertainty about which version is approved or current;
- paths that break when storage is reorganised;
- separate Revit, Enscape, and Omniverse libraries drifting apart; and
- automation that can generate files but cannot safely govern their release.

The project exists to make those relationships explicit and operational while
remaining pleasant for designers and library operators to use.

## Product vision

The near-term product is an internal, multi-tenant material library and
production workbench. It should let an operator:

1. create or import supplier, product, finish, and colourway information;
2. attach source files without losing provenance;
3. assess source suitability and identify missing coverage;
4. request or run deterministic and assisted processing workflows;
5. review candidates with clear quality and scale evidence;
6. approve, reject, supersede, or restore representations safely;
7. publish approved material packages to application-specific views; and
8. collect canonical materials into project boards without copying their
   identity.

The longer-term product may support supplier participation, architect and
client workspaces, live commercial data, material matching, reusable project
themes, and deeper Revit and Omniverse integration. Those are directions, not
requirements for the first production slice.

## One system, five responsibilities

```text
                 Suppliers / operators / designers
                              |
                              v
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
```

### 1. The control plane

The Laravel and Livewire application is the system of record for product
meaning and decisions. It owns:

- tenant membership, roles, and permissions;
- canonical material identities and relationships;
- asset metadata, checksums, versions, and provenance;
- physical and rendering profiles;
- production jobs and their attempts;
- review, approval, rejection, and supersession;
- project selections and platform mappings; and
- publication state and audit history.

The UI is an operational workbench over this model, not a filesystem browser
that infers truth from directory names.

### 2. Object storage

S3-compatible storage owns immutable file bytes: supplier originals, curated
sources, generated maps, previews, packages, and archived versions. Object keys
are storage implementation details. They must not become canonical material
IDs or user-facing paths.

RustFS is the pinned local development fixture. The application and PrismFS
depend on S3-compatible contracts rather than on RustFS-specific behaviour, so
production storage can be selected independently.

### 3. Processing workers

Workers perform ingestion, analysis, conversion, thumbnailing,
seamless-texture work, PBR generation, preview rendering, or platform
packaging. A worker consumes versioned inputs and produces versioned candidate
outputs plus evidence. It does not silently approve its own work or become the
source of material identity.

Some workflows will be deterministic; others may use assisted or generative
tools. In both cases, provenance, reproducibility where possible, explicit
failure state, and human review are part of the contract.

### 4. PrismFS

PrismFS turns a logical namespace supplied by the control plane into a
read-only filesystem backed by object storage.

A path such as:

```text
/projects/opera-house/materials/Oak Natural/base-color.jpg
```

does not imply an equivalent S3 key. It resolves to a logical node, an immutable
object reference, and an authorization decision. This indirection allows the
same object to appear in several useful projections without copying it:

- an approved tenant library;
- a project-specific selection;
- a stable or flattened Revit view;
- an application-specific package; or
- a compatibility alias retained after a material is renamed.

PrismFS performs policy checks, hides inaccessible paths, issues byte-range
reads, caches ranges, and emits audit and telemetry events. On Linux it exposes
the namespace through FUSE. Samba shares that same mount over SMB so Windows
clients and Revit receive normal UNC paths.

PrismFS is deliberately:

- not the material database;
- not the owner of canonical identity or approval state;
- not a scraper, converter, or PBR generator;
- not a second object store;
- not a direct mirror of S3 keys; and
- not a general-purpose writable network filesystem.

Read-only delivery is a product property, not merely an early limitation.
Edits enter through governed control-plane workflows, where they can be
validated, versioned, and audited. Export or sync packages may still be needed
for offline or application-specific workflows, but they remain publications of
the same canonical records.

### 5. Client and platform integrations

Revit requires path-addressable material assets, which makes a stable SMB/UNC
view especially important. Platform integrations map canonical material
records to Revit, Enscape, Omniverse, USD, or other application identities;
they do not create competing canonical materials.

Project workspaces and material boards reference canonical material versions.
They may record project-specific intent, location, notes, quantities, selected
representation, and publication state without changing the master material.

## Material is a domain, not one model

The Laravel material model should be designed interactively before migrations
are committed. The legacy application demonstrated that one catch-all
`Material` record would collapse concepts that have different lifecycles.
At minimum, the design needs to distinguish concepts in these groups:

| Group | Examples of meaning it owns |
| --- | --- |
| Catalogue | supplier, collection, product, category |
| Material identity | finish, colourway, variant, aliases |
| Technical description | dimensions, repeat, finish class, physical and rendering response |
| Asset | source object, role, checksum, provenance, version |
| Representation | Revit image set, AI-assisted set, PBR set, preview, application package |
| Workflow | job, attempt, candidate, review, approval, supersession |
| Integration | platform identity, mapping, publication, sync state |
| Project context | project, board, selection, notes, intended use |

These names are a vocabulary for modelling, not an approved schema. In
particular, a supplier product, a finish or colourway, a renderable material
set, and a project selection must not be forced into one lifecycle merely
because people casually call all of them a “material.”

## Intended lifecycle

```text
Supplier or manual intake
  -> canonical catalogue and material identity
  -> immutable source upload with provenance
  -> source suitability and coverage assessment
  -> candidate generation or conversion
  -> automated evidence and quality gates
  -> human review
  -> approved, versioned representation
  -> platform mapping and namespace publication
  -> PrismFS projection or explicit export/sync package
  -> Revit, Enscape, Omniverse, or another client
  -> usage and audit feedback
```

Important lifecycle rules:

- strictness beats false completeness: an unsuitable image remains missing;
- every meaningful file is traceable to its source and owning record;
- generation produces a candidate, not an approval;
- only one version is current for a role where the domain requires it;
- replacement is versioned and recoverable rather than destructive;
- canonical identity survives filename, path, and platform-name changes;
- project selection references a material instead of duplicating it; and
- publication is an explicit, observable state transition.

## Tenancy and authorization

The application uses a shared PostgreSQL database and schema with
application-enforced row-level tenant ownership. Users may belong to multiple
tenants and operate within one current tenant. Spatie Permission uses tenant
teams, and Spatie Media Library stores tenant-scoped objects in S3-compatible
storage.

Tenant isolation must apply consistently across HTTP requests, Livewire
updates, queues, media, namespace publication, PrismFS policy, and audit
events. A valid object reference is not by itself permission to discover or
read the object.

## What the legacy prototype taught us

The previous Material Asset Library is a product and workflow reference, not a
codebase to migrate wholesale. It proved useful concepts across a sizeable
real-world collection, including supplier/product/colourway identity, strict
source selection, AI-assisted seamless and PBR workflows, review gates,
platform mappings, project boards, and reversible replacement.

This rebuild preserves these lessons:

- the database owns identity, state, provenance, and mapping truth;
- storage owns bytes;
- filenames and folders remain useful presentation, never primary identity;
- source fidelity, real-world scale, and human visual review matter;
- generated assets need explicit lineage and candidate state;
- Revit and Omniverse are publication targets, not parallel masters;
- project selections reference canonical materials; and
- destructive, expensive, bulk, and approval-override actions require clear
  operator intent.

It intentionally leaves behind legacy implementation constraints:

- SQLite and a filesystem-scanning UI as the production architecture;
- OneDrive and hard-coded Windows paths as canonical storage;
- channel folders such as `Enscape_Revit`, `Omni_PBR`, `AI_Mat`, and `SS` as
  the domain model;
- identity parsed from long filenames;
- ad hoc scripts mutating live files and database rows; and
- workflow documentation standing in for enforceable application state.

Legacy channel names will be useful during import and may remain user-facing
labels, but the new model should represent their underlying roles explicitly.

## Current state

The new monorepo currently provides foundations rather than the complete
product:

- Laravel 13, Livewire 4, PostgreSQL, Redis, Sail, and Bun/Vite development;
- tenant context, tenant-aware permissions, and tenant-scoped media storage;
- a local UI workbench for establishing the visual and component language;
- RustFS as isolated local S3-compatible storage;
- all intended PrismFS crate boundaries;
- a working read-only namespace, policy filtering, S3 range reads, in-process
  cache, FUSE mount, Samba adapter, and telemetry vocabulary; and
- a repeatable end-to-end proof from RustFS through PrismFS and SMB.

The material domain, control-plane namespace API, production PrismFS identity
mapping, worker system, and real platform integrations are not implemented yet.

## Delivery sequence

The practical sequence from here is:

1. Agree on the material-domain vocabulary, invariants, and first vertical
   slice using representative legacy records.
2. Implement catalogue, material identity, assets, representations, and review
   in the Laravel control plane.
3. Import a small, traceable sample from the legacy library into PostgreSQL and
   object storage.
4. Replace PrismFS's development manifest with a control-plane namespace and
   policy source while retaining the standalone manifest adapter for tests.
5. Prove one approved material from intake to a stable Revit-facing UNC path.
6. Add workers and review flows incrementally, beginning with the most
   deterministic transformations.
7. Add project boards, mappings, exports, and broader platform workflows only
   on top of the proven identity and publication model.

## Documentation map

- [`architecture/overview.md`](architecture/overview.md) describes component
  boundaries and runtime flow.
- [`architecture/laravel-control-plane.md`](architecture/laravel-control-plane.md)
  records the implemented tenancy and media foundations.
- [`../services/prismfs/README.md`](../services/prismfs/README.md) documents
  standalone PrismFS development and its current vertical slice.
- [`adr/`](adr/) contains durable architectural decisions.
