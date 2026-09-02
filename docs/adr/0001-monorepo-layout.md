# ADR 0001: Monorepo layout

- Status: accepted
- Date: 2026-09-01

## Decision

Keep product applications under `apps/`, long-running data-plane components
under `services/`, local and deployment configuration under `infrastructure/`,
and durable design records under `docs/`.

PrismFS remains self-contained under `services/prismfs` so it can be extracted
into a standalone open-source repository later without importing Laravel or
product-domain code.

## Consequences

The root owns cross-project workflows and CI. Laravel retains its normal Sail
layout inside `apps/web`. PrismFS retains a normal Cargo workspace layout.
