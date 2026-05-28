# Roadmap

> Living document. Reflects current intent — order and scope shift as user feedback comes in. **Open a [GitHub issue](https://github.com/danplaton4/tenancy-bundle/issues) if you want something prioritized.**

## Shipped

- ✅ **v0.1** — initial bundle (2026-04-19)
- ✅ **v0.2 Architectural Fixes** (2026-04-20) — cache contract, resolver chain, DBAL middleware, docs. See [CHANGELOG](https://github.com/danplaton4/tenancy-bundle/blob/master/CHANGELOG.md).
- ✅ **v0.3 Adoption Surface — partial** (2026-05-22) — `OriginHeaderResolver`, `tenancy:install`, Symfony Profiler tab, per-tenant Mailer bootstrapper, runnable three-tenant demo (`examples/saas/`). Latest tag: **v0.3.2**.

Bundle is functionally complete for a v1 surface: two isolation drivers (database-per-tenant + shared-DB), 5 resolvers, Messenger context propagation, cache + mailer per-tenant bootstrappers, CLI commands, and the `InteractsWithTenancy` PHPUnit trait. The `v1.0` tag will go on once external adoption validates the surface.

## In progress — closing v0.3

- 📝 **Phase 22 — Docs Refresh (DOC-19)** — install page rewrite, new pages (resolver / profiler / mailer / demo / roadmap), UPGRADE 0.2→0.3, docs-lint extended. Last item before the v0.3 milestone closes.

## Next — v0.4 Storage & shared entities

- **Filesystem (Flysystem) bootstrapper** — per-tenant uploads with prefixed adapters
- **Shared-entity replication** — sync via Doctrine events, async via Messenger; one row in the landlord, fan-out to N tenant DBs
- **PHPStan extension** for `#[TenantAware]` correctness — static check that tenant-scoped repositories aren't accidentally injected into shared services

## Planned

| Milestone | Theme | Highlights |
|---|---|---|
| **v0.5** | Operations & scale | Tenant-level maintenance mode; health check / MonitorBundle integration; parallel `tenancy:migrate` for 100+ tenants |
| **v0.6** | Advanced isolation *(demand-gated)* | PostgreSQL Row-Level Security driver; advanced isolation guide. Candidate for **v1.0** if external adoption signals the line is validated |

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
