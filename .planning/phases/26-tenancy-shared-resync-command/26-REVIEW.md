---
phase: 26-tenancy-shared-resync-command
reviewed: 2026-06-12T21:23:21Z
depth: standard
files_reviewed: 13
files_reviewed_list:
  - src/Command/SharedEntityResyncCommand.php
  - src/Shared/SharedEntityCopier.php
  - src/Shared/SharedEntityCopierInterface.php
  - src/Subscriber/SharedEntitySyncSubscriber.php
  - src/Subscriber/SharedEntityWriteProtectionListener.php
  - src/TenancyBundle.php
  - tests/Integration/Command/Support/CommandTestKernel.php
  - tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php
  - tests/Integration/SharedEntity/Support/MakeSharedEntityResyncServicesPublicPass.php
  - tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php
  - tests/Unit/Command/SharedEntityResyncCommandTest.php
  - tests/Unit/Shared/SharedEntityCopierTest.php
  - tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php
findings:
  critical: 1
  warning: 5
  info: 4
  total: 10
status: issues_found
---

# Phase 26: Code Review Report

**Reviewed:** 2026-06-12T21:23:21Z
**Depth:** standard
**Files Reviewed:** 13
**Status:** issues_found

## Summary

Phase 26 adds the `tenancy:shared:resync` console command, extracts a new `SharedEntityCopier`
service from the Phase 25 sync subscriber, and re-points the write-protection listener at the
copier's re-entrancy flag. I verified the extraction, the re-entrancy flag scoping, cross-DB key
equality, the `--force`/`--dry-run`/`-n` gate, and the Doctrine optional-dependency guards.

The re-entrancy guard itself is sound: the flag is set narrowly around `persist()+flush()` in a
`try/finally` so an exception mid-flush cannot leave protection permanently bypassed, and the
write-protection listener fires on `onFlush` while the flag is set, so the bypass still works after
the extraction. PHPStan level 9 passes clean on all six changed source files. Idempotency and CR-01
cross-DB key equality are correct and proven by integration tests.

The headline defect is in the command's **apply pass** error recovery: unlike the subscriber, the
command never resets the tenant EntityManager after a failed flush. Because the first failed flush
closes the Doctrine EM and the command re-uses the same `getManager('tenant')` instance for every
subsequent tenant, **a single tenant failure cascades to every later tenant** — directly defeating
the documented D-06 continue-on-failure isolation. This failure mode is not covered by any test (the
unit test forces failures from the bootstrapper, never from a real flush; the integration tests only
exercise the happy path).

There is no structural-findings substrate attached to this review, so all findings below are
narrative.

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: Apply pass never recovers a closed tenant EM — one tenant's flush failure cascades to all remaining tenants (breaks D-06)

**File:** `src/Command/SharedEntityResyncCommand.php:201-217` (and the apply loop at `165-176`)
**Issue:**
`resyncForTenant()` obtains the tenant EM with `$this->registry->getManager('tenant')` and never
calls `resetManager('tenant')`. When `SharedEntityCopier::applyRow()` calls `$tenantEm->flush()` and
the flush throws (constraint violation, locked DB, schema drift, etc.), Doctrine **closes** the
EntityManager (`EntityManager::close()` sets the closed flag; `EntityManager::clear()` does NOT
reopen it — verified in `vendor/doctrine/orm/src/EntityManager.php:420/532`).

The exception propagates to the apply loop's `catch (\Throwable $e)` (line 169), which records the
failure and continues to the next tenant. The `finally` runs `bootstrapperChain->clear()` /
`tenantContext->clear()`. For the next tenant, `bootstrapperChain->boot()` runs
`DoctrineBootstrapper::boot()` → `$em->clear()` — but `clear()` does not reopen a closed EM, and the
command then calls `getManager('tenant')` again, which returns **the same cached, still-closed EM
instance** (`AbstractManagerRegistry::getManager()` returns the cached service; only `resetManager()`
re-instantiates it). The next tenant's `find()`/`persist()`/`flush()` therefore throws
`EntityManagerClosed`, and so does every tenant after it.

Net effect: after the first real flush failure, **every subsequent tenant is reported as failed even
when its database is perfectly healthy.** The whole point of D-06 ("one tenant's failure does not
abort fan-out to the others") is defeated. Contrast `SharedEntitySyncSubscriber::applyChange()`
(lines 262-267), which explicitly calls `$this->registry->resetManager('tenant')` after a caught
flush failure precisely to avoid this cascade — the command omits the equivalent recovery.

Note the existing tests do not catch this: `SharedEntityResyncCommandTest::testContinueOnFailure...`
forces the failure from a spy `boot()` (before any flush, against a mock EM that never closes), and
the integration tests only sync successfully.

**Fix:** Reset the tenant manager after a failed apply so the next tenant runs against a usable EM.
For example, in the apply loop's `catch` (or inside a dedicated recovery step), reset before
continuing:

```php
foreach ($tenants as $tenant) {
    try {
        $this->resyncForTenant($tenant, $landlordRowsByClass);
        $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
    } catch (\Throwable $e) {
        $failures[] = $tenant->getSlug();
        $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
        // A failed flush closes the tenant EM; reset it so the next tenant gets a usable manager
        // (mirror SharedEntitySyncSubscriber::applyChange()).
        $this->registry->resetManager('tenant');
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();
    }
}
```

Add a test that makes `applyRow()` throw on the first tenant against a real/closeable EM and asserts
the second tenant still succeeds (or at least is attempted with an open EM).

## Warnings

### WR-01: Classify-pass `getManager('tenant')` is also vulnerable to a closed EM with no reset, and silently swallows + does not log the error

**File:** `src/Command/SharedEntityResyncCommand.php:122-140`
**Issue:**
The classify pass catches `\Throwable` and leaves the tenant's drift counts at `0/0/0` with **no log
line** (the comment says "summary will show 0/0/0"). Two problems:

1. A tenant whose classification fails is visually indistinguishable from a tenant that is fully
   in-sync with zero shared rows — the operator sees `0 | 0 | 0` and reasonably concludes "nothing to
   do," when in fact the classification crashed (e.g., tenant DB unreachable, schema missing). The
   subscriber logs fan-out failures at `error` level (`SharedEntitySyncSubscriber:255`); the command
   silently discards them. For an operator-facing audit tool this is a meaningful loss of signal.
2. Same root cause as CR-01: classify uses `getManager('tenant')` with no `resetManager`. Classify is
   read-only (`find()`), so it should not normally close the EM, but if any single tenant's
   classification throws something that closes the EM, the `0/0/0` masking compounds across tenants.

**Fix:** Log the caught classify error (at warning/error level with the tenant slug and exception
message) and surface a distinct marker in the table (e.g., an `error` column or `—` instead of `0`)
so a failed classification is not silently presented as "in-sync, nothing to do." Resetting the
manager in the `finally`/`catch` (per CR-01) also protects the classify loop.

### WR-02: Drift summary table is computed but discarded for failed-classify tenants, and the apply pass re-classifies — the table can lie

**File:** `src/Command/SharedEntityResyncCommand.php:143-148`, `209-216`
**Issue:**
The drift table shown before the confirmation prompt is built from the classify pass. The apply pass
(`resyncForTenant`) then **re-runs `classifyRow()`** and applies based on the second classification.
Between the two passes nothing re-reads the landlord rows (those are materialized once, good), but
the tenant side is re-resolved. If a classify failed (WR-01) and showed `0/0/0`, the operator
confirms believing "nothing will change for this tenant," yet the apply pass independently
re-classifies and **may now succeed and write rows** the operator never saw in the preview. The
preview and the action are not guaranteed to agree. This undermines the purpose of the
preview-then-confirm gate (D-03/D-04).

**Fix:** Either (a) reuse the classification results captured in the classify pass for the apply pass
instead of re-classifying, or (b) treat a tenant whose classify pass failed as a hard skip in the
apply pass (and show it as skipped in the table), so the operator's confirmation matches what
actually runs.

### WR-03: `count($tenants) - count($failures)` can mislabel skipped/short-circuited tenants as "succeeded"

**File:** `src/Command/SharedEntityResyncCommand.php:178`
**Issue:**
`$succeeded = count($tenants) - count($failures);` assumes every non-failed tenant was successfully
resynced. But a tenant can complete the apply pass `try` block without throwing yet without having
done anything meaningful — e.g., if `getManager('tenant')` returned the EM in a degraded state but
`resyncForTenant` happened not to throw, or if a future change adds a "skipped" outcome. The
"succeeded" count is derived by subtraction rather than by counting actual successes, so it cannot
distinguish "synced N rows" from "did nothing." Combined with WR-01's `0/0/0` masking, an operator
can be told "2 succeeded, 0 failed" when one tenant silently did nothing.

**Fix:** Count successes explicitly (increment a `$succeeded` counter in the `try` after
`resyncForTenant` returns) rather than computing it as `count($tenants) - count($failures)`, and
consider tracking a per-tenant applied-row count to report what actually changed.

### WR-04: Re-entrancy flag scope narrowed from per-batch to per-flush during extraction — verify the subscriber fan-out delete/reset window

**File:** `src/Shared/SharedEntityCopier.php:82-91`, `130-136`; `src/Subscriber/SharedEntitySyncSubscriber.php:174-189`
**Issue:**
In Phase 25 the subscriber set `syncInProgress = true` around the **entire per-tenant change batch**
(`foreach ($changes as $change)` wrapped in `try { ... } finally { $this->syncInProgress = false; }`).
After the extraction the flag is set only inside `SharedEntityCopier::applyRow()` around each
individual `persist()+flush()` (and around each `remove()+flush()` for deletes). Functionally the
write-protection listener fires on the tenant EM's `onFlush`, so the narrow window still covers the
guarded flush and the bypass works (confirmed by `SharedEntityResyncCommandIntegrationTest::testResyncWritesBypassWriteProtection`).

However, the narrowing changes one thing worth a deliberate check: in Phase 25 the flag was still
`true` while `applyChange()`'s catch block ran `resetManager('tenant')` and during the gap between
consecutive changes; now it is `false` there. `resetManager()` does not flush, so today this is
benign — but the change removes a defensive margin that previously protected any incidental flush
triggered during reset/teardown. There is no test asserting the flag is reset between two changes of
the same tenant in the subscriber's own fan-out (the dedicated test only covers the `shared_db`
short-circuit and the command path).

**Fix:** Add a subscriber-level test that fans out two `#[Shared]` changes to one tenant and asserts
`isSyncInProgress()` is `false` immediately after each change and after a simulated mid-batch flush
failure. If any teardown step (e.g., a future autoflush-on-reset) could flush, restore the
broader-than-flush guarding or document explicitly that no flush may occur outside `applyRow()`.

### WR-05: Apply pass re-classifies then applies, doubling tenant `find()` round-trips and creating a TOCTOU window

**File:** `src/Command/SharedEntityResyncCommand.php:209-216`
**Issue:**
`resyncForTenant` calls `classifyRow()` (one `find()` per row) and then, for non-`in-sync` rows,
`applyRow()` which does **another** `find()` for the same row. Beyond the redundant read, this is a
time-of-check-to-time-of-use gap: `classifyRow` decides `insert`, but by the time `applyRow` runs its
own `find()` the row may now exist (e.g., concurrent operator run, or the subscriber's fan-out firing
for the same landlord write), so `applyRow`'s `isInsert` branch is re-evaluated independently. The
two are internally consistent because `applyRow` re-checks, but the classify result passed in (`$type`)
is then partly ignored — `applyRow` trusts its own `find()` for insert-vs-update and only uses `$type`
to distinguish `delete`. Passing a `$type` that `applyRow` then re-derives is confusing and invites a
future bug where the two disagree (e.g., classify says `update`, applyRow finds nothing and silently
inserts, or vice versa).

**Fix:** Make `applyRow` authoritative (drop the `$type` insert/update distinction and let the
find-or-new logic decide), or have the command pass the already-classified rows so `applyRow` does not
re-`find()`. Document which component owns the insert/update decision.

## Info

### IN-01: `SharedEntityResyncCommand` documents but does not enforce the `EntityManagerInterface` landlord/tenant distinction

**File:** `src/Command/SharedEntityResyncCommand.php:29,127,207`
**Issue:**
The constructor takes `EntityManagerInterface $landlordEm` and separately fetches
`$this->registry->getManager('tenant')`. Both are typed `EntityManagerInterface`; nothing prevents a
future caller from passing the tenant EM as the "landlord" argument, or the registry default manager
being landlord. This is wiring-correct today (TenancyBundle injects
`doctrine.orm.landlord_entity_manager`), but the safety rests entirely on DI config.
**Fix:** No code change required; consider an inline assertion or a doc note that `$landlordEm` MUST be
the landlord manager and the `'tenant'` registry name MUST be the per-tenant manager.

### IN-02: Dead defensive branch — `null === $tenant` after `hasTenant()` already true

**File:** `src/Subscriber/SharedEntityWriteProtectionListener.php:75-79`
**Issue:**
Line 64 returns early when `!hasTenant()`. Line 75 then does
`$tenant = $this->tenantContext->getTenant();` and line 77 returns if `null === $tenant`. Given
`hasTenant()` was just true, `getTenant()` returning null is only reachable via a race on a non-atomic
context. This is harmless belt-and-suspenders but is effectively dead under the single-threaded request
model. Worth a one-line comment so it is not mistaken for a real guard.
**Fix:** Add a brief comment noting the null-check is defensive against a context cleared between the
two calls, or fold it into the line-64 guard.

### IN-03: Unreachable `'shared_db'` short-circuit retained in subscriber (acknowledged) and mirrored in command

**File:** `src/Subscriber/SharedEntitySyncSubscriber.php:144-149`, `src/Command/SharedEntityResyncCommand.php:65-70`
**Issue:**
TenancyBundle.php:267-272 documents that the subscriber is never registered under `shared_db` (the
service only exists when `database.enabled: true`, which the validator forbids combining with
`shared_db`), so the subscriber's `'shared_db' === $this->driver` branch is unreachable under any
wiring this bundle produces. It is intentionally kept as defence-in-depth and covered by a unit test.
The command's analogous `shared_db` no-op (line 65) IS reachable because the command is tagged
`console.command` and can be invoked directly. The two are inconsistent in reachability but both are
intentional. No action required beyond awareness; flagged so a future reader does not "dead-code-remove"
the command branch thinking it matches the subscriber's.
**Fix:** None required. Optionally cross-reference the two no-ops in comments.

### IN-04: Test `testContinueOnFailureExitsFailureWhenAnyTenantFails` does not exercise the real failure mode (relates to CR-01)

**File:** `tests/Unit/Command/SharedEntityResyncCommandTest.php:186-242`
**Issue:**
The continue-on-failure test forces the failure from a spy bootstrapper's `boot()` (count > 2), which
throws before any tenant EM flush occurs and against a mock `$tenantEm` that never closes. It proves
the loop continues and exits FAILURE, but it does NOT prove the documented isolation when the failure
originates from `applyRow()`'s flush (the realistic case) — which is exactly where CR-01 bites. The
test gives false confidence that D-06 holds.
**Fix:** Add a case where the mocked `SharedEntityCopierInterface::applyRow` throws on the first tenant
and assert the second tenant's `applyRow` is still invoked (and, ideally, that `resetManager('tenant')`
is called between them once CR-01 is fixed).

---

_Reviewed: 2026-06-12T21:23:21Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
