# ADR 0006: USDZ is the published material content truth

- Status: accepted
- Date: 2026-09-17

## Context

OPAL previously published approved `pbr`, `revit`, and `omniverse`
representations side by side. That allowed application-specific files to drift
from the USD package and made a version a collection of parallel masters. A
Revit texture could be approved independently of the package that was supposed
to describe the same material.

The catalogue database still has to own identity, permissions, workflow, and
the current-version pointer. “USDZ is the source of truth” refers to published
material content, not to those control-plane decisions.

## Decision

One immutable USDZ `Package` is the content boundary for a variant revision.
A `MaterialVersion` pins one package per variant. It does not pin loose target
representations.

Consumer outputs are disposable `PackageDerivative` caches. Their identity is:

```text
package + target + quality + converter + converter version
```

Each cache records the source package SHA-256 and its files by semantic role.
The package bytes are verified before conversion. Revit caches are produced by
`usd-toolbox export` and contain base colour, bump, and glossiness. A package
cannot be published until every configured publication target has a verified
cache. Publishing rechecks that invariant before moving the current pointer.

PrismFS manifests project only files from caches whose source hash matches the
package pinned by the current material version. Friendly drive paths are views,
not storage identities:

```text
/materials/{Category}/{Material}/{Variant}/{target}/{quality}/{code}_{role}.{ext}
```

Loose PBR maps remain immutable authoring inputs and provenance evidence used
to build the next USDZ. Pre-existing target representations remain readable as
history, but are not publishable content and are never exposed by a drive.

## Consequences

- Revit, Omniverse, and future consumers cannot become parallel masters.
- Rebuilding a cache with a new converter produces a new cache generation,
  without changing the package or material version.
- Rollback moves the version pointer back to an already pinned package.
- Deleting all consumer caches does not lose material content; they can be
  regenerated from USDZ. It does temporarily make that package unpublishable.
- Existing published versions need their package-derived caches built before
  they appear on a drive under the new projection.
- The database remains authoritative for catalogue meaning and governance;
  the USDZ remains authoritative for the published material payload.
