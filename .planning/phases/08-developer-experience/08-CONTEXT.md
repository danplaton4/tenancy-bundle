# Phase 8: Developer Experience — Context

<decisions>

## 1. `initializeTenant($id)` — Parameter Type

**Decision:** Accept a slug string only.

```php
$this->initializeTenant('acme');
// Trait constructs a minimal in-memory Tenant entity from the slug.
// Zero setup required by the caller.
```

- Trait builds a synthetic `Tenant` entity (slug='acme', sane defaults for connection config) without touching any repository or database lookup.
- Downstream: planner should create the minimal factory method inside the trait itself — no external fixture factory needed.

---

## 2. DB Isolation Strategy — Fresh In-Memory SQLite Per Call

**Decision:** Each `initializeTenant('slug')` call creates a fresh `:memory:` SQLite connection for that slug and runs `SchemaTool::createSchema()` on it.

Full sequence that `initializeTenant()` must perform:
1. `$tenantContext->clear()` — wipe any prior tenant from context
2. Swap the EM connection parameters → new `:memory:` SQLite DSN (unique per call)
3. `SchemaTool::createSchema($em->getMetadataFactory()->getAllMetadata())` — create schema
4. `$tenantContext->setTenant($tenant)` — activate synthetic tenant
5. Run all registered bootstrappers via `BootstrapperChain`

**Why in-memory:** No temp files to clean up, zero cross-test leakage, fastest possible teardown. Two methods calling `initializeTenant()` with different slugs get completely independent databases — the requirement for no shared DB/cache state is satisfied automatically.

---

## 3. Base Class Scope — KernelTestCase Only (v1)

**Decision:** Trait targets `KernelTestCase` only for v1. `WebTestCase` support is explicitly deferred to a later phase.

```php
use Tenancy\Bundle\Testing\InteractsWithTenancy;

class MyTest extends KernelTestCase
{
    use InteractsWithTenancy;
}
```

- The trait should declare a `@requires` or docblock note that it expects `self::$kernel` to be available (KernelTestCase contract).
- WebTestCase (HTTP client + kernel restart semantics) adds non-trivial complexity around client state reset — defer to v1.1.

---

## 4. Kernel Lifecycle — Shared Kernel, Reset Context Per Method

**Decision:** Kernel boots once per class (the KernelTestCase default). `initializeTenant()` resets TenantContext + swaps the EM connection + rebuilds schema. `tearDown()` always calls `clearTenant()`.

- Do **not** reboot the kernel between test methods — aligns with the existing integration test pattern (`setUpBeforeClass` with a static kernel).
- `clearTenant()` must be called in `tearDown()` even when `setUp()` or the test method throws (PHPUnit always runs `tearDown()` after an exception).

---

## 5. DX Helpers — Assertion Helpers + Service Accessor

**Decision:** Include both categories beyond the core `initializeTenant`/`clearTenant`/`tearDown`:

### Assertion helpers
```php
$this->assertTenantActive('acme');  // asserts TenantContext has tenant with slug='acme'
$this->assertNoTenant();             // asserts TenantContext is empty
```

### Service accessor
```php
$service = $this->getTenantService(SomeService::class);
// Fetches service from the test container with tenant context already active.
// Saves callers from: static::getContainer()->get(SomeService::class)
```

Both are convenience wrappers over existing services — no new architecture needed.

---

## Trait Location

**Decision:** `src/Testing/InteractsWithTenancy.php` — production source tree so it ships in the Packagist package and downstream projects can `use` it without copying files.

</decisions>

<canonical_refs>
- `.planning/REQUIREMENTS.md` — DX-01 requirement definition
- `.planning/ROADMAP.md` §Phase 8 — success criteria (initializeTenant, tearDown on throw, two-method isolation)
- `src/Context/TenantContext.php` — context service being set/cleared by the trait
- `src/Bootstrapper/TenantBootstrapperInterface.php` — interface for bootstrappers the trait must invoke
- `src/Bootstrapper/DoctrineBootstrapper.php` — example bootstrapper; boot() calls $em->clear()
- `src/TenantInterface.php` — interface the synthetic Tenant entity must satisfy
- `src/Entity/Tenant.php` — concrete Tenant entity to use (or extend/mimic) for the synthetic instance
- `tests/Integration/TestKernel.php` — existing kernel pattern; trait reuses same boot/container approach
- `tests/Integration/DoctrineBootstrapperIntegrationTest.php` — existing DB isolation pattern (SchemaTool usage)
</canonical_refs>

<code_context>
## Existing Assets the Trait Reuses

- `TenantContext` (`src/Context/TenantContext.php`) — `setTenant()`, `clear()`, `hasTenant()`
- `BootstrapperChain` (via DI tag `tenancy.bootstrapper`) — trait calls `boot()` after context is set
- `DoctrineBootstrapper` — already calls `$em->clear()` in `boot()`; no changes needed
- `SchemaTool` (`Doctrine\ORM\Tools\SchemaTool`) — used in existing integration tests to create schema; same pattern applies
- `Tenant` entity — can be instantiated directly for the synthetic tenant; planner should check if constructor is flexible enough or if a `TenantStub` helper class is needed
- `TestKernel` + `KernelTestCase` static kernel pattern — established integration test convention; trait follows the same lifecycle

## Integration Points
- `config/services.php` — `TenantContext` and `BootstrapperChain` are already registered; trait fetches them via `static::getContainer()`
- No new DI registrations required for the trait itself (it's a test-only PHP trait, not a service)
</code_context>

<deferred>
## Deferred Ideas

- `WebTestCase` support — HTTP client + kernel restart semantics; defer to v1.1
- `initializeTenant()` accepting `TenantInterface` object — slug string covers all v1 use cases
- Per-test kernel reboot option — flag like `$this->isolationMode = 'full'`; not needed when in-memory SQLite is the isolation mechanism
</deferred>

---

*Phase: 08-developer-experience*
*Context gathered: 2026-04-02*