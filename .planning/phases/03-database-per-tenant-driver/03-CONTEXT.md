# Phase 3: Database-Per-Tenant Driver - Context

**Gathered:** 2026-03-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Implement runtime database connection switching per tenant using DBAL 4's `wrapperClass` mechanism, and configure two named Doctrine entity managers: `landlord` (static, reads central Tenant registry) and `tenant` (runtime-switched to active tenant DB). This phase does NOT include shared-DB SQL filters (`#[TenantAware]`, `TenantAwareFilter`) — that is Phase 4.

**Trigger:** Switching happens when `TenantContext::setTenant()` fires (via bootstrapper chain). Teardown (EM reset) happens on `TenantContextCleared` event.

</domain>

<decisions>
## Implementation Decisions

### `connectionConfig` schema
- **Format**: individual DSN components as JSON keys — NOT a single URL string. Keys: `driver` (e.g. `pdo_mysql`, `pdo_pgsql`), `host`, `port`, `dbname`, `user`, `password`. Optional: `charset` (defaults to `utf8mb4`), `server_version`.
- **Rationale**: Individual keys allow partial overrides (e.g., same host/user, different `dbname` per tenant), are DBAL 4's native params shape (`Connection::getParams()`), and are safer to validate than URL strings.
- **Example stored value**: `{"driver":"pdo_mysql","host":"db.internal","port":3306,"dbname":"tenant_acme","user":"acme_user","password":"secret"}`
- **Missing keys**: `TenantConnection::switchTenant()` merges tenant-specific params over landlord defaults — fields not in `connectionConfig` inherit from the base connection's params. This allows shared infrastructure (host, user) with only `dbname` varying per tenant.

### Doctrine config ownership — bundle scope
- **The bundle does NOT auto-configure `doctrine.dbal` connections or entity managers.** DoctrineBundle's configuration system does not support programmatic manipulation post-compilation.
- **The bundle provides**: `TenantConnection` (the DBAL `wrapperClass` subclass) and a `DatabaseSwitchBootstrapper` (registers itself in the bootstrapper chain).
- **The user must configure** `config/packages/doctrine.yaml` with:
  - Two named DBAL connections: `landlord` (standard) and `tenant` (uses `wrapper_class: Tenancy\Bundle\DBAL\TenantConnection`)
  - Two named entity managers: `landlord` (maps `Tenancy\Bundle\Entity\`) and `tenant` (maps user's tenant-aware entities)
- **Bundle config**: adds a `tenancy.database.enabled` flag (default: `false`) — when `true`, the bundle wires `DatabaseSwitchBootstrapper` into the DI container. This makes the driver opt-in (library consumers who don't use database-per-tenant don't get unnecessary DBAL dependencies).
- **Documentation**: Phase 9 (OSS hardening) ships a `doctrine.yaml` snippet in the README and Flex recipe stub.

### Connection switch trigger — bootstrapper pattern
- **`DatabaseSwitchBootstrapper` implements `TenantBootstrapperInterface`** — fits directly into the bootstrapper chain established in Phase 1. No new event listener.
- **`boot(TenantInterface $tenant)`**: calls `TenantConnection::switchTenant($tenant->getConnectionConfig())` — replaces DBAL connection params and reconnects.
- **`clear()`**: calls `TenantConnection::reset()` — restores connection to initial (landlord-equivalent) state.
- **Ordering**: `DatabaseSwitchBootstrapper` has no explicit priority — it runs first in the chain (Phase 3 is the only bootstrapper until Phase 5). Phase 5 (cache bootstrapper) registers independently.

### EntityManager reset on context clear
- **Trigger**: `TenantContextOrchestrator::onKernelTerminate()` already calls `BootstrapperChain::clear()` — `DatabaseSwitchBootstrapper::clear()` fires there.
- **EM reset**: a dedicated `EntityManagerResetListener` listens on `TenantContextCleared` event and calls `$managerRegistry->resetManager('tenant')` — this closes the EM and replaces it with a fresh instance, preventing identity map pollution across requests.
- **Why `resetManager()` and not just `clear()`**: `EntityManager::clear()` only clears the identity map; `resetManager()` closes and recreates the EM, which also ensures the connection state is clean. Success criteria (ISOL-01 item 4) requires `resetManager()`.
- **`landlord` EM**: never reset — it holds long-lived `Tenant` entity objects that should be cached across requests.

### TenantConnection — DBAL 4 wrapperClass implementation
- **Extends `Doctrine\DBAL\Connection`** — the `wrapperClass` mechanism in DBAL 4 requires a subclass of `Connection` (not a wrapper interface).
- **`switchTenant(array $connectionConfig)`**: merges $connectionConfig over `$this->getParams()`, then reconnects:
  ```php
  $this->close();
  // Update internal params via reflection or DSN rebuild — researcher must verify the DBAL 4 API
  $this->connect();
  ```
- **Thread/request safety**: `switchTenant()` is called once per request in `kernel.request` context — no concurrent access concern in standard Symfony FPM/RoadRunner (one request per process). Async (Symfony Runtime, Swoole) is out of scope for Phase 3.
- **Known risk**: DBAL 4 internal param mutation API is underdocumented (noted in STATE.md blockers) — researcher MUST investigate `mapeveri/tenancy-bundle` and `facile-it/doctrine-mysql-come-back` to find the correct non-deprecated param-update path in DBAL 4.

### DoctrineTenantProvider rewiring (Phase 2 debt)
- Phase 2 wired `DoctrineTenantProvider` to `doctrine.orm.default_entity_manager`. Phase 3 rewires it to the named `landlord` entity manager (`doctrine.orm.landlord_entity_manager`).
- This is a `config/services.php` change: update `service('doctrine.orm.default_entity_manager')` → `service('doctrine.orm.landlord_entity_manager')`.

### Claude's Discretion
- Exact DBAL 4 API call for updating connection params inside `TenantConnection::switchTenant()` — researcher must determine from source inspection
- Whether `TenantConnection` stores a "reset params" snapshot at construction or derives it at runtime from the base connection's initial params
- Internal service IDs for `TenantConnection`, `DatabaseSwitchBootstrapper`, `EntityManagerResetListener`
- SQLite in-memory approach for integration tests (one in-memory DB per tenant slug)

</decisions>

<specifics>
## Specific Ideas

- The `connectionConfig` merge-over-landlord-defaults pattern means a minimal tenant row only needs `{"dbname": "tenant_acme"}` if host/user/password are shared — makes tenant provisioning simpler in homogeneous DB fleet scenarios.
- `DatabaseSwitchBootstrapper` should be a `final` class, consistent with Phase 1/2's use of `final` everywhere.
- Integration tests (Plan 03-05): use two separate SQLite in-memory databases (`:memory:` won't work for two connections — use `sqlite:///tmp/test_tenant_a.db` and `sqlite:///tmp/test_tenant_b.db` with teardown). Researcher to confirm best approach.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 & 2 output (direct integration surface)
- `src/TenancyBundle.php` — add `tenancy.database.enabled` config node; register `DatabaseSwitchBootstrapper`
- `src/Bootstrapper/TenantBootstrapperInterface.php` — contract `DatabaseSwitchBootstrapper` implements
- `src/Bootstrapper/BootstrapperChain.php` — `boot()` and `clear()` lifecycle; `DatabaseSwitchBootstrapper` plugs in here
- `src/Event/TenantContextCleared.php` — event `EntityManagerResetListener` subscribes to
- `src/EventListener/TenantContextOrchestrator.php` — teardown flow: `clear()` → `TenantContextCleared` dispatch
- `src/Provider/DoctrineTenantProvider.php` — needs rewiring from `default_entity_manager` → `landlord_entity_manager`
- `src/Entity/Tenant.php` — `getConnectionConfig(): array` — the source of connection params passed to `switchTenant()`
- `config/services.php` — existing DI wiring; new services added here

### Project requirements
- `.planning/REQUIREMENTS.md` — ISOL-01, ISOL-02 (the two requirements for this phase)
- `.planning/PROJECT.md` — extensibility constraint, optional dependency model (Doctrine is suggested, not required)

### Prior decisions
- `.planning/STATE.md` — "Phase 3: DBAL 4 wrapperClass underdocumented" blocker (researcher must resolve this)
- `.planning/phases/02-tenant-resolution/02-CONTEXT.md` — TenantProviderInterface rewiring note

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Patterns from Phase 1 & 2
- `BootstrapperChainPass` → `DatabaseSwitchBootstrapper` registers via autoconfiguration (same `tenancy.bootstrapper` tag pattern)
- `final class` convention — all Phase 1/2 classes are `final`; maintain this
- `#[AsEventListener]` attribute — use for `EntityManagerResetListener` (consistent with `TenantContextOrchestrator`)
- `tests/Integration/Support/` — `NullTenantProvider`, `MakeResolverChainPublicPass` patterns for test isolation; Phase 3 integration tests will extend this

### Integration Points
- `src/TenancyBundle.php` → `configure()` add `database.enabled` node; `loadExtension()` conditionally register `DatabaseSwitchBootstrapper`
- `config/services.php` → add `TenantConnection`, `DatabaseSwitchBootstrapper`, `EntityManagerResetListener`; update `DoctrineTenantProvider` EM reference
- `tests/Integration/TestKernel.php` → extend for dual-EM integration test kernel

### What Phase 4 will need from Phase 3
- The `tenant` named EM — Phase 4 (shared-DB SQL filter) filters queries on this EM
- The `landlord` named EM — Phase 4 registers the filter on `tenant` EM only, not landlord
- `DatabaseSwitchBootstrapper::boot()` ordering — Phase 5 bootstrappers run after this

</code_context>

<deferred>
## Deferred Ideas

- **Async/coroutine safety** (Swoole, Symfony Runtime) — connection sharing in async contexts requires a connection pool or per-coroutine connection. Out of scope for Phase 3; document as a known limitation.
- **Connection health check / reconnect on lost connection** — DBAL has `detectSchemaChangesAndMigrate()` patterns; not needed for Phase 3.
- **`TenantProviderInterface::findAll()`** — needed for Phase 7 batch CLI commands; noted but not implemented here.
- **Multiple tenant connections** (e.g., read replica per tenant) — v2+ concern; Phase 3 supports one connection per tenant.

</deferred>

---

*Phase: 03-database-per-tenant-driver*
*Context gathered: 2026-03-18*
