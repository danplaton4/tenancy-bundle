# Roadmap

> Living document. Reflects current intent — order and scope shift as user feedback comes in. **Open a [GitHub issue](https://github.com/danplaton4/tenancy-bundle/issues) if you want something prioritized.**

## Shipped

- ✅ **v0.1** — initial bundle (2026-04-19)
- ✅ **v0.2 Architectural Fixes** (2026-04-20) — cache contract, resolver chain, DBAL middleware, docs. See [CHANGELOG](https://github.com/danplaton4/tenancy-bundle/blob/master/CHANGELOG.md).
- ✅ **v0.3 Adoption Surface** (2026-05-22) — `OriginHeaderResolver`, `tenancy:install`, Symfony Profiler tab, per-tenant Mailer bootstrapper, runnable three-tenant demo (`examples/saas/`). Latest tag: **v0.3.3**.
- ✅ **v0.4 Storage & Shared Entities** — Filesystem (Flysystem) bootstrapper; shared-entity
  replication (landlord-side master → tenant-side read-only copy via Doctrine events + Messenger
  async fan-out); PHPStan extension with three static rules (`tenancy.mutualExclusion`,
  `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift`). See [CHANGELOG](https://github.com/danplaton4/tenancy-bundle/blob/master/CHANGELOG.md).
- ✅ **v0.5 Operations & Scale** (2026-07-06) — parallel `tenancy:migrate` (bounded subprocess
  worker pool); per-tenant maintenance mode (HTTP 503 + `Retry-After` + allow-list bypass, three
  `tenancy:maintenance:*` commands); tenant health checks (liveness/readiness endpoints +
  `tenancy:health` CLI + optional LiipMonitorBundle, DSN-redacted); and a production
  [**Operations** docs section](ops/maintenance-mode.md). Latest tag: **v0.5.0**. See [CHANGELOG](https://github.com/danplaton4/tenancy-bundle/blob/master/CHANGELOG.md).

Bundle is functionally complete for a v1 surface: two isolation drivers (database-per-tenant + shared-DB), 5 resolvers, Messenger context propagation, cache + mailer per-tenant bootstrappers, per-tenant maintenance mode + health checks, CLI commands (incl. parallel migrations), and the `InteractsWithTenancy` PHPUnit trait. The `v1.0` tag will go on once external adoption validates the surface.

## Next — v0.6 Advanced isolation *(demand-gated)*

- PostgreSQL Row-Level Security driver
- Advanced isolation guide + a when-to-pick-which-driver matrix
- Candidate for the **v1.0** tag if external adoption signals the line is validated

## Planned

Beyond v0.6, scope is demand-driven — see **Future — by demand** below. The **v1.0** tag will go on once external adoption validates the surface.

Scope is intentionally subject to change as users tell us what they need first. The split exists to keep each milestone small enough to ship in weeks, not months.

## Future — by demand

Tracked but unscheduled. **Open an issue to request prioritization** — these are outside the v0.3–v0.6 cadence but are not rejected outright.

- Per-tenant middleware pipelines
- DNS TXT resolver
- Non-SQL primary isolation targets (Redis, MongoDB as primary stores)
- Tenant-aware job scheduler (Messenger covers async context today)
- Multi-region / sharding (infrastructure concern outside bundle scope)
- Symfony Flex recipe / `symfony/recipes-contrib` submission — will adopt when install volume makes the recipe maintenance cost worthwhile

## Want something here?

Open a [GitHub issue](https://github.com/danplaton4/tenancy-bundle/issues) with your use case. Real users asking for a feature is the single strongest input to the next milestone's scope — more than any line in this file.
