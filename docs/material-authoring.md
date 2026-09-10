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
it on the material record; a publisher then cuts an immutable version.

## Material Studio

Use **Material Studio** for editable, procedural materials. The initial clean-room
generators are paint, masonry, timber, terrazzo and textile. Each recipe records:

- real-world repeat width and height;
- production resolution and deterministic seed;
- the generator-specific physical, colour and finish parameters;
- generator version, application digest and toolbox digest;
- PBR maps and, where meaningful, SVG and Revit PAT hatches.

The browser requests a small preview from the same `usd-toolbox` engine the
worker uses for production. Saving stores the recipe on the variant and queues
a tracked bake. Existing recipes can be reopened and changed, while a paint
family can add a new variant and change just its colour. This handles fixed
supplier ranges and custom colours without giving either a special downstream
format.

Pattern coverage must contain a complete repeat. The toolbox refuses cropped
masonry bonds, staggered board cycles, or weave repeats that would create a seam.

## Repair loop

1. Find the material from keyword, semantic or similar-material search.
2. Open the record and inspect raw maps and the lit surface.
3. Choose **Upload maps** for replacement/generated images or **Edit recipe**
   for procedural sources.
4. Review the new candidate beside existing sets.
5. Approve it, render its consistent sphere preview, generate safe lower tiers,
   derive Revit/Omniverse outputs, and publish a new version.

Archived historical imports are hidden from the default material list but remain
available through the status filter. No catalog rows are deleted by this view.

## Clean-room boundary

The workflow takes inspiration from the category of browser material editors,
including the useful user-facing ideas demonstrated by
[Architextures Create](https://architextures.org/create/133): real scale,
pattern controls, map outputs, hatches and saved definitions. It does not use
Architextures source code, generated assets, visual assets, or private APIs.
That boundary is required by the [Architextures terms](https://architextures.org/page/terms),
which prohibit copying/reverse engineering their software and republishing
their content in competing texture libraries.
