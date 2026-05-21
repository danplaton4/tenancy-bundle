---
phase: 18-tenancy-install
verified: 2026-05-21T00:00:00Z
status: passed
score: 8/8
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 5/5 (original truths) — gaps_found after human UAT
  gaps_closed:
    - "4 read-path resolvers (HostResolver/HeaderResolver/QueryParamResolver/ConsoleResolver) now have ?TenantProviderInterface constructors with fail-silent null guards"
    - "2 write-path consumers (TenantRunCommand/TenantWorkerMiddleware) now have ?TenantProviderInterface constructors with fail-loud RuntimeException guards"
    - "Canary regression test ZeroConfigKernelBootTest.php passes (3/3 tests, GREEN bar, 548 total tests)"
    - "CHANGELOG.md [Unreleased] ### Fixed block documents the fix, all 6 sites, DX-06, affected versions"
    - "README nikic/php-parser callout added to Quick Start"
    - "phpunit.xml.dist canary-red exclusion removed — canary is now in the default suite"
  gaps_remaining: []
  regressions: []
advisory_findings:
  # From 18-REVIEW.md — recorded here for completeness; none block the phase goal
  - id: CR-01
    severity: warning
    summary: "Nullable-with-default (= null) applied to 3 HTTP resolvers but NOT to ConsoleResolver/TenantRunCommand/TenantWorkerMiddleware — inconsistent. No enforcement (PHPStan rule or contract test) guards against a future contributor dropping the ? and resurrecting the TypeError. Functional today because the DI container always passes null via nullOnInvalid()."
  - id: WR-01
    severity: warning
    summary: "RuntimeException is the wrong exception class for a misconfiguration signal in TenantRunCommand and TenantWorkerMiddleware — should be LogicException or a dedicated MissingTenantProviderException to prevent Messenger retry semantics from treating it as a transient failure."
  - id: WR-02
    severity: warning
    summary: "ConsoleResolver mutates global Application definition (adds --tenant option) — guard ordering is fragile. Early-return is above the mutation today but a future refactor could move it below."
  - id: WR-03
    severity: warning
    summary: "QueryParamResolver empty-string check uses null/empty-string pattern instead of is_string() guard used by ConsoleResolver — minor intra-bundle consistency drift."
  - id: WR-04
    severity: warning
    summary: "TenantRunCommand injects unescaped $commandString into a shell command line via Process::fromShellCommandline — pre-existing command-injection vector (intended caller is a developer, but worth documenting the trust boundary)."
  - id: IN-01
    severity: info
    summary: "ZeroConfigKernelBootTest class docblock still says 'MUST fail on master before plans 18-09/18-10 land' and carries @group canary-red — stale framing after those plans landed."
  - id: IN-02
    severity: info
    summary: "setCatchExceptions(false) in testConsoleApplicationVersionCommandExitsZero may suppress the diagnostic assertion message if an exception propagates."
  - id: IN-03
    severity: info
    summary: "ZeroConfigTestKernel cache-dir hash is static::class + env — no PID/run-id, potential race on parallel PHPUnit processes."
  - id: IN-04
    severity: info
    summary: "tearDownAfterClass removes the shared parent dir twice in a loop (second removal is a no-op, cosmetically misleading)."
  - id: IN-05
    severity: info
    summary: "TenantWorkerMiddleware references TenantStamp::class without an explicit use statement — implicit same-namespace reference, minor consistency drift."
---

# Phase 18: tenancy-install Verification Report

**Phase Goal:** Ship a one-shot installer that lets a freshly-installed Symfony app boot the tenancy bundle with zero manual config edits. Specifically: `composer require danplaton4/tenancy-bundle` followed by `bin/console tenancy:install` (or just `composer require` alone for the zero-config bootability sub-contract) must result in a kernel that COMPILES and BOOTS cleanly — no TypeError, no missing-service error — even before any `tenancy:` config block is present.
**Verified:** 2026-05-21T00:00:00Z
**Status:** PASSED
**Re-verification:** Yes — gap-closure re-verification after plans 18-08..18-11

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `bin/console tenancy:install` on a fresh Symfony skeleton registers TenancyBundle in `config/bundles.php` and writes `config/packages/tenancy.yaml`, exits 0 | VERIFIED (carried from prior verification + gap closure removes the boot blocker) | All original install-command tests pass (7 integration tests, 33 assertions); the 2026-05-21 human UAT boot blocker is now closed |
| 2 | Re-running `bin/console tenancy:install` is idempotent | VERIFIED | `TenancyInstallCommandIdempotencyTest` — 3 consecutive runs, bytes identical after run 1 |
| 3 | `bin/console tenancy:install --dry-run` prints proposed mutation without writing | VERIFIED | `testDryRunDoesNotWrite` + `testDryRunSkipsTenancyInitInvocation` pass |
| 4 | On all 6 fixture-corpus shapes the command either succeeds or refuses cleanly | VERIFIED | 18 `BundlesPhpInstallerTest` tests, 70 assertions |
| 5 | `nikic/php-parser` is in `require-dev` only and loaded lazily | VERIFIED | `ComposerJsonContractTest` 3/3 passing; `class_exists(ParserFactory::class)` guard confirmed |
| 6 | A zero-config kernel (TenancyBundle with NO `tenancy:` extension block) compiles and boots without TypeError | VERIFIED | `ZeroConfigKernelBootTest::testContainerCompilesAndKernelBoots` PASS; `testHostResolverInstantiatesWithNullProvider` PASS; `testConsoleApplicationVersionCommandExitsZero` PASS. Empirical: `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php --no-coverage` → `OK, but there were issues! Tests: 3, Assertions: 6, Risky: 1` (risky = known PHPUnit 11 exception-handler note, not a failure) |
| 7 | All 6 defect-site constructors now accept `?TenantProviderInterface` with appropriate null handling | VERIFIED | Grep confirms `?TenantProviderInterface` in all 6 files; fail-silent guards (early `return null;` / `return;`) in 4 resolvers; fail-loud `\RuntimeException` in TenantRunCommand + TenantWorkerMiddleware. `grep -lE "\?TenantProviderInterface" ...6 files... \| wc -l` → 6 |
| 8 | Full PHPUnit suite (548 tests) passes after gap-closure changes; PHPStan level 9 clean; php-cs-fixer clean | VERIFIED | Empirical: `vendor/bin/phpunit --no-coverage` → `OK, but there were issues! Tests: 548, Assertions: 2017, PHPUnit Deprecations: 1`; `vendor/bin/phpstan analyse --memory-limit=512M` → `[OK] No errors`; `vendor/bin/php-cs-fixer check --diff` → exit 0, no files changed |

**Score:** 8/8 truths verified

---

### Gap-Closure: Six-Site Defect Inventory — All Closed

The prior verification identified 6 defect sites where `nullOnInvalid()` DI wiring met non-nullable constructors, causing TypeError on fresh installs. All 6 are now fixed:

| Site | Constructor Before | Constructor After | Guard Type | Status |
|------|--------------------|-------------------|------------|--------|
| `src/Resolver/HostResolver.php:15` | `TenantProviderInterface $tenantProvider` | `?TenantProviderInterface $tenantProvider = null` | fail-silent (return null) | CLOSED |
| `src/Resolver/HeaderResolver.php:17` | `TenantProviderInterface $tenantProvider` | `?TenantProviderInterface $tenantProvider = null` | fail-silent (return null) | CLOSED |
| `src/Resolver/QueryParamResolver.php:17` | `TenantProviderInterface $tenantProvider` | `?TenantProviderInterface $tenantProvider = null` | fail-silent (return null) | CLOSED |
| `src/Resolver/ConsoleResolver.php:21` | `TenantProviderInterface $tenantProvider` | `?TenantProviderInterface $tenantProvider` (no default) | fail-silent (return void) | CLOSED |
| `src/Command/TenantRunCommand.php:19` | `TenantProviderInterface $tenantProvider` | `?TenantProviderInterface $tenantProvider` (no default) | fail-loud (RuntimeException) | CLOSED |
| `src/Messenger/TenantWorkerMiddleware.php:21` | `TenantProviderInterface $tenantProvider` | `?TenantProviderInterface $tenantProvider` (no default) | fail-loud (RuntimeException) | CLOSED |

**Note on CR-01 (advisory, not a blocker):** Three HTTP resolvers received `= null` defaults while the three remaining sites (ConsoleResolver, TenantRunCommand, TenantWorkerMiddleware) are nullable-without-default. This is a consistency gap flagged in 18-REVIEW.md as a drift risk. The container always supplies the null via `nullOnInvalid()` so there is no functional regression, but there is no enforcement (no PHPStan rule, no contract test) preventing a future contributor from removing the `?` and resurrecting the TypeError. Recommend addressing in a follow-up plan.

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Resolver/HostResolver.php` | `?TenantProviderInterface $tenantProvider = null` + null guard | VERIFIED | Line 15: `= null`; line 23: `null === $this->tenantProvider` guard; `return null;` |
| `src/Resolver/HeaderResolver.php` | Same pattern | VERIFIED | Line 17: `= null`; line 24: guard present |
| `src/Resolver/QueryParamResolver.php` | Same pattern | VERIFIED | Line 17: `= null`; line 24: guard present |
| `src/Resolver/ConsoleResolver.php` | `?TenantProviderInterface $tenantProvider` (no default) + void no-op guard | VERIFIED | Line 21: nullable no-default; line 31: `null === $this->tenantProvider → return;` |
| `src/Command/TenantRunCommand.php` | `?TenantProviderInterface $tenantProvider` + fail-loud RuntimeException | VERIFIED | Line 19: nullable; lines 35-37: `null === $this->tenantProvider → throw new \RuntimeException(...)` citing `tenancy:install` |
| `src/Messenger/TenantWorkerMiddleware.php` | `?TenantProviderInterface $tenantProvider` + fail-loud RuntimeException inside stamp branch | VERIFIED | Line 21: nullable; lines 34-36: guard after null-stamp early-return (no-stamp path stays throwless) |
| `tests/Integration/ZeroConfigKernelBootTest.php` | 3-test canary: `testContainerCompilesAndKernelBoots`, `testHostResolverInstantiatesWithNullProvider`, `testConsoleApplicationVersionCommandExitsZero` | VERIFIED | All 3 tests pass; `@group canary-red` annotation present (stale — see IN-01 advisory); `ZeroConfigTestKernel` + `RemoveTenancyProviderPass` + `MakeZeroConfigServicesPublicPass` inline; no `loadFromExtension('tenancy', ...)` call confirmed |
| `phpunit.xml.dist` | `canary-red` exclusion REMOVED — canary in default suite | VERIFIED | `grep -c "canary-red" phpunit.xml.dist` → 0 |
| `CHANGELOG.md` | `[Unreleased] ### Fixed` block with all 6 class names, DX-06 citation, affected versions | VERIFIED | `grep "^### Fixed" CHANGELOG.md` → 2 matches (one in [Unreleased]); all 6 class names present (grep count: 12); `DX-06` cited 2×; `v0.1.0`/`v0.2.0`/`v0.2.1` cited 6× |
| `README.md` | `composer require --dev nikic/php-parser` callout in Quick Start | VERIFIED | Line 24-26: blockquote present immediately after `bin/console tenancy:init` step; `grep -c "composer require --dev nikic/php-parser" README.md` → 1 |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `config/services.php` (nullOnInvalid) | HostResolver/HeaderResolver/QueryParamResolver/ConsoleResolver | `?TenantProviderInterface` nullable constructors | WIRED | DI side unchanged; consumer side signature now matches. `nullOnInvalid()` resolves to null → null guard fires → fail-silent |
| `config/services.php` (nullOnInvalid) | TenantRunCommand / TenantWorkerMiddleware | `?TenantProviderInterface` nullable constructors | WIRED | DI side unchanged; null guard fires only at use-time (execute() / handle()), not at container instantiation — zero-config kernel boots cleanly |
| `ZeroConfigTestKernel::build()` | `RemoveTenancyProviderPass` | `$container->addCompilerPass(new RemoveTenancyProviderPass())` | WIRED | Simulates absence of tenancy.provider; confirmed in test file lines 182-183 |
| `ZeroConfigTestKernel::build()` | `MakeZeroConfigServicesPublicPass` | `$container->addCompilerPass(new MakeZeroConfigServicesPublicPass())` | WIRED | Exposes resolver services for direct container fetch in tests |
| `ZeroConfigKernelBootTest` | 3 assertions | All 3 test methods reach `assert*` statements | WIRED | Empirically confirmed: 6 assertions pass in test run |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Canary: 3/3 zero-config tests pass | `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php --no-coverage` | `OK, but there were issues! Tests: 3, Assertions: 6, Risky: 1` | PASS |
| Full suite: 548 tests pass | `vendor/bin/phpunit --no-coverage` | `OK, but there were issues! Tests: 548, Assertions: 2017, PHPUnit Deprecations: 1` | PASS |
| PHPStan level 9 | `vendor/bin/phpstan analyse --memory-limit=512M` | `[OK] No errors` | PASS |
| php-cs-fixer @Symfony | `vendor/bin/php-cs-fixer check --diff` | Exit 0, no files changed | PASS |
| All 6 fix sites carry nullable signature | `grep -lE "\\?TenantProviderInterface" 6 files \| wc -l` | `6` | PASS |
| Fail-loud guards cite `tenancy:install` | `grep -E "tenancy:install" src/Command/TenantRunCommand.php src/Messenger/TenantWorkerMiddleware.php` | 2 matches | PASS |

---

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
|-------------|----------------|-------------|--------|----------|
| DX-06 | 18-01 through 18-11 | `tenancy:install` command — idempotent, `--dry-run`, AST detect, refuses non-standard shapes, atomic write; **PLUS zero-config bootability** (container boots cleanly with no `tenancy:` block) | SATISFIED | All 5 original ROADMAP success criteria verified (5/5 from prior verification); gap-closure sub-contract verified: canary 3/3 GREEN; `Closes DX-06` cited in CHANGELOG.md `### Fixed` block |

---

### Anti-Patterns Found

The four original code-review warnings (WR-01 through WR-04 from the prior verification) remain informational. The gap-closure code review (18-REVIEW.md) surfaced the following additional items:

| File | Finding | Severity | Impact |
|------|---------|----------|--------|
| `src/Resolver/ConsoleResolver.php`, `src/Command/TenantRunCommand.php`, `src/Messenger/TenantWorkerMiddleware.php` | CR-01: nullable-without-default vs nullable-with-default — inconsistent across 6 fix sites; no enforcement gate; drift risk for future regressions | Warning | No current functional impact. Container always passes null via nullOnInvalid(). Without a PHPStan rule or contract test, a contributor could drop the `?` and resurface the TypeError silently. |
| `src/Command/TenantRunCommand.php`, `src/Messenger/TenantWorkerMiddleware.php` | WR-01: `\RuntimeException` used for a misconfiguration signal; should be `LogicException` or dedicated `MissingTenantProviderException`. For TenantWorkerMiddleware, this may trigger Messenger retry semantics on what is actually a permanent config error. | Warning | Messenger retry waste / confusing behavior in async contexts |
| `src/Resolver/ConsoleResolver.php:52-61` | WR-02: Application definition mutation (adds --tenant option) is guarded, but guard position relative to mutation could regress if refactored. No defensive comment at mutation site. | Warning | Future refactor risk |
| `src/Resolver/QueryParamResolver.php:28-35` | WR-03: Empty-string check pattern inconsistent with ConsoleResolver; pre-cast `null/empty-string` check vs post-cast `is_string()` guard | Info | Minor consistency drift; no security or functional impact |
| `src/Command/TenantRunCommand.php:50-56` | WR-04 (pre-existing): Unescaped `$commandString` in `Process::fromShellCommandline` — command-injection vector if caller constructs command from user input | Warning | Pre-existing; trust boundary: intended caller is a developer. Document trust assumption. |
| `tests/Integration/ZeroConfigKernelBootTest.php:23-37` | IN-01: Stale class docblock ("MUST fail on master") and `@group canary-red` after plans 18-09/18-10 landed | Info | Misleads future readers about the test's role; breaks any CI selector using `--exclude-group canary-red` |
| `tests/Integration/ZeroConfigKernelBootTest.php:139-150` | IN-02: `setCatchExceptions(false)` — exception propagates before `assertSame(0, ...)` is reached, suppressing the diagnostic message | Info | Test still fails on regression, but failure message is a stack trace not the documented assertion |
| `tests/Integration/ZeroConfigKernelBootTest.php:207-215` | IN-03: Cache-dir hash uses `static::class + env` — no PID; races on parallel PHPUnit processes | Info | Not an issue for single-worker CI; add `getmypid()` to be safe |
| `tests/Integration/ZeroConfigKernelBootTest.php:62-68` | IN-04: `tearDownAfterClass` removes shared parent dir twice in loop | Info | Second removal is a no-op; cosmetically misleading |
| `src/Messenger/TenantWorkerMiddleware.php:28` | IN-05: `TenantStamp::class` referenced without explicit `use` statement (same-namespace implicit reference) | Info | Breaks silently if TenantStamp moves to a sub-namespace |

**Blocker anti-patterns:** None. CR-01 is a drift risk, not a current functional failure. All 548 tests pass.

---

### Human Verification Required

None. The prior human verification requirement (real `bin/console` invocation on a fresh skeleton) identified the boot blocker; that blocker has been closed by plans 18-08..18-11. The canary `ZeroConfigKernelBootTest` provides permanent automated regression coverage for the zero-config boot path.

The remaining gap — verifying that `bin/console tenancy:install` runs successfully end-to-end on a real fresh skeleton after the fix — is addressed by the canary test's `testConsoleApplicationVersionCommandExitsZero` (proves `bin/console` works in zero-config mode), combined with the installer's existing integration test suite (which independently proves the install command logic is sound). A full fresh-skeleton UAT re-run would be belt-and-suspenders and is not required to advance the phase.

---

### Gaps Summary

**Status:** PASSED — all gaps identified in the prior `gaps_found` verification are closed.

The zero-config boot regression that blocked the phase goal on 2026-05-21 is empirically resolved:
- All 6 defect-site constructors now accept `?TenantProviderInterface` with appropriate null handling.
- `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php` passes 3/3 tests (GREEN bar).
- `vendor/bin/phpunit` passes 548/548 tests.
- PHPStan level 9: 0 errors. php-cs-fixer: clean.

The advisory findings from 18-REVIEW.md (CR-01, WR-01 through WR-04, IN-01 through IN-05) are informational and do not block the phase goal. The most material follow-up items are CR-01 (add a contract test or PHPStan rule to lock the nullable-provider invariant) and WR-01 (introduce `MissingTenantProviderException extends LogicException` to fix Messenger retry semantics).

---

_Verified: 2026-05-21T00:00:00Z (re-verification after gap closure plans 18-08..18-11)_
_Verifier: Claude (gsd-verifier + empirical test runs)_
