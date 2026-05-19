# Phase 8: Developer Experience — Research

**Researched:** 2026-04-02
**Domain:** PHPUnit trait for KernelTestCase; in-memory SQLite schema creation; Doctrine SchemaTool; TenantContext/BootstrapperChain wiring
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

1. **`initializeTenant($id)` — Parameter Type:** Accept a slug string only.
   - Trait builds a synthetic `Tenant` entity (slug=value, sane defaults) without any repository or DB lookup.
   - No external fixture factory — the factory method lives inside the trait itself.

2. **DB Isolation Strategy — Fresh In-Memory SQLite Per Call:**
   Full sequence `initializeTenant()` must perform:
   1. `$tenantContext->clear()` — wipe any prior tenant from context
   2. Swap the EM connection parameters → new `:memory:` SQLite DSN (unique per call)
   3. `SchemaTool::createSchema($em->getMetadataFactory()->getAllMetadata())` — create schema
   4. `$tenantContext->setTenant($tenant)` — activate synthetic tenant
   5. Run all registered bootstrappers via `BootstrapperChain`

3. **Base Class Scope — KernelTestCase Only (v1):** `WebTestCase` support explicitly deferred.

4. **Kernel Lifecycle — Shared Kernel, Reset Context Per Method:**
   - Kernel boots once per class (KernelTestCase default).
   - `initializeTenant()` resets TenantContext + swaps EM connection + rebuilds schema.
   - `tearDown()` always calls `clearTenant()` — PHPUnit guarantees tearDown runs even when setUp/test throws.
   - Do NOT reboot the kernel between test methods.

5. **DX Helpers — Assertion Helpers + Service Accessor:**
   ```php
   $this->assertTenantActive('acme');
   $this->assertNoTenant();
   $service = $this->getTenantService(SomeService::class);
   ```

6. **Trait Location:** `src/Testing/InteractsWithTenancy.php` — production source tree, ships in Packagist package.

### Claude's Discretion

None explicitly stated — all areas with decisions are locked.

### Deferred Ideas (OUT OF SCOPE)

- `WebTestCase` support — HTTP client + kernel restart semantics; defer to v1.1
- `initializeTenant()` accepting `TenantInterface` object — slug string covers all v1 use cases
- Per-test kernel reboot option — flag like `$this->isolationMode = 'full'`; not needed when in-memory SQLite is the isolation mechanism
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| DX-01 | `InteractsWithTenancy` PHPUnit trait for `KernelTestCase` provides `$this->initializeTenant($id)` which sets up a clean tenant DB/schema and boots the tenant context for each test method | Covered by SchemaTool pattern (already used in `DoctrineBootstrapperIntegrationTest`), TenantContext API (`setTenant`, `clear`, `hasTenant`), BootstrapperChain API (`boot`, `clear`), and Tenant entity constructor (`new Tenant($slug, $name)`) |
</phase_requirements>

---

## Summary

Phase 8 delivers a single PHP trait, `InteractsWithTenancy`, that lives in `src/Testing/` and ships as part of the Packagist package. All infrastructure the trait needs already exists and is proven in prior integration tests: `TenantContext` (`setTenant`/`clear`/`hasTenant`), `BootstrapperChain` (`boot`/`clear`), `SchemaTool::createSchema()`, and the `Tenant` entity (direct constructor `new Tenant($slug, $name)` with no lifecycle callback requirements).

The DB isolation strategy is fully resolved: each `initializeTenant()` call creates a fresh `:memory:` SQLite database. This approach is already battle-tested in the project — `DoctrineBootstrapperIntegrationTest` and `DatabaseSwitchIntegrationTest` both use `SchemaTool::createSchema()` to build schema on demand. The in-memory variant eliminates cleanup and guarantees per-call isolation. The only new technical question is how to swap the DBAL connection parameters at runtime to point at a fresh `:memory:` DSN, which is already solved by `TenantConnection::switchTenant()` from Phase 3.

The trait requires no new DI registrations — it retrieves `TenantContext` and `BootstrapperChain` via `static::getContainer()` (the standard KernelTestCase accessor). The planner's job is straightforward: one implementation plan for the trait itself, and one test plan that verifies all three DX-01 success criteria.

**Primary recommendation:** Build `InteractsWithTenancy` as a thin orchestration wrapper over existing services using the exact same SchemaTool + TenantContext + BootstrapperChain sequence already proven in Phase 5 integration tests.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `phpunit/phpunit` | ^11.0 (already in `require-dev`) | Test framework providing `KernelTestCase` base | Already the project's test framework |
| `symfony/framework-bundle` | ^6.4\|^7.0 (already in `require-dev`) | Provides `KernelTestCase` and `static::getContainer()` | Standard Symfony testing infrastructure |
| `doctrine/orm` | ^3.3 (already in `require-dev`) | Provides `SchemaTool`, `EntityManagerInterface` | Already used in all integration tests |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Doctrine\ORM\Tools\SchemaTool` | (part of doctrine/orm) | Creates schema in `:memory:` SQLite per `initializeTenant()` call | Core isolation mechanism |
| `Tenancy\Bundle\Context\TenantContext` | (bundle) | Set/clear active tenant | Retrieved via `static::getContainer()->get('tenancy.context')` |
| `Tenancy\Bundle\Bootstrapper\BootstrapperChain` | (bundle) | Run registered bootstrappers after context activation | Retrieved via `static::getContainer()->get('tenancy.bootstrapper_chain')` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `:memory:` SQLite per call | File-based SQLite with unlink in tearDown | In-memory is faster, zero cleanup, zero cross-test leakage |
| Direct `Tenant` entity construction | Anonymous class implementing `TenantInterface` | Direct construction is simpler; existing project already uses anonymous stub class pattern but the locked decision says use `Tenant` entity or check if constructor is sufficient |

**Installation:** No new packages needed. All dependencies already present in `composer.json`.

---

## Architecture Patterns

### Recommended Project Structure
```
src/
└── Testing/
    └── InteractsWithTenancy.php    # PHPUnit trait; ships in Packagist package

tests/
└── Integration/
    └── Testing/
        └── InteractsWithTenancyTest.php    # Integration test for the trait itself
```

### Pattern 1: The InteractsWithTenancy Trait
**What:** A PHP trait that mixes into `KernelTestCase` subclasses. Exposes `initializeTenant(string $slug)`, `clearTenant()`, `assertTenantActive(string $slug)`, `assertNoTenant()`, and `getTenantService(string $class)`.

**When to use:** Any PHPUnit integration test that needs to exercise code under a live tenant context with full DB schema.

**Key implementation details:**
- `tearDown()` override calls `clearTenant()` unconditionally — PHPUnit always calls `tearDown()` even on exception.
- `initializeTenant()` must call `$tenantContext->clear()` first (idempotent if called multiple times in one test method).
- The synthetic tenant is built by constructing `new Tenant($slug, $slug)` directly — the constructor signature is `__construct(string $slug, string $name)`. No `onPrePersist` required since the entity is never persisted.
- EM connection swap: use `TenantConnection::switchTenant(['driver' => 'pdo_sqlite', 'path' => ':memory:'])` — same call used in `DatabaseSwitchIntegrationTest`. Each call to `initializeTenant()` passes a unique DSN; SQLite `:memory:` creates a new database per connection.

**Example (conceptual — do not copy verbatim; planner will expand):**
```php
// Source: inferred from existing integration tests
// tests/Integration/DatabaseSwitchIntegrationTest.php and
// tests/Integration/DoctrineBootstrapperIntegrationTest.php

trait InteractsWithTenancy
{
    private function initializeTenant(string $slug): void
    {
        $container = static::getContainer();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();

        // Swap to fresh :memory: SQLite
        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => ':memory:']);

        // Rebuild schema on the fresh connection
        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $em = $registry->resetManager('tenant');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Activate tenant context
        $tenant = new Tenant($slug, $slug);
        $tenantContext->setTenant($tenant);

        // Run bootstrappers
        /** @var BootstrapperChain $chain */
        $chain = $container->get('tenancy.bootstrapper_chain');
        $chain->boot($tenant);
    }

    protected function tearDown(): void
    {
        $this->clearTenant();
        parent::tearDown();
    }

    private function clearTenant(): void
    {
        $container = static::getContainer();
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();

        $chain = $container->get('tenancy.bootstrapper_chain');
        $chain->clear();
    }
}
```

**CRITICAL NOTE on driver mode:** The trait must work in both `shared_db` mode (single EM `default`) and `database` mode (dual EMs `tenant` + `landlord`). The connection swap via `TenantConnection::switchTenant()` only applies when `database.enabled = true`. In `shared_db` mode, there is no `TenantConnection` — the isolation is via the SQL filter, not a separate connection. The planner must decide how `initializeTenant()` handles this branching. Options:
  1. Check whether `doctrine.dbal.tenant_connection` exists in the container — if yes, database mode; if no, shared_db mode.
  2. Require callers to use a testing kernel that always uses database mode (simplest for the trait).
  3. The CONTEXT.md locked sequence explicitly lists "Swap the EM connection parameters → new `:memory:` SQLite DSN" as step 2 — implying database mode is the expected test setup. The planner should note the trait's TestKernel must enable `database.enabled: true`.

### Pattern 2: Synthetic Tenant Construction
**What:** `Tenant` entity is instantiated directly without persisting — no DB write needed for the context-holder.

**Key fact:** `Tenant::__construct(string $slug, string $name)` initializes `$slug` and `$name`. The `$createdAt` / `$updatedAt` fields are set by `#[ORM\PrePersist]` / `#[ORM\PreUpdate]` lifecycle callbacks — they will be `null` if the entity is never persisted. This is safe for the testing trait since the entity is used only as a context holder, never queried from an EM.

**Alternative already in codebase:** The inline anonymous class pattern (`new class($slug) implements TenantInterface`) is used throughout existing tests. However, the locked decision says "Trait builds a synthetic `Tenant` entity (slug='acme', sane defaults for connection config)" — check if `Tenant` constructor is sufficient or if a dedicated `TenantStub` inner class is cleaner. The planner should choose `new Tenant($slug, $slug)` (name = slug) for simplicity, noting `connectionConfig = []` by default.

### Pattern 3: tearDown Safety
**What:** PHPUnit always runs `tearDown()` even when `setUp()` or the test method throws.

**Key fact:** The trait's `tearDown()` override must call `parent::tearDown()` to preserve KernelTestCase's kernel lifecycle. The `clearTenant()` call must happen before `parent::tearDown()` to ensure cleanup runs while the container is still available.

### Anti-Patterns to Avoid
- **Rebooting the kernel per test method:** Aligns with locked decision — one boot per class via `setUpBeforeClass`.
- **Using file-based SQLite in the trait's isolation:** Use `:memory:` not file paths; files require `unlink()` in tearDown and create cross-test pollution risk.
- **Calling `$chain->clear()` without calling `$tenantContext->clear()` first:** Both must be called; `clearTenant()` should clear context AND call chain clear.
- **Assuming `tenancy.bootstrapper_chain` is public:** It is registered as `->public(false)` in `config/services.php`. Tests that use the trait need a test kernel with a `MakeServicesPublicPass` or the services exposed differently. The trait calls `static::getContainer()` which in Symfony tests returns the **test container** (all services accessible). This is the same mechanism used by all existing integration tests.
- **Calling `getTenantService()` before `initializeTenant()`:** The helper is a convenience wrapper; it doesn't auto-initialize. Document this clearly.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Schema creation for :memory: SQLite | Custom DDL generation | `Doctrine\ORM\Tools\SchemaTool::createSchema()` | Already used in 4 integration test suites; handles all ORM-mapped entities including custom test entities |
| Tenant context set/clear | Direct property mutation | `TenantContext::setTenant()` / `::clear()` | Thread-safe, event-driven, the canonical API |
| Bootstrapper invocation | Direct bootstrapper calls | `BootstrapperChain::boot()` / `::clear()` | Ensures all registered bootstrappers run in correct priority order; dispatches `TenantBootstrapped` event |
| Connection swap | Custom DBAL connection rebuild | `TenantConnection::switchTenant(['driver' => 'pdo_sqlite', 'path' => ':memory:'])` | Handles ReflectionProperty params merge + lazy reconnect; same call used in `DatabaseSwitchIntegrationTest` |

**Key insight:** This phase is purely orchestration — every primitive already exists. The trait is glue code, not new infrastructure.

---

## Common Pitfalls

### Pitfall 1: `static::getContainer()` vs `$this->getContainer()`
**What goes wrong:** `static::getContainer()` is the Symfony 5.3+ KernelTestCase API. `$this->getContainer()` does not exist in PHPUnit's TestCase. Calling `static::getContainer()` before the kernel boots throws.
**Why it happens:** The trait's methods are called instance-methods but access static kernel state.
**How to avoid:** Always call `static::getContainer()` (not `parent::getContainer()` or `$this->getContainer()`). The kernel must be booted before `initializeTenant()` is called — enforced by the KernelTestCase lifecycle (kernel boots in `setUp()` or `setUpBeforeClass()`).
**Warning signs:** `LogicException: The kernel is not yet booted` or `Call to undefined method`.

### Pitfall 2: Bootstrapper Chain Services Not Public in Test Container
**What goes wrong:** `tenancy.bootstrapper_chain` is registered as `->public(false)`. Calling `$container->get('tenancy.bootstrapper_chain')` from a test throws `ServiceNotFoundException`.
**Why it happens:** Symfony test container exposes private services via `$this->getContainer()` only when `framework.test: true` is set. All existing integration test kernels set `'test' => true` — this is the Symfony mechanism that makes all services available in test containers.
**How to avoid:** The test kernel used with `InteractsWithTenancy` must include `'test' => true` in its framework config. This is already done in every existing test kernel (TestKernel, BootstrapperTestKernel, etc.).
**Warning signs:** `ServiceNotFoundException` for `tenancy.context` or `tenancy.bootstrapper_chain`.

### Pitfall 3: TenantConnection Absent in shared_db Mode
**What goes wrong:** `TenantConnection` and `doctrine.dbal.tenant_connection` are only registered when `database.enabled: true`. The trait's connection-swap step will throw if the test kernel uses `shared_db` mode.
**Why it happens:** The locked decision sequence explicitly includes "Swap the EM connection parameters" — this step only applies to database-per-tenant mode.
**How to avoid:** The test kernel used with the trait must use database mode (`database.enabled: true`). The planner should create a `TenancyTestKernel` (or document that the trait requires a database-mode kernel). The trait should either guard with `$container->has('doctrine.dbal.tenant_connection')` or document the requirement clearly.
**Warning signs:** `ServiceNotFoundException: doctrine.dbal.tenant_connection`.

### Pitfall 4: Doctrine Metadata Cache Tied to Old Connection After switchTenant
**What goes wrong:** After `conn->switchTenant()`, calling `$em->find()` may still use metadata cached against the old connection. Queries fail silently or use wrong schema.
**Why it happens:** DoctrineBundle wraps EMs in lazy proxies; metadata is cached at first use.
**How to avoid:** Call `$registry->resetManager('tenant')` after `switchTenant()` — same fix used in `DatabaseSwitchIntegrationTest`. This produces a fresh EM with no stale metadata.
**Warning signs:** `Table not found` errors after `initializeTenant()` when schema was created correctly.

### Pitfall 5: Tenant Entity Missing `createdAt` / `updatedAt`
**What goes wrong:** Some code may call `$tenant->getCreatedAt()` expecting a non-null value. The synthetic tenant is never persisted, so lifecycle callbacks never run.
**Why it happens:** `Tenant::$createdAt` is set by `#[ORM\PrePersist]` — it is uninitialized until persist.
**How to avoid:** The trait only needs the tenant as a context holder for `TenantContext::setTenant()`. `TenantInterface` only requires `getSlug()`, `getDomain()`, `getConnectionConfig()`, `getName()`, `isActive()` — no date fields. The `Tenant` entity satisfies all of these from the constructor. If `getCreatedAt()` would be called on the synthetic tenant, the planner should consider a `TenantStub` anonymous class instead (matching the existing `makeTenantStub()` pattern in tests).

---

## Code Examples

Verified patterns from existing project tests:

### SchemaTool Schema Creation (in-memory variant)
```php
// Source: tests/Integration/DatabaseSwitchIntegrationTest.php (adapted for :memory:)
$conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => ':memory:']);
$registry = $container->get('doctrine');
$em = $registry->resetManager('tenant');
$schemaTool = new SchemaTool($em);
$schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
```

### TenantContext Set/Clear
```php
// Source: src/Context/TenantContext.php
$tenantContext = $container->get('tenancy.context');  // or TenantContext::class alias
$tenantContext->clear();
$tenantContext->setTenant($tenant);
$tenantContext->hasTenant(); // returns bool
$tenantContext->getTenant(); // returns ?TenantInterface
```

### BootstrapperChain Boot/Clear
```php
// Source: src/Bootstrapper/BootstrapperChain.php
$chain = $container->get('tenancy.bootstrapper_chain');
$chain->boot($tenant);   // runs all bootstrappers forward, dispatches TenantBootstrapped
$chain->clear();          // runs all bootstrappers in reverse order
```

### Inline Tenant Stub (existing pattern from integration tests)
```php
// Source: tests/Integration/DoctrineBootstrapperIntegrationTest.php::makeTenantStub()
$tenant = new class ($slug) implements TenantInterface {
    public function __construct(private readonly string $slug) {}
    public function getSlug(): string { return $this->slug; }
    public function getDomain(): ?string { return null; }
    public function getConnectionConfig(): array { return []; }
    public function getName(): string { return $this->slug; }
    public function isActive(): bool { return true; }
};
```

### Direct Tenant Entity Construction
```php
// Source: src/Entity/Tenant.php — constructor: __construct(string $slug, string $name)
// Used in DatabaseSwitchIntegrationTest, DoctrineBootstrapperIntegrationTest
$tenant = new Tenant('acme', 'acme');  // name = slug for synthetic tenant
// connectionConfig defaults to [], domain defaults to null, isActive defaults to true
```

### tearDown Safety Pattern
```php
// Source: PHPUnit KernelTestCase contract — tearDown always runs
protected function tearDown(): void
{
    $this->clearTenant();   // must run before parent::tearDown() while container is live
    parent::tearDown();
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `$this->getContainer()` | `static::getContainer()` | Symfony 5.3 | Static method; works correctly in both setUp and test methods |
| `$kernel->getContainer()` | `static::getContainer()` | KernelTestCase API | Returns test container with all private services accessible when `test: true` |
| Manual `SchemaTool` in `setUpBeforeClass` | Per-`initializeTenant()` SchemaTool with `:memory:` | Phase 8 decision | Enables per-method isolation without file cleanup |

**Deprecated/outdated:**
- `$this->getContainer()` (Symfony <5.3): `static::getContainer()` is the correct API since Symfony 5.3 KernelTestCase.

---

## Open Questions

1. **shared_db vs database mode branching in `initializeTenant()`**
   - What we know: The locked sequence assumes `TenantConnection` is available (database-per-tenant mode). `shared_db` has no `TenantConnection`.
   - What's unclear: Should the trait silently skip the connection swap step if in shared_db mode, or should the planner require the test kernel to always use database mode?
   - Recommendation: Require database mode for the testing kernel. Document this in the trait's class-level docblock. This is simpler than runtime branching and aligns with the lock decision's explicit mention of connection swapping.

2. **`Tenant` entity vs anonymous stub for synthetic tenant**
   - What we know: `new Tenant($slug, $slug)` works — constructor only requires `slug` and `name`. `createdAt`/`updatedAt` remain null until persisted (never called on synthetic tenant).
   - What's unclear: Will any bootstrapper or downstream code call `getCreatedAt()` on the context tenant? If so, `Tenant` entity would throw a typed property access error.
   - Recommendation: Use `new Tenant($slug, $slug)` as the locked decision specifies. If this causes issues, fall back to the anonymous stub pattern documented above.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.0 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `./vendor/bin/phpunit --testsuite integration --filter InteractsWithTenancy` |
| Full suite command | `./vendor/bin/phpunit` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DX-01a | `initializeTenant('acme')` boots tenant context and schema | integration | `./vendor/bin/phpunit --filter testInitializeTenantBootsContextAndSchema` | Wave 0 |
| DX-01b | `tearDown()` clears tenant context even when test throws | integration | `./vendor/bin/phpunit --filter testTearDownClearsContextOnException` | Wave 0 |
| DX-01c | Two test methods with different slugs do not share DB/cache state | integration | `./vendor/bin/phpunit --filter testTwoMethodsGetIsolatedDatabases` | Wave 0 |
| DX-01d | `assertTenantActive('acme')` passes when context has that slug | integration | `./vendor/bin/phpunit --filter testAssertTenantActive` | Wave 0 |
| DX-01e | `assertNoTenant()` passes when context is empty | integration | `./vendor/bin/phpunit --filter testAssertNoTenant` | Wave 0 |
| DX-01f | `getTenantService(SomeService::class)` returns service from container | integration | `./vendor/bin/phpunit --filter testGetTenantService` | Wave 0 |

### Sampling Rate
- **Per task commit:** `./vendor/bin/phpunit --testsuite integration --filter InteractsWithTenancy`
- **Per wave merge:** `./vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Integration/Testing/InteractsWithTenancyTest.php` — covers DX-01a through DX-01f
- [ ] `tests/Integration/Testing/Support/TenancyTestKernel.php` — database-mode kernel for the trait test suite (extends existing kernel patterns with `database.enabled: true`)
- [ ] `tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php` — exposes `tenancy.context`, `tenancy.bootstrapper_chain`, `doctrine.dbal.tenant_connection` in test container

---

## Sources

### Primary (HIGH confidence)
- `src/Context/TenantContext.php` — API verified by direct read: `setTenant()`, `clear()`, `hasTenant()`, `getTenant()`
- `src/Bootstrapper/BootstrapperChain.php` — API verified: `boot()`, `clear()` (reverse order), dispatches `TenantBootstrapped`
- `src/Bootstrapper/TenantBootstrapperInterface.php` — interface: `boot(TenantInterface)`, `clear()`
- `src/Entity/Tenant.php` — constructor `__construct(string $slug, string $name)`, `connectionConfig = []` default, `isActive = true` default
- `src/TenantInterface.php` — interface contract (5 methods, no date fields)
- `tests/Integration/DoctrineBootstrapperIntegrationTest.php` — SchemaTool creation pattern, makeTenantStub pattern, setUpBeforeClass kernel lifecycle
- `tests/Integration/DatabaseSwitchIntegrationTest.php` — `switchTenant()` + `resetManager()` + `SchemaTool` pattern
- `tests/Integration/Support/BootstrapperTestKernel.php` — kernel pattern with DoctrineBundle + database config
- `config/services.php` — confirmed `tenancy.bootstrapper_chain` is `->public(false)`; `tenancy.context` is `->public()`
- `phpunit.xml.dist` — PHPUnit 11 config; `tests/Integration` is the integration suite directory
- `composer.json` — confirmed all required packages already in `require-dev`

### Secondary (MEDIUM confidence)
- Symfony KernelTestCase `static::getContainer()` API — consistent with Symfony docs since 5.3; all existing integration tests use `$container = static::$kernel->getContainer()` (direct kernel call rather than KernelTestCase method, since tests extend `TestCase` not `KernelTestCase`)
- PHPUnit `tearDown()` always-runs guarantee — documented PHPUnit behavior; consistent with MSG-03 Messenger middleware `try/finally` pattern already in codebase

### Tertiary (LOW confidence)
- None — all claims are grounded in direct code inspection.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; all existing
- Architecture: HIGH — direct code inspection of every referenced file
- Pitfalls: HIGH — derived from existing phase decisions and direct code inspection (e.g., `->public(false)` confirmed in services.php)

**Research date:** 2026-04-02
**Valid until:** 2026-05-02 (stable — no fast-moving external dependencies)
