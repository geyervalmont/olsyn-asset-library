# Documentation

Start with [`project-overview.md`](project-overview.md). It is the canonical
explanation of what Olsyn Asset Library is, why it exists, how PrismFS fits, and
what the project is building toward.

The remaining documents add implementation detail without replacing that
narrative:

- [`architecture/overview.md`](architecture/overview.md) — system boundaries
  and runtime data flow;
- [`architecture/laravel-control-plane.md`](architecture/laravel-control-plane.md)
  — implemented tenancy, permission, and media invariants;
- [`architecture/material-domain.md`](architecture/material-domain.md) — the
  material model: readable codes, variants, provenance, search;
- [`../services/prismfs/README.md`](../services/prismfs/README.md) — standalone
- [`../integrations/revit/README.md`](../integrations/revit/README.md) — the pyRevit extension, its Revit-independent client, and the Unix proxy harness
  PrismFS usage, configuration, and development;
- [`adr/`](adr/) — architectural decision records; and
- [`api/README.md`](api/README.md) — API documentation placeholder.

The previous vibe-coded Material Asset Library is historical product and
workflow reference. It is not a runtime dependency or a second source of truth
for this rebuild. Its durable lessons are consolidated in the project overview.

