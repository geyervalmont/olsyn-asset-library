# Material authoring

OPAL has two authoring paths. Both produce immutable candidate representations;
neither can overwrite an approved representation or publish a version.

## Material Import

Use **Import maps** for scanned, supplier-authored, manually edited, or
AI-produced image sets. A contributor can create a material or improve an
existing variant. A multi-file picker assigns common filename role hints such
as `albedo`, `normal`, `roughness`, and `height`; explicit per-role fields can
override it. Normal convention remains an explicit choice and is never inferred
from `_gl` or `_dx`.

Every file is content-addressed. The resulting PBR set is a candidate linked to
its uploader, source, role and normal convention. A reviewer approves or rejects
it on the material record. The package worker seals approved inputs into an
immutable USDZ and builds the configured consumer caches; a publisher then cuts
a version that pins that package.

## Material Studio

Use **Material Studio** for editable, procedural materials. The initial clean-room
generators are paint, masonry, timber, terrazzo and textile. Each recipe records:

- real-world repeat width and height;
- production resolution and deterministic seed;
- the generator-specific physical, colour and finish parameters;
- generator version, application digest and toolbox digest;
- PBR maps and, where meaningful, SVG and Revit PAT hatches.

The browser requests a preview from the same `usd-toolbox` engine the
worker uses for production. Start with one of fifteen editable finishes, use
**Fast** (384 px) while adjusting the recipe, and switch to **Detailed** (1K)
to inspect fine grain. Production resolution remains independent. The viewer
keeps its camera, shape and map selection between edits; auto rotation is an
explicit control. Rectangular repeats retain their physical proportions on
both the surface and the four-up repeat inspection.

Changes to unit sizes, joints or bond/weave patterns fit the coverage to a
complete repeat automatically. Manually entered dimensions remain explicit;
**Fit a seamless repeat** repairs a cropped extent. Basket weave requires a
four-thread cycle.

Preview PNGs use authenticated, owner-scoped URLs rather than embedding image
bytes in Livewire state. A fifteen-minute cache keys bakes by recipe, preview
resolution and engine digest; bundled edits bake once. The shared engine source
lives in the sibling `usd-toolbox` repository (`crates/procedural`). Deploy its
updated CLI with these UI changes to obtain the revised paint noise,
longitudinal timber grain, wrapped unit identities and angular terrazzo chips.
Build the CLI against a libc compatible with the application container.

Saving stores the recipe on the variant and queues a tracked bake. Existing
recipes can be reopened and changed, while a paint
family can add a new variant and change just its colour. This handles fixed
supplier ranges and custom colours without giving either a special downstream
format.

Pattern coverage must contain a complete repeat. The toolbox refuses cropped
masonry bonds, staggered board cycles, or weave repeats that would create a seam.

## Repair loop

1. Find the material with keywords, semantic meaning, **Same type**, or the
   image-only **Looks like this** search for a selected colourway.
2. Open the record and inspect raw maps and the lit surface.
3. Choose **Upload maps** for replacement/generated images or **Edit recipe**
   for procedural sources.
4. Review the new candidate beside existing sets.
5. Approve it, render its consistent sphere preview, generate safe lower tiers,
   build the canonical USDZ and its Revit/Omniverse caches, and publish a new
   package-pinned version.

Archived historical imports are hidden from the default material list but remain
available through the status filter. No catalog rows are deleted by this view.

## Public QR sharing

Publishers can open **QR code** on a material record and generate either a
material-level code or one pinned to a colourway. Both the SVG image and its
destination use permanent signed URLs, so the QR can be printed, downloaded,
and opened without an OPAL account. The database IDs in those URLs remain stable
if a material or colourway is recoded, while the signature prevents someone from
changing the selected record in the URL.

The public page is deliberately presentation-only. It shows catalog metadata
and an approved rendered preview; raw maps, canonical USDZ packages, provenance,
review controls, and consumer actions remain behind authenticated access. Public
preview reads are recorded in the file-access audit trail. Public pages are also
marked `noindex` so possession of the shared link, rather than search indexing,
controls discovery.

## Clean-room boundary

The workflow takes inspiration from the category of browser material editors,
including the useful user-facing ideas demonstrated by
[Architextures Create](https://architextures.org/create/133): real scale,
pattern controls, map outputs, hatches and saved definitions. It does not use
Architextures source code, generated assets, visual assets, or private APIs.
That boundary is required by the [Architextures terms](https://architextures.org/page/terms),
which prohibit copying/reverse engineering their software and republishing
their content in competing texture libraries.
