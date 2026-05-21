---
phase: 18-tenancy-install
plan: "11"
subsystem: verification+docs
tags: [green-bar, changelog, readme, zero-config, canary, dx-06, gap-closure]

dependency_graph:
  requires:
    - 18-08 (ZeroConfigKernelBootTest canary, RED-bar)
    - 18-09 (fail-silent resolver fixes)
    - 18-10 (fail-loud write-path fixes)
  provides:
    - GREEN-bar empirical proof: all 6 defect sites fixed, canary 3/3 passing
    - phpunit.xml.dist canary-red exclusion removed (548 tests in default suite)
    - CHANGELOG.md [Unreleased] ### Fixed block documenting the regression + fix
    - README.md Quick Start nikic/php-parser prerequisite callout
  affects:
    - Phase 18 verification status (gaps_found -> verified)

tech-stack:
  added: []
  patterns:
    - "canary-green promotion: remove canary-red phpunit exclusion once fixes land"

key-files:
  created: []
  modified:
    - phpunit.xml.dist
    - CHANGELOG.md
    - README.md

decisions:
  - "phpunit.xml.dist canary-red exclusion removed: canary is now GREEN; promotes it to the permanent default regression gate"
  - "CHANGELOG Fixed policy distinction documented: fail-silent (resolver chain) vs fail-loud (write path) to make the fix rationale clear to users upgrading from affected versions"
  - "README callout is a blockquote under step 1: minimal, terse, does not restructure existing content, mirrors the tone of the surrounding Quick Start prose"

requirements-completed:
  - DX-06

metrics:
  duration: ~7min
  completed_date: "2026-05-21"
  tasks_completed: 2
  files_modified: 3
---

# Phase 18 Plan 11: GREEN-Bar Verification + CHANGELOG + README Docs Summary

**Capstone plan: empirical GREEN-bar proof that the zero-config boot regression (DX-06) is closed, with CHANGELOG and README documentation for the v0.2.2 patch release.**

---

## Verification Matrix Results

All 6 verification commands green-bar.

### 1. Canary Regression Test (ZeroConfigKernelBootTest)

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.12
Configuration: phpunit.xml.dist (canary-red exclusion removed)

R..                                                                 3 / 3 (100%)

Time: 00:00.305, Memory: 26.00 MB

Zero Config Kernel Boot (Tenancy\Bundle\Tests\Integration\ZeroConfigKernelBoot)
 ⚠ Container compiles and kernel boots
 ✔ Host resolver instantiates with null provider
 ✔ Console application version command exits zero

There was 1 risky test:

1) ZeroConfigKernelBootTest::testContainerCompilesAndKernelBoots
   Test code or tested code did not remove its own exception handlers

OK, but there were issues!
Tests: 3, Assertions: 6, PHPUnit Deprecations: 1, Risky: 1.
```

The "risky" flag on `testContainerCompilesAndKernelBoots` is the known PHPUnit 11 kernel boot exception-handler housekeeping note (confirmed in plans 18-08 and 18-09). It is not a test failure. All 3 assertions pass.

**RED bar (plan 18-08):** `ERRORS! Tests: 3, Assertions: 4, Errors: 2`
**GREEN bar (plan 18-11):** `OK, but there were issues! Tests: 3, Assertions: 6` (the 2 extra assertions are the newly-passing `assertInstanceOf` and `assertSame(0, ...)` calls)

### 2. Full Suite (canary included via phpunit.xml.dist exclusion removal)

```
Time: 00:05.019, Memory: 72.50 MB

OK, but there were issues!
Tests: 548, Assertions: 2017, PHPUnit Deprecations: 1.
```

**Pre-fix baseline (plan 18-08, canary excluded):** 545 tests, 2011 assertions
**Post-fix with canary in default suite:** 548 tests, 2017 assertions

Uplift: +3 tests, +6 assertions (the 3 canary test methods now in the default suite).

### 3. Unit Testsuite

```
OK (425 tests, 1149 assertions)
```

Baseline per 18-VERIFICATION.md: 287 tests (at Phase 18 verification time). Current 425 reflects tests added in phases 17-18 (OriginHeaderResolver + tenancy:install tests). No regressions.

### 4. Integration Testsuite

```
OK, but there were issues!
Tests: 120, Assertions: 862, PHPUnit Deprecations: 1.
```

All integration tests pass. PHPUnit deprecation is the known Symfony test framework note.

### 5. PHPStan Level 9

```
 [OK] No errors
```

66 source files analysed, 0 errors. Note: required `--memory-limit=512M` for the first (cold) run; subsequent runs (including the pre-commit hook) succeed with cached result within default 128M limit.

### 6. php-cs-fixer @Symfony

```
Exit code: 0
(no files changed)
```

### Nullable Signature Check (6 fix sites)

```bash
grep -lE "\?TenantProviderInterface" \
  src/Resolver/HostResolver.php \
  src/Resolver/HeaderResolver.php \
  src/Resolver/QueryParamResolver.php \
  src/Resolver/ConsoleResolver.php \
  src/Command/TenantRunCommand.php \
  src/Messenger/TenantWorkerMiddleware.php | wc -l
# → 6
```

All 6 defect sites confirmed to carry the nullable constructor signature.

---

## CHANGELOG.md Diff

The following `### Fixed` block was added to `## [Unreleased]` (after the existing `### Added` entries):

```markdown
### Fixed

- **Zero-config kernel boot regression** — bundle now constructs cleanly with no
  `tenancy:` config block present (e.g. immediately after `composer require` on a
  fresh Symfony skeleton before `bin/console tenancy:install` has been run).
  - **Root cause:** 6 service classes were wired with
    `service('tenancy.provider')->nullOnInvalid()` in `config/services.php` but
    declared their `TenantProviderInterface` constructor parameter as non-nullable.
    On a zero-config install where no `tenancy:` extension block is loaded,
    `tenancy.provider` is absent and `nullOnInvalid()` resolves to `null`. PHP 8.x
    strict typing then throws `TypeError` during `cache:clear` (or any subsequent
    `bin/console` invocation), making `bin/console tenancy:install` unreachable.
  - **Fix — read-only resolver sites (fail-silent):** `HostResolver`,
    `HeaderResolver`, `QueryParamResolver`, and `ConsoleResolver` now declare
    `?TenantProviderInterface` and return `null` / early-return void at the top of
    their active method when the provider is absent. The resolver chain falls
    through to null-resolution, which the system already handles.
  - **Fix — write-path sites (fail-loud):** `TenantRunCommand` and
    `TenantWorkerMiddleware` now declare `?TenantProviderInterface` and throw
    `\RuntimeException` with an actionable message directing the user to
    `bin/console tenancy:install` when invoked without a configured provider.
    Silent no-op on the write path would risk data-correctness issues; fail-loud
    is the safer policy.
  - **Versions affected:** v0.1.0, v0.2.0, v0.2.1 — all users on those tags should
    upgrade. The defect predates Phase 18 and was discovered during human UAT on
    2026-05-21.
  - **Regression coverage:** `tests/Integration/ZeroConfigKernelBootTest.php` now
    exercises the previously-uncovered zero-config code path (container compile,
    resolver instantiation, `bin/console list` exit 0) as a permanent regression
    gate. Closes DX-06. Audit source: `.planning/phases/18-tenancy-install/18-VERIFICATION.md`.
```

---

## README.md Diff

The following blockquote was added immediately after the `bin/console tenancy:init` line in Quick Start step 1:

```markdown
> **Automated setup:** `bin/console tenancy:install` handles both steps above in one command.
> It requires `nikic/php-parser` to AST-parse `config/bundles.php` — install it first:
> `composer require --dev nikic/php-parser`. Without it the command exits 1 with a clear
> error (per DEC-INST-02); it is listed in `composer suggest`, not `require`.
```

4 lines, terse, no marketing tone. The secondary finding from 18-HUMAN-UAT.md ("user must `composer require --dev nikic/php-parser` before the installer can run") is now surfaced in the README quick-start.

---

## Task Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1 — canary-red exclusion removed | `62bbe5d` | chore(18-11): remove canary-red exclusion from phpunit.xml.dist |
| 2 — CHANGELOG + README docs | `ed913ba` | docs(18-11): add CHANGELOG Fixed entry and README nikic/php-parser callout |

---

## Deviations from Plan

None — plan executed exactly as specified.

The vendor delegate setup (symlinks + custom `autoload.php`) for the worktree is infrastructure, not a plan deviation. The previous executor (18-10) also set up a delegate; this worktree required the same treatment since it was spawned fresh.

---

## Phase 18 Status Recommendation

**Phase 18 verification status should flip from `gaps_found` to `verified`.**

All 3 items in 18-VERIFICATION.md `## Gaps` are now empirically closed:
1. Container compiles and kernel boots in zero-config mode — PASS (canary GREEN)
2. HostResolver/HeaderResolver/QueryParamResolver/ConsoleResolver instantiate with null provider — PASS
3. `bin/console list` exits 0 in zero-config mode — PASS

Recommend running `/gsd:verify-phase 18` (re-verification pass) to update 18-VERIFICATION.md frontmatter `status:` from `gaps_found` to `verified`. Alternatively, a human can update the frontmatter field directly.

---

## Next Steps (outside this plan)

- **Tag `v0.2.2`** as a patch release containing the zero-config boot fix. Decision belongs to the user — do not tag automatically. Users on v0.1.0, v0.2.0, v0.2.1 should upgrade.
- The 4 code-review warnings (WR-01 through WR-04) from 18-VERIFICATION.md remain informational and are not in this plan's scope.

---

## Self-Check: PASSED

| Item | Status |
|------|--------|
| phpunit.xml.dist canary-red exclusion removed | FOUND (file modified, groups block removed) |
| Canary: 3/3 tests pass | CONFIRMED (transcript above) |
| Full suite: 548 tests exit 0 | CONFIRMED (transcript above) |
| PHPStan level 9: 0 errors | CONFIRMED |
| php-cs-fixer: exit 0 | CONFIRMED |
| CHANGELOG.md `### Fixed` under `[Unreleased]` | FOUND |
| All 6 defect site class names in CHANGELOG | CONFIRMED (grep count: 12, 6+ per defect) |
| DX-06 cited in CHANGELOG Fixed block | CONFIRMED |
| v0.1.0/v0.2.0/v0.2.1 in CHANGELOG Fixed block | CONFIRMED |
| README.md nikic/php-parser callout | FOUND |
| `composer require --dev nikic/php-parser` in README | FOUND |
| Commit 62bbe5d | FOUND |
| Commit ed913ba | FOUND |
| No modifications to STATE.md, ROADMAP.md, REQUIREMENTS.md | CONFIRMED |

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-21*
