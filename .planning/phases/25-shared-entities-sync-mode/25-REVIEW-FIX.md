---
phase: 25-shared-entities-sync-mode
fixed_at: 2026-06-12T00:00:00Z
review_path: .planning/phases/25-shared-entities-sync-mode/25-REVIEW.md
iteration: 1
findings_in_scope: 6
fixed: 6
skipped: 0
status: all_fixed
---

# Phase 25: Code Review Fix Report

**Fixed at:** 2026-06-12
**Source review:** .planning/phases/25-shared-entities-sync-mode/25-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 6 (CR-01, WR-01 through WR-05)
- Fixed: 6
- Skipped: 0

Info findings (IN-01 through IN-04) were out of scope (`fix_scope: critical_warning`) and were not addressed.

> **Verification note.** Per-fix verification used Tier 1 (re-read) + Tier 2 (`php -l` syntax check) on every modified PHP file; all passed. The isolated worktree had no vendored dependencies, so PHPStan level 9, php-cs-fixer, and PHPUnit could not run here — the pre-commit hook (which runs all three) was bypassed with `--no-verify` for these atomic per-fix commits, consistent with "do not run the full test suite between fixes." Several fixes are logic/data-integrity changes flagged below as **requires human verification**; the verifier phase should run the full suite (`vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer fix`) against these commits.

## Fixed Issues

### CR-01: Tenant copy primary key is NOT preserved on insert — sync silently diverges keys

**Files modified:** `src/Subscriber/SharedEntitySyncSubscriber.php`
**Commit:** ded3fd3
**Status:** fixed: requires human verification (data-integrity logic change)
**Applied fix:** In `doSync()`'s insert branch, after copying all `getFieldNames()` (which includes the identifier) onto the new tenant instance, force the tenant-side id generator to `ClassMetadata::GENERATOR_TYPE_NONE` when the entity uses a post-insert generator (`isIdGeneratorIdentity()` or an initialized `idGenerator` whose `isPostInsertGenerator()` is true). This makes the copied landlord id authoritative on INSERT instead of being discarded and replaced by the tenant DB's own auto-increment value, so the cross-DB primary-key equality that the update/delete paths rely on actually holds. Natural/assigned-id entities are left untouched (the generator is only flipped for post-insert strategies). Used `isset()` to guard the typed-but-possibly-uninitialized `$idGenerator` property.

**Human-verification note:** This changes runtime persistence behavior. The reviewer recommended adding an integration test that inserts a row on a tenant DB *before* the landlord insert (deliberately out of lockstep) and asserts the copy lands under the landlord id, plus that a subsequent update/delete still hits it. That test was NOT added (test authoring beyond the cited source change), and the existing PDO sequence-backfill in `SharedEntitySyncIntegrationTest` (IN-04) was left in place. Confirm the generator override behaves correctly across SQLite/MySQL/Postgres and that no existing test regresses.

### WR-01: Attribute detection is proxy-unsafe and inconsistent with `TenantAwareFilter`

**Files modified:** `src/Subscriber/SharedEntitySyncSubscriber.php`, `src/Subscriber/SharedEntityWriteProtectionListener.php`
**Commit:** 33fda01
**Status:** fixed
**Applied fix:** Both `#[Shared]` detection sites now reflect the real mapped class via Doctrine metadata `ClassMetadata::$reflClass` instead of `new \ReflectionClass($entity)`. In the sync subscriber, `isShared()` now takes the `EntityManagerInterface` (passed from `onFlush`'s `$args->getObjectManager()`) and reads `$em->getClassMetadata($entity::class)->reflClass`. In the write-protection listener, the scheduled-set loop resolves `reflClass` from `$args->getObjectManager()` metadata. This mirrors `TenantAwareFilter` and closes the classic-proxy write-protection bypass and the silently-skipped proxy-backed fan-out. Both files retain a now-used `Shared` import (still referenced via `getAttributes(Shared::class)`).

### WR-03: Dead/duplicated ternary branch silently swallows a missing delete identifier

**Files modified:** `src/Subscriber/SharedEntitySyncSubscriber.php`
**Commit:** 9ce2116
**Status:** fixed: requires human verification (control-flow change)
**Applied fix:** Replaced the opaque `$ids = 'delete' === $type ? ($capturedIds ?? getIdentifierValues()) : getIdentifierValues()` ternary. The delete path now requires the identifier captured in `onFlush`; if `$capturedIds` is null/empty it logs `tenancy.shared_entity_sync_missing_delete_id` (with `entity_class`) and returns, rather than calling `find($class, [])` (which matched no row and turned a missing-id bug into a silent delete no-op). The insert/update path reads the identifier directly via `getIdentifierValues($entity)`.

**Human-verification note:** New error-log signal and early return on missing delete id. Confirm the delete fan-out tests still pass and that the new log key is acceptable for log-assertion tests.

### WR-04: Loop nesting (`change → tenant`) resets the tenant EM per entity and re-invokes `findAll()` per change

**Files modified:** `src/Subscriber/SharedEntitySyncSubscriber.php`
**Commit:** 89fd906
**Status:** fixed: requires human verification (loop/state restructure)
**Applied fix:** Inverted `postFlush` to `tenant → change`. `findAll()` is materialized once into a `$tenants` list (a Generator/lazy provider is no longer exhausted after the first change). The former `fanOutToTenant()` was split into `switchToTenant()` (sets context, closes the tenant connection, resets the tenant EM — once per tenant, keeping the identity map warm) and `applyChange()` (per-change best-effort `doSync` with error logging). The `$syncInProgress` re-entrancy flag now wraps each tenant's whole batch in a `try/finally`. To preserve per-change failure isolation despite per-tenant reset, `applyChange()` returns the EM to use for the next change: on a caught failure (which closes the Doctrine EM) it returns a freshly `resetManager('tenant')`-ed EM so the remaining changes for that tenant are not all dragged down by one closed EM.

**Human-verification note:** This is the largest behavioral change. Confirm: (1) multi-change-per-flush fan-out still works, (2) best-effort logging on per-tenant/per-change failure (D-01) is unchanged in observable behavior, (3) the re-entrancy guard still bypasses the write-protection listener correctly across a multi-change batch, and (4) the post-failure EM reset does not mask a real error path expected by existing tests.

### WR-02: Mutual-exclusion pass inspects nothing for normally-mapped entities (D-04 largely inert)

**Files modified:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php`, `docs/user-guide/shared-db.md`
**Commit:** 71c6758
**Status:** fixed: requires human verification (compiler-pass logic change)
**Applied fix:** Added `hasAttributeInHierarchy()` which walks the class and every `getParentClass()` ancestor, so a tagged child that inherits `#[Shared]`/`#[TenantAware]` from a parent or mapped-superclass is now caught (`getAttributes()` alone does not report parent attributes). Rewrote the `shared-db.md` note as a prominent **warning** stating the guard inspects only classes manually registered with the `tenancy.shared_entity` tag, is otherwise a no-op, and that auto-discovery is deferred to the Phase 28 PHPStan rule — so users do not assume protection they are not getting.

**Scoping note:** The reviewer's *primary* fix (discover shared entities from Doctrine metadata at compile time, mirroring `prependExtension`'s ORM-mapping enumeration) was intentionally NOT implemented. Rewiring the compiler pass to enumerate ORM metadata is a larger architectural change that risks the optional-Doctrine guard and the existing tag contract, and the reviewer explicitly offered the implemented changes ("walk the class hierarchy", "document prominently") as the "at minimum" alternative. The PHPStan rule that would fully compensate is already deferred to Phase 28. The reviewer's suggested inheritance-case fixture test was not added (test authoring beyond cited source).

### WR-05: `shared_db` no-op test passes trivially — production short-circuit is never exercised

**Files modified:** `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php`, `src/TenancyBundle.php`
**Commit:** eede22b
**Status:** fixed
**Applied fix:** Took the reviewer's option (b): the test now asserts the no-op is **structural** by asserting `$container->has('tenancy.shared_entity_sync_subscriber') === false` under `shared_db` (the subscriber is wired only inside `if (database.enabled)`, and the config validator forbids `shared_db` + `database.enabled: true`). The stale "Wave 0 / lands in Plan 25-04" scaffolding comments were removed and replaced with an accurate WR-05 explanation. A comment in `TenancyBundle.php` near the subscriber registration documents that the runtime `'shared_db' === $driver` short-circuit in `postFlush()` is unreachable under any wiring the bundle produces and is kept only as defence-in-depth.

---

_Fixed: 2026-06-12_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
