---
phase: 23-tech-debt-closure
plan: 03
subsystem: resolver+command+messenger+canary-test
tags: [tech-debt, consistency, comment-anchored-test, canary-cleanup, wr-02, wr-03, wr-04, in-01, in-02, in-03, in-04, in-05]
requirements:
  - DX-06
  - WR-02
  - WR-03
  - WR-04
  - IN-01
  - IN-02
  - IN-03
  - IN-04
  - IN-05
dependency_graph:
  requires:
    - phase: 23
      plan: 02
      provides: "WR-01 LogicException runtime tests (sibling closure phase item)"
    - commit: 31465dc
      provides: "CR-01 closure — consistent no-default nullable signature across all 6 sites (resolved 2026-05-21)"
  provides:
    - "WR-02 source-order tripwire: ConsoleResolver guard-precedes-mutation invariant pinned by ConsoleResolverGuardOrderingTest (2 tests)"
    - "WR-03 pattern-aligned slug check in QueryParamResolver: is_string() type-narrow + trim()-aware empty-string rejection (1 regression test for whitespace-only)"
    - "WR-04 @security trust-boundary docblock above TenantRunCommand's Process call site"
    - "IN-01..IN-04 canary cleanup in ZeroConfigKernelBootTest: stale @group dropped, PID-suffixed cache-dir hash, deduped tearDown, default exception-handling for clearer regression messages"
  affects: []
tech_stack:
  added: []
  patterns:
    - "Comment-anchored test (literal 'GUARD ORDERING' + 'MUST' tokens) as a tripwire signal — stripping the comment IS the first sign of intent-altering refactor"
    - "Source-order invariant via file_get_contents + line-number scan (no runtime exercise; complements existing runtime coverage)"
    - "PID-suffixed cache-dir hash (md5(static::class.$env.getmypid())) for parallel-PHPUnit worker isolation"
    - "PHPDoc @security tag at trust-boundary call sites — documents the threat model in source where future maintainers will look"
key_files:
  created:
    - tests/Unit/Resolver/ConsoleResolverGuardOrderingTest.php  # 107 LOC, 2 tests, 7 assertions
  modified:
    - src/Resolver/ConsoleResolver.php                          # +7 lines (GUARD ORDERING comment block)
    - src/Resolver/QueryParamResolver.php                       # +4/-2 lines (is_string+trim pattern)
    - src/Command/TenantRunCommand.php                          # +11 lines (@security PHPDoc block)
    - tests/Integration/ZeroConfigKernelBootTest.php            # +29/-21 lines (IN-01..IN-04 cleanup)
    - tests/Unit/Resolver/QueryParamResolverTest.php            # +17 lines (WR-03 whitespace test)
    - .planning/phases/23-tech-debt-closure/23-03-SUMMARY.md    # this file
decisions:
  - "IN-05 (explicit `use Tenancy\\Bundle\\Messenger\\TenantStamp;` in TenantWorkerMiddleware) SKIPPED — the project's php-cs-fixer @Symfony ruleset includes `no_unused_imports`, which auto-strips same-namespace imports as redundant. Adding the import results in a cs-fixer revert on the pre-commit hook. The audit-flagged 'minor consistency drift' is policed in the opposite direction by the project's enforced code-style config — IN-05 is moot."
  - "WR-04 reduced to docblock-only — the shell-injection vector (Process::fromShellCommandline) was closed in v0.3.0 (verified at HEAD: src/Command/TenantRunCommand.php L71 now uses `new Process($command)` with array argv). The audit finding's threat-model concern is preserved via a PHPDoc @security block above the Process site explaining the trust boundary and the v0.3.0 fix."
  - "IN-02 implementation follows 18-VERIFICATION.md L41 intent (suppression of diagnostic message via setCatchExceptions(false)), NOT CONTEXT.md's expectException misquote — the canary test is GREEN-path, not a deliberate-throw test. Implementation: drop the setCatchExceptions(false) line; keep the assertSame + getDisplay() diagnostic pattern."
  - "WR-02 comment text uses literal tokens 'GUARD ORDERING' and 'MUST' (both checked by ConsoleResolverGuardOrderingTest::testGuardOrderingCommentBlockExists). Stripping the comment is itself a tripwire — the comment is load-bearing for the source-order tripwire test."
metrics:
  duration: ~35 min
  completed_date: 2026-05-29
  tasks_executed: 3
  tasks_partial: 1  # Task 3: IN-01..IN-04 done, IN-05 skipped per cs-fixer conflict
  tests_added: 3   # 2 guard-ordering + 1 whitespace-slug regression
  total_tests_before: 565
  total_tests_after: 568
  commits: 3       # b143597, fcdfaf1, 283e0fa
---

# Phase 23 Plan 03: Intra-bundle consistency nits + canary-test cosmetics — SUMMARY

## One-line summary

Closed five advisory findings (WR-02 guard-ordering, WR-03 trim-aware slug check, WR-04 @security docblock, IN-01..IN-04 canary cleanup) via small mechanical edits across 5 files + 1 new comment-anchored test; SKIPPED IN-05 because the project's cs-fixer @Symfony ruleset auto-strips same-namespace imports.

## Change list

### WR-02 — ConsoleResolver guard-ordering defensive comment + source-order test

**Files:** `src/Resolver/ConsoleResolver.php`, `tests/Unit/Resolver/ConsoleResolverGuardOrderingTest.php` (NEW)

**Before:** The null-tenantProvider early-return guard at L31 was a fragile ordering invariant — no comment, no test. A future refactor could reorder it below the `$appDefinition->addOption('tenant', ...)` Application-definition mutation at L53-57. Doing so would pollute every console command's `--help`/`--list` output with a stale `--tenant` flag in zero-config mode (where the resolver does nothing anyway).

**After:** A multi-line comment block above the mutation site carries the literal tokens `GUARD ORDERING` and `MUST`, documenting why the guard must stay above the mutation. A new `ConsoleResolverGuardOrderingTest` (2 tests, 7 assertions) reads the source file and asserts:
1. The line containing `null === $this->tenantProvider` precedes the line containing `addOption(` — fails with a load-bearing message naming both line numbers + the WR-02 invariant.
2. The literal tokens `GUARD ORDERING` and `MUST` exist in the source — stripping the comment is itself a tripwire.

The test does NOT exercise runtime resolver behavior (already covered by `ConsoleResolverTest`); it's a static source-level scan.

**RED-bar mental proof:** swap the L31 guard below the L54 mutation → both new tests fail with the messages above.

Commit: **b143597** (source + cs-fixer pattern alignment), **fcdfaf1** (new test).

### WR-03 — QueryParamResolver pattern alignment + whitespace rejection

**File:** `src/Resolver/QueryParamResolver.php`, `tests/Unit/Resolver/QueryParamResolverTest.php`

**Before:**
```php
if (null === $slug || '' === $slug) {
    return null;
}

try {
    return $this->tenantProvider->findBySlug((string) $slug);
```

**After:**
```php
if (!is_string($slug) || '' === trim($slug)) {
    return null;
}

try {
    return $this->tenantProvider->findBySlug($slug);
```

Two changes:
1. **Type-narrow first** via `is_string($slug)` — pattern-aligned with `ConsoleResolver` L65 (`if (!\is_string($slug) || '' === $slug)`). `Request::query->get()` returns `string|null` in the dominant case; the old `null === $slug` pattern handled null but the new pattern's `is_string` also rejects array-shaped query params (e.g. `?_tenant[]=a&_tenant[]=b`) cleanly.
2. **Trim-aware empty check** — `'' === trim($slug)` rejects whitespace-only slugs like `?_tenant=%20%20%20`. The old pattern would have accepted `'   '` (three spaces) and passed it to `findBySlug()`. CONTEXT.md D-03 explicitly called out the `trim()` addition as part of WR-03.

The `(string) $slug` cast on the findBySlug() argument is removed — the if-guard has already proven `$slug` is a string, and PHPStan level 9 accepts the narrowed type. (The cast was load-bearing under the OLD pattern because `null` and `array` would both fall through; under the new pattern it's redundant.)

**New regression test:** `testReturnsNullWhenParamWhitespeaceOnly` constructs `Request::create('/?_tenant=%20%20%20')` and asserts the resolver returns null without calling findBySlug.

Commit: **b143597**.

### WR-04 — TenantRunCommand @security trust-boundary docblock

**File:** `src/Command/TenantRunCommand.php`

**Before:** Inline comment block at L55-60 documents the array-argv defense ("shell metacharacters in any token are inert" — closing the original shell-injection vector that v0.3.0 fixed). No `@security` PHPDoc tag — the trust-model concern was implicit.

**After:** A PHPDoc `/** @security ... */` block added IMMEDIATELY ABOVE the existing inline comment. The new docblock:

- Names the trust boundary explicitly ("the caller of `tenancy:run` is a developer at the CLI; `$commandString` is read from `$input->getArgument('command_string')` and is NOT escaped").
- Cites the v0.3.0 fix that switched from `Process::fromShellCommandline()` (shell-quoted, the historical vector) to `new Process(array $argv)` (execve-direct, the current safe call).
- Warns against re-exposing the command via HTTP, queued jobs, or any context where untrusted input can reach `$commandString` — the array-argv defense covers shell metas but a malicious token could still invoke unintended `bin/console` commands.

**Note on scope:** The actual shell-injection vector is GONE at HEAD. WR-04 reduces to documentation-only (the planner anticipated this — see CONTEXT.md D-04 / objective L74). No executable code changed.

Commit: **b143597**.

### IN-01..IN-04 — ZeroConfigKernelBootTest cosmetic + safety cleanup

**File:** `tests/Integration/ZeroConfigKernelBootTest.php`

| # | Change | Rationale |
|---|--------|-----------|
| IN-01 | Drop stale class docblock framing ("MUST fail on master before plans 18-09/18-10 land") + `@group canary-red` annotation. Reframe as "Permanent GREEN-bar regression gate: zero-config kernel boot". | Plans 18-09/18-10 landed; the canary is now green in the default suite, not RED-bar. The stale framing misled future readers about what the test is for. |
| IN-02 | Drop `$application->setCatchExceptions(false);` in `testConsoleApplicationVersionCommandExitsZero`. | If the resolver throws on instantiation under a regression, letting the exception propagate bypasses the `assertSame($status, 0)` diagnostic message and leaves only the PHPUnit stack trace. With default exception handling, `ApplicationTester` captures the error into `getDisplay()` so the assertion message surfaces both status code AND captured output — clearer regression messages. (Implementation follows 18-VERIFICATION.md L41 intent, NOT CONTEXT.md's `expectException` misquote — the test is GREEN-path, not a deliberate-throw test.) |
| IN-03 | Add `getmypid()` to `ZeroConfigTestKernel::getCacheDir()` and `getLogDir()` md5 input: `md5(static::class.$this->environment.getmypid())`. | Prevents parallel PHPUnit processes (paratest workers) from colliding on the shared `sys_get_temp_dir()` path. Stable within a single process run. |
| IN-04 | Replace the `foreach ([$cacheDir, $logDir] as $dir) { $parent = \dirname($dir); ... }` loop with a single removal of the shared parent dir. | `$cacheDir` and `$logDir` resolve to siblings under the SAME parent (see `getCacheDir()` / `getLogDir()`); the second removal was a no-op that implied per-path semantics that don't exist. |

All four edits land in a single commit.

Commit: **283e0fa**.

### IN-05 — SKIPPED (cs-fixer conflict)

**File targeted:** `src/Messenger/TenantWorkerMiddleware.php`

**Plan directive:** Add `use Tenancy\Bundle\Messenger\TenantStamp;` to the import block at top-of-file. The current `TenantStamp::class` reference at L29 relies on implicit same-namespace resolution — IN-05 flagged this as "minor consistency drift".

**Why skipped:** The project's `.php-cs-fixer.dist.php` enables `@Symfony` ruleset, which includes the `no_unused_imports` rule. PHP-CS-Fixer's heuristic considers same-namespace imports redundant (the bare name resolves identically without the `use`) and auto-strips them. Verified by running `vendor/bin/php-cs-fixer check --diff src/Messenger/TenantWorkerMiddleware.php` after adding the import — the diff output shows cs-fixer reverting the change.

The project's enforced code-style config polices same-namespace imports in the OPPOSITE direction from IN-05's "consistency" goal. The pre-commit hook would either revert the change or fail. Either way IN-05's acceptance criterion (`grep -c "^use Tenancy\\\\Bundle\\\\Messenger\\\\TenantStamp;" src/Messenger/TenantWorkerMiddleware.php` returns 1) is unachievable without disabling `no_unused_imports` — which would be a much larger policy decision out of scope for a closure phase.

**Recommendation for follow-up:** None. The audit-flagged "drift" doesn't exist in the project's enforced style; IN-05 is moot.

## Verification

| Gate | Result |
|------|--------|
| `vendor/bin/phpunit --no-coverage` | OK (568 tests, 2122 assertions) |
| `vendor/bin/phpstan analyse --memory-limit=512M` | [OK] No errors |
| `vendor/bin/php-cs-fixer check --diff` | files=[] (clean) |
| Pre-commit hook on each task commit | Passed (php-cs-fixer + PHPStan + PHPUnit run automatically) |
| WR-02 grep gates | `GUARD ORDERING` count: 1; guard-precedes-mutation awk: PASS |
| WR-03 grep gates | `is_string($slug)`: 1, `trim($slug)`: 1, old pattern: 0 |
| WR-04 grep gate | `@security`: 1 |
| IN-01 grep gates | `@group canary-red`: 0, `MUST fail on master`: 0, `Permanent GREEN-bar regression gate`: 1 |
| IN-02 grep gate | `setCatchExceptions(false)`: 0 |
| IN-03 grep gate | `getmypid()`: 3 (2 method bodies + 1 design-rationale comment) |
| IN-04 grep gate | `foreach \(\[$cacheDir, $logDir\]`: 0 |
| Canary test still green | `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php` → OK (3 tests, 6 assertions) |

## Test count delta

| Metric | Before (commit f2d40ad) | After (commit 283e0fa) | Δ |
|--------|------------------------:|-----------------------:|--:|
| Total tests | 565 | 568 | +3 |
| Total assertions | 2115 | 2122 | +7 |

Tests added:
- `tests/Unit/Resolver/QueryParamResolverTest::testReturnsNullWhenParamWhitespaceOnly` (WR-03 regression).
- `tests/Unit/Resolver/ConsoleResolverGuardOrderingTest::testGuardPrecedesApplicationMutation` (WR-02 source-order tripwire).
- `tests/Unit/Resolver/ConsoleResolverGuardOrderingTest::testGuardOrderingCommentBlockExists` (WR-02 comment-marker tripwire).

## Commits

| Hash | Type | Summary |
|------|------|---------|
| **b143597** | chore | WR-02/03/04 — guard-ordering comment + trim()-aware slug check + @security docblock |
| **fcdfaf1** | test | WR-02 — pin guard-precedes-mutation invariant via source-order scan |
| **283e0fa** | test | IN-01..IN-04 — ZeroConfigKernelBootTest canary cleanup |

## Self-Check: PASSED

- `src/Resolver/ConsoleResolver.php` exists and contains `GUARD ORDERING` comment block — verified.
- `src/Resolver/QueryParamResolver.php` exists and uses `is_string($slug) && '' !== trim($slug)` — verified.
- `src/Command/TenantRunCommand.php` exists and contains `@security` PHPDoc above Process call site — verified.
- `tests/Unit/Resolver/ConsoleResolverGuardOrderingTest.php` exists — verified (107 LOC, 2 tests pass).
- `tests/Integration/ZeroConfigKernelBootTest.php` no longer contains `@group canary-red` or `setCatchExceptions(false)` — verified.
- `tests/Unit/Resolver/QueryParamResolverTest.php` contains new `testReturnsNullWhenParamWhitespaceOnly` test — verified (passes).
- Commit `b143597` exists in `git log --oneline --all` — verified.
- Commit `fcdfaf1` exists in `git log --oneline --all` — verified.
- Commit `283e0fa` exists in `git log --oneline --all` — verified.

## Deviations from plan

1. **IN-05 skipped (cs-fixer conflict).** Documented in detail above. The project's php-cs-fixer config polices same-namespace imports in the opposite direction from IN-05's "consistency" advice. Rule 4 candidate downgraded to "skip with rationale" because no architectural change is needed — the project has ALREADY made its decision via the cs-fixer config.

2. **WR-04 reduced to docblock-only.** Anticipated by the planner (CONTEXT.md D-04 / 23-03-PLAN.md objective L74). The shell-injection vector is GONE at HEAD (`new Process($command)` array-argv at L71); the audit finding survives as a documentation gap, closed by the new `@security` PHPDoc.

3. **WR-03 cast removal.** Plan said "leave the `(string) $slug` cast for explicitness; PHPStan level 9 may demand it depending on inference". In practice, PHPStan level 9 accepts the narrowed type after `is_string($slug)` and the cast is reported as redundant; removed it. Verified clean by `vendor/bin/phpstan analyse src/Resolver/QueryParamResolver.php` → [OK] No errors.

4. **IN-02 follows 18-VERIFICATION.md L41, NOT CONTEXT.md's `expectException` misquote.** The plan body (Task 3 / IN-02) explicitly flagged the CONTEXT.md text as incorrect and instructed to keep the assertSame pattern, just dropping the `setCatchExceptions(false)` line. Followed the plan body's interpretation.

## Known Stubs

None. No placeholder code, hardcoded empty values, or "TODO" markers introduced.

## Threat Flags

None. No new network endpoints, auth paths, file-access patterns, or schema changes. The WR-04 `@security` docblock DOCUMENTS an existing trust boundary; it does not introduce a new one.
