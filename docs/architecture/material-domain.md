# Material domain

This records the material model as agreed while building it, slice by slice.
The [project overview](../project-overview.md) gives the reasons; this file
gives the shape. Slice one (identity, variants, search) is implemented; the
later slices are design notes until they land.

## Principles

- **The library is OPAL's.** Materials, variants, files and provenance are
  landlord records. Tenants consume and contribute; contribution is recorded
  (`contributed_by_tenant_id`, `contributed_by_user_id`), never ownership.
- **Nothing is hardcoded.** Categories, variant types, tags, targets, quality
  tiers, map roles and provenance actions are tables seeded with defaults and
  edited by admins. PHP enums are reserved for workflow states that code
  branches on (material status, review state).
- **Codes are readable and immutable.** `CPT-TARKETT-ACADEMIX` is a material,
  `CPT-TARKETT-ACADEMIX-ASHEN` a variant. Segments are separated by `-`, words
  inside a segment by `_`. Codes are proposed from names and frozen at
  creation; renames never touch them. Recoding is an explicit action that keeps
  the old code as an alias, so paths and platform names issued earlier still
  resolve.
- **Provenance is an event log**, shared polymorphically by materials and,
  later, other library assets such as furniture. Files are immutable; every
  change produces a new file and an event that explains it.
- **Search is designed in.** Every material and variant maintains a
  `search_text` column; PostgreSQL provides the generated `tsvector`, trigram
  fuzziness and, later, pgvector embeddings.

## Slice one: identity and variants (implemented)

```text
Category  (kind, code, name)         seeded from the legacy library's 15 real categories
Supplier  (name, code)               code is the id token, e.g. TARKETT; OPAL is the in-house supplier
Source    (name, kind, supplier?,    where files come from; terms are descriptive JSON,
           url, licence_url, terms)  licence decisions are derived from provenance later
VariantType (slug, name)             colourway, saturation, finish, pattern, format, …

Material  code, name, category, supplier?, source?, collection, supplier_product_code,
          material_type, form, tile/thickness mm, repeat_type, install_pattern,
          sqm_cost, currency, lead_time, specifications (JSONB), status,
          contributed_by_*, search_text
  └── Variant  code, token, name, position, physical overrides, dominant colour (hex + LAB),
               colour_family, search_text
        └── VariantAttribute  (variant_type, value, supplier_code, supplier_name, ref_variant?)
Alias     polymorphic retired codes
Tag       polymorphic
```

Rules enforced by tests:

- a material always has at least one variant (`Default` when none is given);
- a variant holds at most one value per variant type, and only known types;
- tokens are unique among sibling variants, so a variant code is always
  `material code + token`; collisions get a numeric suffix (`OAK_2`);
- direct code edits throw; `RecodeMaterial` is the only path and aliases both
  the material and every variant;
- `Material::resolveCode()` accepts current codes and aliases, case-insensitively;
- variants inherit physical dimensions from the material unless overridden;
- search finds by name, supplier, product code, colourway, tag and category,
  tolerates misspellings, and stays in sync when variants or attributes change.

Actions: `CreateMaterial`, `AddVariant`, `RecodeMaterial`. Seeders:
`LibrarySeeder` (idempotent vocabularies) and `DemoMaterialsSeeder` (local only).

Variant attribute values may reference another variant (`ref_variant_id`).
That is how a furniture part will later point at a material option.

## Slice two: files and provenance (implemented)

```text
File               immutable, content-addressed: sha256 (unique), disk, object_key
                   (opal/files/ab/cd/<sha256>.<ext>), kind (image | video | archive |
                   document | model | other), mime, extension, original_name, bytes,
                   width/height, colour space, bit depth, metadata (JSONB)
ProvenanceAction   registry: uploaded, downloaded, scraped, imported, generated, upscaled,
                   tiled, edited, converted, rendered, approved, rejected, …
ProvenanceEvent    subject (material | variant | …), action, actor_type (user | worker |
                   external), actor (user) or actor_name (worker class, external name),
                   tool, tool_version, model, parameters (JSONB), source, source_url,
                   external_ref, job_id, parent_event, notes, occurred_at
ProvenanceEventFile  event ⇄ file, direction (input | output), role (bump, base_color, …)
```

- `FileStore::store()` accepts an upload, a path or raw bytes, hashes the
  bytes, stores them once under the content-addressed key and records image
  dimensions. Identical bytes return the existing `File`. Updating a `File`
  throws.
- `RecordProvenance` writes one event with its input and output files. The
  actor is a `User`, a worker name, or nothing (external), and the action must
  exist in the registry.
- `Lineage` walks output → event → inputs backwards (cycle-safe, depth-capped):
  `File::lineage()` gives the events nearest first, `File::ancestors()` the
  files it was derived from, `File::sources()` every source in the chain.
  `Lineage::timelineFor($subject)` is the oldest-first history of a material
  or variant. `ProvenanceEvent::describe()` renders one line such as
  "Harrison edited with Photoshop 26".
- No licence is stored. "Can we redistribute this?" is answered by reading
  the terms of `File::sources()`; a rule layer can sit on top later.

Materials and variants expose `provenanceEvents()` through `HasProvenance`,
which any later library asset (furniture) reuses.

## Slice three: versions, representations, quality (implemented)

```text
Target             registry: pbr (canonical), revit (also feeds Enscape), omniverse, preview
QualityTier        registry: preview 512, 1k, 2k, 4k, 8k; any pixel size becomes a tier on demand
MapRole            registry: base_color, normal, roughness, glossiness, metallic, height, bump, ao,
                   opacity, specular, transmission, emissive, ref_image, render, thumbnail,
                   mdl, usd, rvt, package — each with a default colour space
Representation     variant × target × quality; kind (pbr_set | image | package, inferred from
                   roles); review_state (candidate | approved | rejected | superseded);
                   reviewer, metadata, notes. Key and files are immutable.
RepresentationFile representation ⇄ file with one file per map role
MaterialVersion    number per material, status (draft | published | superseded), notes,
                   created/published by, published_at
material.current_version_id      the pointer PrismFS projects
material_version_representations version ⇄ representation, with the (variant, target, quality)
                   key denormalised so the database enforces one per key per version
```

- `CreateRepresentation` builds a candidate from files keyed by role.
- `ReviewRepresentation` approves or rejects. Approval supersedes the earlier
  approved representation for the same key, so exactly one approved
  representation exists per (variant, target, quality). Every decision is a
  provenance event on the representation.
- `CutVersion` snapshots all approved representations into a draft;
  representations are referenced, never copied. `PublishVersion` moves the
  pointer, supersedes the previous current version, and is also the rollback:
  publishing an older version again makes it current.

## Slice four: platform identities and derivation (implemented)

```text
Platform           registry: revit → target revit, enscape → target revit, omniverse → omniverse
PlatformIdentity   variant × platform: external_id, external_name, payload, status
Converter          interface: supports(target), convert(canonical, target, quality) → files by role
ConverterRegistry  bound in AppServiceProvider; RevitImageSetConverter, OmniverseMdlConverter
```

- `AssignPlatformIdentity` records what a variant is called on a platform.
- `ResolvePlatformVariant` finds the variant behind an external id, an
  external name, or any string containing a variant code (aliases included),
  e.g. `Academix Ashen [CPT-TARKETT-ACADEMIX-ASHEN]`.
- `DeriveRepresentation` takes the variant's approved canonical `pbr`
  representation (exact quality, else the closest above, else the best
  available), runs the converter for the target, creates a candidate
  representation with `derived_from_*` metadata, and records a `converted`
  provenance event with the canonical files as inputs and the produced files
  as outputs.
- `OmniverseMdlConverter` writes an OmniPBR module referencing the canonical
  textures by file name, with `texture_scale` from the variant's real-world
  size; the textures travel with the representation.
- `RevitImageSetConverter` keeps base colour, uses the normal map as bump, and
  inverts roughness into glossiness.
- `DeriveFromPlatformReference` is the headline path: "the Omniverse material
  for this Revit material" is one call from a Revit id or name.

## Search and similarity

- `search_text` is rebuilt on save from names, codes, supplier, product code,
  category, description, tags and, for materials, their variants' attribute
  values and supplier codes.
- PostgreSQL adds a generated `search_vector` (GIN) and a trigram index on
  `search_text`; `Material::search()` and `Variant::search()` combine
  `websearch_to_tsquery`, `word_similarity` and a contains fallback.
- Dominant colour per variant (hex and LAB) supports colour filters and
  nearest-colour queries.
- Embeddings (image and text) will live in a polymorphic `embeddings` table on
  pgvector, which requires the `pgvector/pgvector` Postgres image.

Tests run against a PostgreSQL database (`asset_library_testing`) because the
search features have no SQLite equivalent; CI provisions the same service.
