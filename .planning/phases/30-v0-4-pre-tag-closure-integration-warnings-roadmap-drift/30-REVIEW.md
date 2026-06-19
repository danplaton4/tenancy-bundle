---
phase: 30-v0-4-pre-tag-closure-integration-warnings-roadmap-drift
reviewed: 2026-06-19T00:00:00Z
depth: standard
files_reviewed: 12
files_reviewed_list:
  - src/Command/SharedEntityResyncCommand.php
  - src/MessageHandler/SharedEntityChangedMessageHandler.php
  - src/Shared/TenantEmSwitcher.php
  - src/Shared/TenantEmSwitcherInterface.php
  - src/Subscriber/SharedEntitySyncSubscriber.php
  - src/Subscriber/SharedEntityWriteProtectionListener.php
  - src/TenancyBundle.php
  - tests/Unit/Shared/TenantEmSwitcherTest.php
  - tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php
  - tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php
  - tests/Unit/Subscriber/SharedEntityWriteProtectionListenerTest.php
  - scripts/docs-lint.sh
findings:
  critical: 0
  warning: 0
  info: 3
  total: 3
status: issues_found
---

# Phase 30: Code Review Report

**Reviewed:** 2026-06-19T00:00:00Z
**Depth:** standard
**Files Reviewed:** 12
**Status:** issues_found (Info-only — no Blockers, no Warnings)

## Summary

This phase extracted a shared `TenantEmSwitcher` (+ `TenantEmSwitcherInterface`) from two
consumers (`SharedEntitySyncSubscriber`, `SharedEntityChangedMessageHandler`) that previously
each carried byte-identical `switchToTenant()`/`restoreTenantContext()` private methods (W-02),
swapped two constructor type-hints from the concrete `SharedEntityCopier` to its interface (W-01),
added a `@see`/back-reference note distinguishing the lightweight switch path from the full
bootstrapper-chain path (W-03), and fixed an awk state-leak in `docs-lint.sh` (D-10/WR-03). It
also added four unit-test files and reconciled `ROADMAP.md` (out of review scope).

I treated the extraction as adversarially as possible because a missed save/restore or an
unclosed tenant DBAL connection is a cross-tenant data leak (a security incident per CLAUDE.md).
The four critical concerns flagged in the brief all hold up:

1. **Extraction equivalence (CR-01/CR-02):** `TenantEmSwitcher::switchTo()` and `restore()` are
   **byte-for-byte equivalent** to the originals. I diffed `bf01a3d^` against HEAD: the only
   change to the moved code is the `\Doctrine\DBAL\Connection` FQCN becoming an imported
   `Connection` (functionally identical) — the `setTenant → getConnection('tenant') → close() →
   resetManager('tenant')` sequence and the `set-or-clear → close → resetManager` restore
   sequence are unchanged. Confirmed against both former twins.

2. **`$previousTenant` + finally:** Both consumers still compute
   `$previousTenant = hasTenant() ? getTenant() : null` BEFORE the loop and call
   `$this->switcher->restore($previousTenant)` inside a `finally` (subscriber line 209/237;
   handler line 109/150). No regression.

3. **D-03 async-dispatch branch:** The scope-fenced async branch (subscriber lines 172–202) has
   **zero `+`/`-` diff lines** for `dispatch`, the branch-local `clear()`, or the previous-tenant
   save/restore. Behavior is identical to before extraction.

4. **W-01 type-hint swaps:** `SharedEntityCopier` → `SharedEntityCopierInterface` in the
   subscriber and `SharedEntityWriteProtectionListener`. Every method called on `$this->copier`
   (`isShared`, `applyRow`, `deleteRow`, `classifyRow`, `findSharedClasses`, `isSyncInProgress`)
   is declared on the interface — no interface-method assumption is broken. All remaining
   `SharedEntityCopier` (concrete) mentions in those two files are docblock/comment references,
   not type-hints.

**Verification performed (not relied upon as proof, but corroborating):**
- DI wiring argument order in `TenancyBundle.php` matches both constructors positionally
  (subscriber args 1–7 → context/provider/doctrine/logger/driver/copier/em_switcher; `$bus` via
  named arg; handler args 1–7 → landlordEm/provider/copier/context/doctrine/logger/em_switcher).
- No now-unused imports or dangling `@see` to the deleted private methods.
- The 4 new unit files pass (13 tests / 54 assertions); full unit suite passes (615 tests);
  shared-entity integration suite passes (22 tests / 121 assertions); PHPStan L9 clean on all
  changed `src/` files; `docs-lint.sh` exits 0.

No Blockers and no Warnings found. Three Info-level items below are documentation/test-rigor
polish only — none affect correctness, security, or the CR-01/CR-02 invariant.

## Info

### IN-01: Stale prose in handler docblock still says "switchToTenant()" after extraction

**File:** `src/MessageHandler/SharedEntityChangedMessageHandler.php:29`
**Issue:** The fan-out-flow docblock step 5 reads "Per-tenant fan-out: `switchToTenant()` +
copier->deleteRow() …". That private method no longer exists — switching is now delegated to
`$this->switcher->switchTo()`. Line 44 likewise narrates the historical de-dup ("The duplicated
`switchToTenant()`/`restoreTenantContext()` private methods … have been extracted") which is
accurate as history but the step-5 wording reads as a live method name. Not a `@see` tag, so no
tooling breaks; purely a reader-confusion nit. The same conceptual `switchToTenant()` wording also
survives in two integration-test comments (`SharedEntitySyncIntegrationTest.php:920`,
`SharedEntityResyncCommandIntegrationTest.php:354`).
**Fix:** Update the step-5 phrasing to reference the delegated call, e.g.
`Per-tenant fan-out: switcher->switchTo() + copier->deleteRow() (delete) or applyRow() (upsert).`

### IN-02: Sync-mode async test asserts only that findAll() was called — no restore round-trip

**File:** `tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php:137-158`
**Issue:** `testPostFlushUsesSyncFanOutWhenAsyncDisabled` stubs `findAll()` to return `[]`, so the
fan-out loop body never executes and the only behavior actually exercised is the `findAll()`
materialization plus a `restore(null)` in the `finally`. The test carries a single implicit
assertion (the `expects($this->once())->method('findAll')` mock expectation) and never asserts
that `TenantContext` was correctly restored, nor that any change was applied. It proves "sync path
was entered," not "sync path is correct." Given this phase's whole point is preserving the
CR-01/CR-02 switch/restore semantics, a sync-mode test that drives the switcher round-trip
(non-empty `$tenants`, asserting context restored afterward) would be stronger. The
round-trip itself is covered by `TenantEmSwitcherTest` and the integration suite, so this is
test-rigor polish, not a coverage gap.
**Fix:** Add an assertion that seeds an active tenant before `postFlush()` and asserts
`$tenantContext->getTenant()` is the same instance afterward (mirroring
`testDispatchClearsTenantContextToAvoidStamp`), or extend the sync test to return one tenant and
assert restore.

### IN-03: docs-lint.sh SHARED_ENTITY block emits literal "\n" and re-derives EXIT redundantly

**File:** `scripts/docs-lint.sh:106-117`
**Issue:** Two minor robustness/readability nits in the shared-entity violation block (none affect
the gate's correctness — the script passes and short-circuits the build correctly):
(1) `SHARED_ENTITY_VIOLATIONS="${SHARED_ENTITY_VIOLATIONS}${f}\n"` accumulates a literal two-char
`\n`; it is later rendered with `printf "%b"` so the escape is interpreted at print time — this
works but is fragile (any future `echo "$SHARED_ENTITY_VIOLATIONS"` would print literal `\n`).
(2) `EXIT=1` is set both inside the `while` loop (line 107) and the violation message is printed
in a separate trailing `if` (line 112); the dual-location state mutation is harder to audit than
the sibling `BUNDLES_VIOLATIONS` block which sets `EXIT=1` once next to its own print. The awk
`FNR==1 { in_whitelist=0 }` fix added this phase is correct and addresses a real per-file
state-leak bug in the `BUNDLES_VIOLATIONS` scan (without it, a whitelisted trailing H2 section in
file N suppressed violations at the top of file N+1).
**Fix:** Use a real newline (`SHARED_ENTITY_VIOLATIONS+="${f}"$'\n'`) and print with plain `printf
'%s'`, or collect into a bash array and `printf '%s\n'`. Optional cleanup only.

---

_Reviewed: 2026-06-19T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
