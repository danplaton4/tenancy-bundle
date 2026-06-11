---
phase: 25-shared-entities-sync-mode
reviewed: 2026-06-11T00:00:00Z
depth: standard
files_reviewed: 19
files_reviewed_list:
  - src/Attribute/Shared.php
  - src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php
  - src/Exception/SharedEntityWriteInTenantContextException.php
  - src/Subscriber/SharedEntitySyncSubscriber.php
  - src/Subscriber/SharedEntityWriteProtectionListener.php
  - src/TenancyBundle.php
  - docs/user-guide/shared-db.md
  - tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php
  - tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php
  - tests/Integration/SharedEntity/Support/Entity/TestPlan.php
  - tests/Integration/SharedEntity/Support/Entity/TestPlanCategory.php
  - tests/Integration/SharedEntity/Support/Entity/TestPlanWithAssociation.php
  - tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php
  - tests/Integration/SharedEntity/Support/ReplaceWithStubMultiTenantProviderPass.php
  - tests/Integration/SharedEntity/Support/SharedEntityNoDbTestKernel.php
  - tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php
  - tests/Integration/SharedEntity/Support/StubMultiTenantProvider.php
  - tests/Unit/Attribute/SharedTest.php
  - tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php
findings:
  critical: 3
  warning: 6
  info: 4
  total: 13
criticals_resolved: 3
resolution: "CR-01/CR-02/CR-03 + WR-02 fixed in post-review hardening (full suite 706 green, PHPStan L9 clean, new regression test testFanOutRestoresActiveTenantContext). Remaining 5 warnings + 4 info are advisory follow-ups — see Resolution Addendum."
status: criticals_resolved
---

# Phase 25: Code Review Report

**Reviewed:** 2026-06-11
**Depth:** standard
**Files Reviewed:** 19
**Status:** criticals_resolved

## Resolution Addendum (post-review hardening)

All 3 Critical findings were verified against the source and fixed in `SharedEntitySyncSubscriber`
before phase completion:

- **CR-01 (context wipe) — FIXED.** `postFlush()` now captures the pre-fan-out tenant and restores
  it (or clears, if none) in a `finally` via the new `restoreTenantContext()`; `fanOutToTenant()`
  no longer clears the context per iteration.
- **CR-02 (stale tenant connection) — FIXED.** `restoreTenantContext()` closes the tenant DBAL
  connection and resets the tenant manager after the loop, so the next query reconnects under the
  restored context instead of the last fanned-out tenant's DB.
- **CR-03 (silent divergence) — FIXED.** The best-effort `catch` now logs at `error` level (the
  best-effort fan-out itself is the locked D-01 decision; the under-signalled log level was the bug).
- **WR-02 (re-entrancy flag) — FIXED (bonus).** `$syncInProgress` is now reset in a `finally`.

Coverage gap closed: new integration test `testFanOutRestoresActiveTenantContext` exercises a
landlord `#[Shared]` flush while a tenant is active (the path WR-03/IN-04 left unexercised).
Full suite 706 tests / 0 failures / 1 skip; PHPStan level 9 clean; cs-fixer clean.

**Remaining (advisory, not blocking phase completion):** WR-03 (shared_db no-op test passes
trivially — subscriber not registered under shared_db), WR-05 (sync flag not EM-scoped),
WR-06 (mutual-exclusion pass misses parent/mapped-superclass attributes), plus the other warnings
and 4 info items below. Recommend addressing via `/gsd:code-review 25 --fix` or a follow-up.

## Summary

This phase introduces the `#[Shared]` landlord-to-tenant fan-out machinery: a sync subscriber
(`SharedEntitySyncSubscriber`) that buffers `#[Shared]` changesets in `onFlush` and replays them
into every tenant EM in `postFlush`, plus a tenant-side write-protection listener and a
compile-time mutual-exclusion guard.

The two Doctrine subscribers are the highest-risk surface and that is where the most serious
defects are. The dominant concern is the **`finally`-clear contract**: `fanOutToTenant()`
unconditionally calls `TenantContext::clear()`, which destroys any tenant context that was active
*before* the fan-out started. Combined with a non-reentrant `bool $syncInProgress` flag and a
DBAL connection that is force-closed but never restored, a landlord flush that happens while a
tenant is already active will silently corrupt tenant isolation for the remainder of the request
— a data-leak class of bug, exactly what `strict_mode` is supposed to prevent.

Secondary concerns: the `getScheduledEntityUpdates()` buffer captures every scheduled update,
not just entities whose `#[Shared]`-relevant fields changed; the best-effort `catch (\Throwable)`
swallows tenant-EM corruption after a partial flush; and the `shared_db` no-op test does not
actually exercise the production short-circuit it claims to cover (the subscriber is never
registered under `shared_db`).

## Critical Issues

### CR-01: `fanOutToTenant` `finally` block destroys a pre-existing active tenant context

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:198-228`
**Issue:**
`fanOutToTenant()` does `setTenant($tenant)` at the top and `clear()` in `finally`. It never
saves and restores the tenant that may have been active when the landlord flush occurred. The
subscriber is wired on the **landlord** connection, but nothing prevents a request that is
*already in tenant context* from flushing the landlord EM (landlord and tenant EMs coexist in
`database_per_tenant` mode — both are always booted). Consider:

1. Request resolves tenant "acme" → `TenantContext` holds "acme".
2. Application code (or a bootstrapper, or a service) writes a `#[Shared]` entity via the
   **landlord** EM and flushes.
3. `postFlush` → `fanOutToTenant()` runs for tenant_a, tenant_b → `finally` runs
   `clear()` twice.
4. Control returns to the original request. `TenantContext` is now **empty** — "acme" is gone.
5. Every subsequent tenant query in that request either throws `TenantMissingException`
   (strict_mode on) or, worse with strict_mode off, **runs unscoped / against the landlord
   placeholder DB** — a cross-tenant data leak.

The class docblock claims `finally` clears context "no stale context (Pitfall 4)", but blindly
clearing is itself the stale-context bug when the pre-flush state was non-empty. CLAUDE.md is
explicit: "a data leak across tenants is a security incident."

**Fix:** Snapshot and restore the prior tenant instead of clearing unconditionally:
```php
private function fanOutToTenant(/* ... */): void
{
    $previousTenant = $this->tenantContext->getTenant();
    try {
        $this->tenantContext->setTenant($tenant);
        // ... close connection, resetManager, doSync ...
    } catch (\Throwable $e) {
        // ... log ...
    } finally {
        if (null !== $previousTenant) {
            $this->tenantContext->setTenant($previousTenant);
        } else {
            $this->tenantContext->clear();
        }
        // Force the next reconnect to pick up the restored (or absent) tenant params
        $conn = $this->registry->getConnection('tenant');
        if ($conn instanceof \Doctrine\DBAL\Connection) {
            $conn->close();
        }
    }
}
```

### CR-02: tenant DBAL connection left pointed at the last tenant after fan-out (stale socket)

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:201-228`
**Issue:**
`fanOutToTenant()` calls `$tenantConn->close()` *before* switching tenants so the next
`connect()` picks up the new tenant params via `TenantAwareDriver`. But after the last iteration
of the fan-out loop, the tenant connection is left **open against the last tenant's DB**
(tenant_b), and the `tenant` EM has been reset to that tenant. The `finally` clears the context
but does **not** close the connection again. The next time anything in the original request uses
the `tenant` connection/EM, `TenantAwareDriver::connect()` is **not re-entered** (DBAL only calls
`connect()` when the internal handle is null — see `TenantAwareDriver` docblock and
`src/DBAL/TenantAwareDriver.php:41`), so queries silently execute against **tenant_b's database**
regardless of the actual active tenant. This is a direct cross-tenant read/write leak.

This compounds CR-01: even if the context is correctly restored, the physical socket is wrong.
Any restore of context must be paired with a `close()` to force the lazy reconnect.

**Fix:** Always `close()` the tenant connection in `finally` (see CR-01 fix snippet) so the next
query reconnects with the restored tenant's params. A connection left open against an arbitrary
tenant DB after the subscriber runs is never safe.

### CR-03: best-effort `catch` swallows failures that leave a tenant EM in a corrupt/half-flushed state

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:215-225`, `src/Subscriber/SharedEntitySyncSubscriber.php:282-283`
**Issue:**
`doSync()` calls `$tenantEm->persist($copy); $tenantEm->flush();`. If `flush()` throws *after*
having partially executed (e.g., a constraint violation, a closed connection mid-transaction, or
a DBAL deadlock), Doctrine **closes the EntityManager** and the `catch (\Throwable)` block in
`fanOutToTenant()` only logs a `warning` and moves on. The class then proceeds to the next change
in the `postFlush` loop reusing the **same now-closed `tenant` manager** for the same tenant on
the next buffered change — `resetManager('tenant')` is only called once per (change × tenant), so
within a single change the EM is fresh, but the deeper problem is that the failure is reported as
a `warning`-level log line with no durable record and no signal to the operator that a specific
tenant is now **out of sync** with the landlord master. The landlord transaction has already
committed (we are in `postFlush`), so the master and that tenant have permanently diverged with
only a log line as evidence. For a "read-only mirror of master data" feature, silent divergence
is a data-integrity defect, not a transient fault.

The docblock frames this as intended ("D-01 best-effort — never abort the landlord
transaction"), and not aborting the landlord is reasonable. But `warning` severity for permanent
master/replica divergence under-signals the impact, and there is no dead-letter / retry / repair
path. At minimum this should be `error` level with enough structured context to drive a repair,
and the divergence risk must be documented as an operational hazard.

**Fix:**
- Raise the log level to `error` (permanent divergence is not a warning):
```php
$this->logger->error('tenancy.shared_entity_sync_failed', [
    'tenant_slug' => $tenant->getSlug(),
    'entity_class' => $entity::class,
    'identifier' => $identifier,
    'type' => $type,            // include the operation that diverged
    'error' => $e->getMessage(),
    'exception' => $e,          // PSR-3 'exception' key for full trace
]);
```
- Document (and ideally provide) a re-sync/repair command so an operator can reconcile a tenant
  that failed mid-fan-out. Track this as a follow-up if out of phase scope.

## Warnings

### WR-01: update buffer captures ALL scheduled updates, including entities with no relevant change

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:115-119`
**Issue:**
`onFlush` buffers every `#[Shared]` entity returned by `getScheduledEntityUpdates()` and later
copies *all* `getFieldNames()` into the tenant copy. Doctrine schedules an entity for update if
*any* tracked field changed (including fields you might not want mirrored) and, more importantly,
the buffer does not consult the actual changeset (`$uow->getEntityChangeSet($entity)`). This is
mostly benign for scalar-only entities, but it means the sync always writes the *full current
landlord field set*, so any field mutated on the landlord entity within the same flush — even
unrelated to the intended "shared" payload — is propagated wholesale. There is no way for a
consumer to keep a field landlord-private. Worth confirming this is intended; if so, document it
alongside the cascade-boundary landmine.
**Fix:** Either document explicitly that *all* scalar fields of a `#[Shared]` entity are always
mirrored (no opt-out), or consult `getEntityChangeSet()` to mirror only changed fields.

### WR-02: `bool $syncInProgress` is not reentrant — nested/concurrent landlord flushes can mis-gate write protection

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:78,212-216`
**Issue:**
`$syncInProgress` is a plain boolean toggled to `true` before `doSync` and `false` after. If a
`doSync` flush on a tenant EM ever triggers a code path that itself causes another landlord
flush + fan-out (e.g., a Doctrine lifecycle callback, a postPersist listener on the tenant entity
that writes back, or an entity listener that flushes), the inner fan-out's `finally`-equivalent
sets `$syncInProgress = false` while the outer fan-out is still running. The write-protection
listener (`isSyncInProgress()`) would then incorrectly start *throwing* for the remainder of the
outer sync, or conversely leave protection bypassed. A boolean cannot represent nested sync
depth. Even absent nesting, the flag is reset in both the `try` (line 214) and the `catch`
(line 216) but **not** in a `finally`, so a `\Throwable` thrown between line 212 and the
`doSync` call (e.g., `resetManager` failure) before the catch — there is none here today, but the
duplicated reset across two branches is fragile.
**Fix:** Use a depth counter incremented/decremented in `try`/`finally`, or move the reset into
the existing `finally` block as the single reset site:
```php
$this->syncInProgress = true;
try {
    $this->doSync(...);
} finally {
    $this->syncInProgress = false;
}
```

### WR-03: `shared_db` no-op test does not exercise the production short-circuit it claims to cover

**File:** `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php:60-104`
**Issue:**
The test asserts SHARE-01-j ("subscriber is a no-op under `shared_db`") by persisting a
`#[Shared]` entity and checking exactly one row exists. But `TenancyBundle::loadExtension()` only
registers `tenancy.shared_entity_sync_subscriber` inside the `if ($databaseConfig['enabled'])`
block (`src/TenancyBundle.php:263-281`), and `SharedEntityNoDbTestKernel` configures
`driver: shared_db` with `database.enabled` left at its default `false`. Therefore **the
subscriber service is never registered or instantiated** in this kernel. The test passes
trivially because there is no subscriber at all — it does *not* validate the `postFlush`
`'shared_db' === $this->driver` short-circuit (`SharedEntitySyncSubscriber.php:147-152`), which
is the actual production code path the test purports to cover. The D-03 branch is effectively
untested.
**Fix:** Add an assertion that the subscriber *is* registered and that its `postFlush`
short-circuit ran (e.g., spy on the logger / provider to prove `findAll()` was not called), OR
explicitly document that under `shared_db` the service is never wired and the no-op is structural
(via DI), not runtime (via the driver check). If the no-op is purely structural, the `driver`
check in `postFlush` is dead code under the only configuration that reaches it — clarify which
guarantee is authoritative.

### WR-04: `isShared()` constructs a fresh `ReflectionClass` per entity per event with no cache

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:289-292`, `src/Subscriber/SharedEntityWriteProtectionListener.php:86`
**Issue:**
Both subscribers call `new \ReflectionClass($entity)` and `getAttributes(Shared::class)` for
every scheduled entity on every flush. While raw performance is out of v1 scope, this is also a
*correctness/robustness* smell: reflecting the instance (`new ReflectionClass($entity)`) rather
than the class works, but proxies/lazy-ghosts in ORM 3 / `enable_native_lazy_objects` (which this
codebase explicitly enables, see the test kernels) can be subclasses whose attribute set may not
reflect the mapped class. Reflecting `$entity::class` is also indirect for proxies — you generally
want the real class via `ClassMetadata`. Reflecting a proxy that does not carry the attribute
would cause a `#[Shared]` write to **bypass** write-protection or **skip** fan-out.
**Fix:** Resolve the real entity class through Doctrine before reflecting, and cache the result:
```php
$realClass = $em->getClassMetadata($entity::class)->getName(); // unwraps proxy class
// then reflect $realClass, memoised in a static/instance map keyed by class name
```

### WR-05: write-protection bypass keys entirely on a shared mutable flag owned by another service

**File:** `src/Subscriber/SharedEntityWriteProtectionListener.php:68`, `src/TenancyBundle.php:275-280`
**Issue:**
The write-protection listener bypasses *all* `#[Shared]` write checks whenever
`SharedEntitySyncSubscriber::isSyncInProgress()` returns true. The flag is a public, mutable
boolean with no scoping to the specific tenant EM being synced. If user code on the tenant side
manages to flush a `#[Shared]` entity *while* the subscriber happens to be mid-fan-out (re-entrant
listener, Doctrine lifecycle callback firing during the sync flush, or any future async/worker
interleaving), that genuinely-illegal tenant write is silently **permitted** because the global
flag is set. The guard cannot distinguish "this flush is the subscriber's own sync write" from
"this flush is user code that coincidentally overlaps a sync." Combined with WR-02 (non-reentrant
flag), the bypass window is wider than intended.
**Fix:** Narrow the bypass to the exact EM/UnitOfWork the subscriber is currently writing to
(e.g., compare `$args->getObjectManager()` against the EM the subscriber holds during `doSync`),
rather than a global boolean. At minimum, document the trust boundary and add a test that proves
a user-initiated tenant write during an active sync is still rejected.

### WR-06: `getDefinition()` can throw before the `class_exists` guard in the mutual-exclusion pass

**File:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php:49-54`
**Issue:**
`$class = $definition->getClass() ?? $id;` then `if (!class_exists($class)) continue;`. If a
tagged service definition has a `null` class and the service *id* is not a loadable class name
(common for non-FQCN service ids), `class_exists($id)` returns false and the entry is skipped —
correct. But if a synthetic/abstract definition is tagged, `getDefinition($id)` is fine, yet a
definition whose class is an interface or a non-existent class passes silently. More concretely,
the pass inspects attributes via `new \ReflectionClass($class)` only after `class_exists`, which
is safe, but it never accounts for `#[Shared]`/`#[TenantAware]` declared on a **parent class**
(reflection `getAttributes()` does not return inherited class attributes). A user who puts
`#[Shared]` on a mapped-superclass / base entity and `#[TenantAware]` on the same base will not be
caught, and a child that inherits both is also not inspected unless the child itself is tagged.
**Fix:** Walk the class hierarchy (or use `ReflectionClass::getAttributes()` on each ancestor) so
attributes declared on parent/mapped-superclass entities are also detected, and document that
only directly-tagged classes are inspected. Add a fixture test for the inheritance case.

## Info

### IN-01: duplicate `@param $capturedIds` docblock above `fanOutToTenant`

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:180-197`
**Issue:** Two stacked docblocks precede `fanOutToTenant()` — the first (lines 180-186) has no
`@param` and the second (187-190) repeats the description. The redundant block is dead
documentation.
**Fix:** Merge into a single docblock.

### IN-02: redundant double null/empty check on identifier in `doSync`

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:252`
**Issue:** The ternary `'delete' === $type ? ($capturedIds ?? $landlordMeta->getIdentifierValues($entity)) : $landlordMeta->getIdentifierValues($entity)` evaluates `getIdentifierValues($entity)`
for both branches; the only difference is the `?? captured` fallback on delete. The expression is
hard to read and the non-delete branch silently ignores `$capturedIds`. No bug, but the intent
(captured ids matter only for delete) would be clearer as an early `if`.
**Fix:**
```php
$ids = 'delete' === $type
    ? ($capturedIds ?? $landlordMeta->getIdentifierValues($entity))
    : $landlordMeta->getIdentifierValues($entity);
```
is already what exists — extract to a small named helper or guard clause for readability.

### IN-03: stale "Wave 0 / lands in Plan 25-0x" scaffolding comments left in shipped tests

**File:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php:7-11,67-68,490-492`; `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php:7-14,67-68`; `tests/Unit/Attribute/SharedTest.php:11-13`; `tests/Integration/SharedEntity/Support/Entity/TestPlan.php:7`
**Issue:** Now that the production classes have landed, the numerous `markTestSkipped(... lands
in Plan 25-0x)` guards and "Wave 0 behavior / RED state acceptable" comments are stale. The skip
guards mean these tests will *silently skip* (not fail) if the service is ever accidentally
unregistered — they no longer protect against regressions the way unconditional assertions would.
**Fix:** Remove the `class_exists`/`has(...)` skip guards now that the classes exist so the tests
assert unconditionally; delete the "lands in Plan" scaffolding comments.

### IN-04: `testPerTenantFailureIsLogged` asserts nothing meaningful (`assertGreaterThanOrEqual(0, ...)`)

**File:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php:466-529`
**Issue:** Despite covering the critical best-effort/logging behavior (SHARE-01-k / D-01 / D-07),
the test never makes a tenant DB unwritable (the docblock describes the intended strategy but it
was never implemented) and its final assertion `assertGreaterThanOrEqual(0, $successCount)` is
**always true** — it asserts nothing. There is no assertion that a `warning`/`error` was logged,
no assertion that the surviving tenant still received the row while the failed one did not. The
most safety-critical behavior of this phase (partial-failure isolation) is effectively untested.
**Fix:** Implement the documented strategy: point one tenant's `getConnectionConfig()` at an
unwritable path, flush a landlord insert, then assert (a) the healthy tenant has the row, (b) the
failed tenant does not, and (c) a log record was captured with the tenant slug + entity class +
identifier. Capture logs via a test logger bound into the container.

---

_Reviewed: 2026-06-11_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
