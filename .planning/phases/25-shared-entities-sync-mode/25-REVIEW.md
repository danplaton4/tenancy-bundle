---
phase: 25-shared-entities-sync-mode
reviewed: 2026-06-12T00:00:00Z
depth: standard
files_reviewed: 20
files_reviewed_list:
  - docs/user-guide/shared-db.md
  - src/Attribute/Shared.php
  - src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php
  - src/Exception/SharedEntityWriteInTenantContextException.php
  - src/Subscriber/SharedEntitySyncSubscriber.php
  - src/Subscriber/SharedEntityWriteProtectionListener.php
  - src/TenancyBundle.php
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
  critical: 1
  warning: 5
  info: 4
  total: 10
status: issues_found
---

# Phase 25: Code Review Report

**Reviewed:** 2026-06-12
**Depth:** standard
**Status:** issues_found

> **Re-review note.** A prior review (2026-06-11) raised CR-01/CR-02/CR-03 (context wipe, stale
> tenant socket, under-signalled divergence log) and WR-02 (non-reentrant flag), all marked fixed
> in its Resolution Addendum. This fresh adversarial pass against the *current* source confirms
> those four fixes are present and correct: `postFlush()` snapshots and restores the prior tenant
> via `restoreTenantContext()`, the tenant DBAL connection is closed after the loop, the
> best-effort `catch` logs at `error` level, and `$syncInProgress` resets in a `finally`. Those
> findings are therefore **not** re-raised. This pass instead surfaces a more fundamental defect
> the prior review did not catch (CR-01 below), plus a re-scoped set of warnings against the
> current code.

## Summary

Phase 25 ships the `#[Shared]` fan-out machinery: a landlord-EM subscriber that buffers `#[Shared]`
changesets in `onFlush` and replays them into every tenant EM in `postFlush`, a tenant-EM
write-protection listener, a compile-time mutual-exclusion pass, and a documented `shared_db`
no-op. The event-buffering design, re-entrancy guard, and the post-hardening context save/restore
are all sound.

The newly-found BLOCKER is upstream of all of that: the sync's core premise — that a tenant copy
carries the **same primary key** as the landlord master — is never actually enforced. The insert
path copies the identifier onto a new tenant instance and `persist()`s it, but the shared test
entity (and any realistic shared entity) uses `#[ORM\GeneratedValue]` (IDENTITY), a post-insert
generator that discards the copied id and lets each tenant DB assign its own auto-increment value.
Master and copy keys therefore stay equal only by coincidence (fresh, lockstep sequences). The
integration suite even backfills a row via raw PDO to keep the sequences aligned — direct evidence
the production sync cannot preserve the id and the green tests validate an accident, not the
invariant. Once keys diverge, the update and delete paths (which look the copy up by the landlord
id) silently create duplicates or no-op, leaving stale shared data on tenants.

## Critical Issues

### CR-01: Tenant copy primary key is NOT preserved on insert — sync silently diverges keys

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:306-326` (insert/update path);
identity-generator interaction at `vendor/doctrine/orm/src/Id/IdentityGenerator.php:16-22`

**Issue:** `doSync()` builds a new tenant instance and copies every `getFieldNames()` field —
including the identifier — onto it, then persists:

```php
$copy = $tenantMeta->newInstance();
foreach ($landlordMeta->getFieldNames() as $fieldName) {
    $value = $landlordMeta->getFieldValue($entity, $fieldName);
    $tenantMeta->setFieldValue($copy, $fieldName, $value);   // sets id = landlord id
}
$tenantEm->persist($copy);
$tenantEm->flush();
```

`TestPlan` (and any realistic shared entity) uses `#[ORM\GeneratedValue]` → IDENTITY strategy.
Doctrine's `IdentityGenerator::isPostInsertGenerator() === true`: the INSERT omits the id column
and Doctrine reads `lastInsertId()` afterward. The id value just written onto `$copy` is **ignored**
and the tenant DB assigns its own auto-increment value.

Consequence: the tenant copy's PK equals the landlord's only while both databases' auto-increment
sequences happen to be in perfect lockstep. As soon as a tenant has pre-existing rows, a gap from a
prior failed insert, or any independent write history, the copy gets a *different* id than the
master. The **update** path (`$tenantEm->find($class, $landlordIds)`, line 307) and the **delete**
path (`$tenantEm->find($class, $capturedIds)`, line 297) both resolve the copy by the *landlord's*
id, so they silently miss the diverged copy: updates create a second row, deletes become no-ops,
and stale shared data accumulates on tenants. This is a data-integrity defect.

Corroborating evidence: `SharedEntitySyncIntegrationTest::testPerTenantFailureIsLogged()`
(lines 606-620) deliberately backfills the blocked row via raw PDO *"with the exact same id that
the landlord used so sequential tests' ids align."* The suite cannot rely on production sync to
preserve ids — it patches the sequence by hand — so the green tests validate lockstep coincidence,
not the invariant.

**Fix:** Do not depend on the IDENTITY generator on the tenant side for synced copies; make the
landlord id authoritative on insert. Minimal ORM-level approach — switch the id generator to NONE
for the synced insert so the copied id survives:

```php
if (null === $existing) {
    $copy = $tenantMeta->newInstance();
    foreach ($landlordMeta->getFieldNames() as $fieldName) {
        $tenantMeta->setFieldValue($copy, $fieldName, $landlordMeta->getFieldValue($entity, $fieldName));
    }
    $tenantMeta->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
    $tenantEm->persist($copy);
    $tenantEm->flush();
}
```

A more robust alternative is a connection-level `INSERT ... ON CONFLICT(id) DO UPDATE` upsert keyed
on the landlord id, which also collapses the find-or-new branching. Whichever is chosen, add an
integration test that inserts a row on a tenant DB *before* the landlord insert (so sequences are
deliberately out of lockstep) and asserts the tenant copy lands under the landlord's id and that a
subsequent landlord update/delete still hits that copy.

## Warnings

### WR-01: Attribute detection is proxy-unsafe and inconsistent with `TenantAwareFilter`

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:333`,
`src/Subscriber/SharedEntityWriteProtectionListener.php:86`

**Issue:** Both classes detect `#[Shared]` via `(new \ReflectionClass($entity))->getAttributes(Shared::class)`,
reflecting the runtime object. When `$entity` is a classic Doctrine lazy-loading proxy
(`Proxies\__CG__\...`), `new \ReflectionClass($entity)` reflects the proxy subclass; PHP class
attributes are not inherited, so `getAttributes(Shared::class)` returns `[]`. Result: (a) the
write-protection listener fails to block a tenant-side update/delete of a proxy-backed `#[Shared]`
entity — a write-protection bypass; (b) the sync subscriber silently skips a proxy-backed shared
deletion/update. The existing `TenantAwareFilter` (src/Filter/TenantAwareFilter.php:32-34)
deliberately reflects `$targetEntity->reflClass` (the real class from `ClassMetadata`) to avoid
exactly this. Native lazy objects (PHP 8.4 + DoctrineBundle ≥ 2.14, enabled in the test kernels)
keep the real class and mask the bug in CI, but the default/classic-proxy configuration is affected.

**Fix:** Resolve `#[Shared]` against the real class via Doctrine metadata, consistent with the
filter:
```php
private function isShared(object $entity, EntityManagerInterface $em): bool
{
    $refl = $em->getClassMetadata($entity::class)->reflClass;
    return null !== $refl && [] !== $refl->getAttributes(Shared::class);
}
```
In the write-protection listener, obtain metadata from `$args->getObjectManager()` and reflect the
metadata `reflClass` rather than the raw object.

### WR-02: Mutual-exclusion pass inspects nothing for normally-mapped entities (D-04 largely inert)

**File:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php:48-63`

**Issue:** The guard only walks services carrying the `tenancy.shared_entity` tag. Doctrine
entities are **not** DI services — they are plain mapped classes never registered in the container,
and `grep` confirms the tag is referenced only inside this pass and its own docs (no
`registerForAutoconfiguration`, no `#[Shared]` autotagging, no metadata-driven discovery). So for
any user who maps entities the normal way, `findTaggedServiceIds()` returns an empty set and the
`#[Shared]` + `#[TenantAware]` mutual-exclusion check — the documented "data-leak bug class" guard
(D-04 / DEC-SHARE-03) — never fires. Users would have to manually register each entity class as a
tagged service, an unusual, undocumented step. The PHPStan rule that would compensate is deferred to
Phase 28. Additionally, `getAttributes()` does not return attributes declared on a parent /
mapped-superclass, so even a tagged child inheriting `#[Shared]` from a base is not caught.

**Fix:** Discover shared entities from Doctrine metadata at compile time (mirror how
`prependExtension` already enumerates the ORM mappings) and walk the class hierarchy so parent /
mapped-superclass attributes are detected. At minimum, document prominently in `shared-db.md` that
the guard requires manual `tenancy.shared_entity` tagging and is otherwise a no-op, so users do not
assume protection they are not getting. Add an inheritance-case fixture test.

### WR-03: Dead/duplicated ternary branch silently swallows a missing delete identifier

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:294-303`

**Issue:**
```php
$ids = 'delete' === $type ? ($capturedIds ?? $landlordMeta->getIdentifierValues($entity)) : $landlordMeta->getIdentifierValues($entity);
```
Per the class contract, by postFlush the landlord has zeroed the identifier fields, so for a delete
`$landlordMeta->getIdentifierValues($entity)` returns `[]`. The `?? getIdentifierValues(...)`
fallback is therefore effectively `?? []` — if `$capturedIds` is null, `$ids` becomes `[]` and
`$tenantEm->find($class, [])` is called, which does not identify a row, so the delete silently
no-ops. The fallback masks the real failure (missing captured id) rather than surfacing it, and the
two ternary arms are otherwise identical, making the expression opaque.

**Fix:** Require captured ids for deletes and fail loudly if absent; simplify:
```php
if ('delete' === $type) {
    if (null === $capturedIds || [] === $capturedIds) {
        $this->logger->error('tenancy.shared_entity_sync_missing_delete_id', ['entity_class' => $class]);
        return;
    }
    $existing = $tenantEm->find($class, $capturedIds);
    if (null !== $existing) { $tenantEm->remove($existing); $tenantEm->flush(); }
    return;
}
$ids = $landlordMeta->getIdentifierValues($entity); // insert/update only
```

### WR-04: Loop nesting (`change → tenant`) resets the tenant EM per entity and re-invokes `findAll()` per change

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:165-174`

**Issue:** The fan-out loop is `foreach ($changes) { foreach ($tenantProvider->findAll() as $tenant)
{ fanOutToTenant() } }`, and `fanOutToTenant` closes + `resetManager('tenant')` on every iteration.
Two robustness problems: (a) `findAll()` is re-invoked once per changed entity — the interface
returns an array here, but a provider that lazily queries or returns a `Generator` would be
exhausted after the first change, silently skipping fan-out for all later changes; (b) because the
tenant EM is reset between every entity, a later shared entity that references an earlier-synced one
in the same flush cannot see it in the identity map. Inverting to `tenant → change` resets each
tenant EM once and applies all of that tenant's changes against a single warm EM.

**Fix:**
```php
$tenants = $this->tenantProvider->findAll();   // materialize once
foreach ($tenants as $tenant) {
    $this->switchToTenant($tenant);            // close + reset once per tenant
    foreach ($changes as $change) {
        $this->applyChange($tenantEm, $change); // no reset between changes
    }
}
```

### WR-05: `shared_db` no-op test passes trivially — production short-circuit is never exercised

**File:** `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php:70-104`,
`src/TenancyBundle.php:263-281`

**Issue:** The test asserts SHARE-01-j ("subscriber is a no-op under `shared_db`") by persisting a
`#[Shared]` entity and checking exactly one row exists. But `TenancyBundle::loadExtension()` only
registers `tenancy.shared_entity_sync_subscriber` inside the `if ($databaseConfig['enabled'])`
block, and `SharedEntityNoDbTestKernel` runs `driver: shared_db` with `database.enabled` left at its
default `false`. The subscriber service is therefore **never registered** in this kernel — the test
passes because there is no subscriber at all, not because the `postFlush` `'shared_db' === $driver`
short-circuit (lines 147-152) ran. That branch is effectively untested, and under the only
configuration that would reach it the no-op is structural (via DI), making the runtime driver check
dead code there.

**Fix:** Either assert the subscriber *is* registered and prove its short-circuit ran (spy on the
provider to confirm `findAll()` was not called), or explicitly document that under `shared_db` the
service is never wired and the no-op is structural — and clarify whether the runtime `driver` check
is still needed (e.g., for a `database.enabled: true` + `driver: shared_db` combination, which the
config validator at TenancyBundle.php:121-129 actually forbids).

## Info

### IN-01: `getClass() ?? $id` fallback in compiler pass cannot resolve a real class

**File:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php:50`

**Issue:** `$class = $definition->getClass() ?? $id;` — when a definition has no class, falling back
to the *service id* as a class name is almost never a valid FQCN, so `class_exists($class)` is false
and the entry is skipped anyway. The fallback adds no value and obscures intent.

**Fix:** `$class = $definition->getClass(); if (null === $class || !class_exists($class)) { continue; }`

### IN-02: Buffer docblock overstates the id-propagation guarantee

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:65-72`

**Issue:** The buffer docblock says inserts/updates need only the entity reference because the id
"is available at postFlush time." True for reading, but it implies the id is carried across to the
tenant copy for free, which (per CR-01) it is not. After CR-01 is fixed, revise this comment to
describe the real explicit-id-insert mechanism.

**Fix:** Update the docblock once CR-01's id strategy is chosen.

### IN-03: Stale "Wave 0 / lands in Plan 25-0x" scaffolding in shipped tests masks regressions

**File:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php:7-11` plus every
`markTestSkipped(... lands in Plan 25-0x)` guard; `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php:7-14`;
`tests/Unit/Attribute/SharedTest.php:18-22`; `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php:27-33`

**Issue:** The production classes have landed, so the `class_exists`/`has(...)` skip guards now
cause these tests to **silently skip** rather than fail if a service is ever accidentally
unregistered — they no longer protect against that regression. The "lands in Plan" comments are
stale scaffolding.

**Fix:** Remove the skip guards so the tests assert unconditionally, and delete the Wave-0 / "lands
in Plan" comments.

### IN-04: Integration test couples to internal auto-increment sequencing via raw PDO backfill

**File:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php:606-620`

**Issue:** `testPerTenantFailureIsLogged()` hand-backfills the blocked row with a hardcoded id and
column list to keep tenant_a's sequence aligned for *subsequent* tests. This bakes in the unsound
id-lockstep assumption (CR-01), creates hidden ordering coupling between test methods, and is
brittle. Once CR-01 is fixed this backfill should become unnecessary.

**Fix:** After CR-01 lands, remove the PDO backfill and assert the synced row's id equals the
landlord id directly; recreate the tenant schema in `setUp` if per-test isolation is needed.

---

_Reviewed: 2026-06-12_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
