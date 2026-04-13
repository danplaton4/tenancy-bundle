# Architecture Reference

Deep technical documentation of Tenancy Bundle internals. For contributors and advanced users who need to understand how the bundle works under the hood.

## Topics

- [Event Lifecycle](event-lifecycle.md) — TenantResolved, TenantBootstrapped, TenantContextCleared
- [DI Compilation Pipeline](di-compilation.md) — 3 compiler passes, service tagging, container build
- [DBAL Wrapper Mechanics](dbal-wrapper.md) — TenantConnection wrapperClass internals
- [SQL Filter Internals](sql-filter.md) — TenantAwareFilter Doctrine filter
- [Messenger Stamp Lifecycle](messenger-lifecycle.md) — dispatch, serialize, consume, teardown
- [Design Decisions](design-decisions.md) — rationale behind key architectural choices
