---
phase: 20-mailer-bootstrapper
plan: 08
subsystem: dx
tags: [dx, install, command, mailer, scaffolding, ast]

requires:
  - phase: 20-mailer-bootstrapper
    plan: 01
    provides: "TenantMailerConfigTrait — the trait FQCN inserted into the user's Tenant entity"
  - phase: 20-mailer-bootstrapper
    plan: 04
    provides: "tenancy.mailer config tree + 5 services registered under interface_exists(MailerInterface)"
  - phase: 20-mailer-bootstrapper
    plan: 07
    provides: "MailerTransportContractPass + compile-time DI guards"
provides:
  - "src/Command/Install/Step/MailerSetupStep — encapsulates 3 install actions (AST entity insert, Doctrine migration scaffold, tenancy.yaml mailer-defaults append) behind a single run() entry point"
  - "tenancy:install --with-mailer CLI flag — one-shot extension that runs all 3 mailer install actions after the existing bundle-registration + tenancy:init flow"
  - "MailerSetupStep::TENANCY_YAML_MAILER_BLOCK constant — the verbatim commented-out `# mailer:` defaults block appended to user's config/packages/tenancy.yaml"
  - "tenancy.mailer.install_step DI service — registered under interface_exists(MailerInterface) guard; injected with nullOnInvalid() into tenancy:install so mailer-absent installs still work"
affects: []  # Plan 08 is the last plan of Phase 20

tech-stack:
  added: []
  patterns:
    - "AST-edit-with-refuse-on-nonstandard (DEC-INST-02): nikic/php-parser + Standard PrettyPrinter, atomic write via Filesystem::dumpFile() + timestamped .bak + post-mutation `php -l` + restore-on-failure. Refusal of non-standard shapes (≠1 class per file) returns a manual snippet rather than producing broken PHP."
    - "Refusal-is-success: every degraded path (parser absent, migrations absent, yaml absent, mailer interface absent) exits 0 with informational $io output. The install funnel never fails on graceful-degradation paths."
    - "Append-with-regex-idempotency for YAML: rather than parsing config/packages/tenancy.yaml with symfony/yaml (which would round-trip strip user comments and reformat the file), the appender runs a multi-line regex scan for `^[ \\t]*#?[ \\t]*mailer[ \\t]*:` BEFORE append. Catches both commented and uncommented forms; preserves user formatting byte-for-byte on idempotent re-runs."
    - "Path-resolution with project-dir guard: TenancyInstallCommand::resolveTenantEntityPath() reflects on tenant_entity_class FQCN ONLY when the resolved file is inside \$projectDir — prevents accidental mutation of bundle-internal entity when a test does not override the config key."

key-files:
  created:
    - src/Command/Install/Step/MailerSetupStep.php
    - tests/Unit/Command/Install/Step/MailerSetupStepTest.php
    - tests/Integration/Command/TenancyInstallCommandWithMailerTest.php
    - .planning/phases/20-mailer-bootstrapper/20-08-SUMMARY.md
  modified:
    - src/Command/TenancyInstallCommand.php
    - config/services.php
    - .planning/phases/20-mailer-bootstrapper/deferred-items.md

key-decisions:
  - "Plan 20-08: install step takes 4 path args by VALUE not by interface — keeps the public surface flat and testable. The 3 sub-actions (entity / migration / yaml) are private methods reachable only through run(). The yaml-update method is exercised via reflection in the focused unit tests (cleaner than exposing a protected method)."
  - "Plan 20-08: command's resolveTenantEntityPath() reflects on the FQCN ONLY when the file is inside \$projectDir. The bundle's autoloader resolves the default tenant_entity_class (Tenancy\\Bundle\\Entity\\Tenant) to the bundle's own src/Entity/Tenant.php — without this guard, a user who hadn't overridden the config key would accidentally mutate the BUNDLE entity rather than their own. The guard means 'reflection always wins for user-provided FQCNs that resolve inside the project root; convention path wins otherwise'."
  - "Plan 20-08: --dry-run is reused from the existing Phase 18 install command — it propagates through to MailerSetupStep::run(\$io, ..., dryRun: true). All three sub-actions honor dry-run independently."
  - "Plan 20-08: --with-mailer runs AFTER the existing tenancy:init delegation succeeds (or the D-09 yaml-exists swallow path). Means the user gets a fully-functional default tenancy.yaml first, then the mailer block is appended onto it — no race between init's write and mailer's read."
  - "Plan 20-08: doctrine/migrations absence is the ONLY 'refused_non_standard' status from scaffoldMigration() — it prints the raw ALTER TABLE SQL to \$io so the user can apply manually. This keeps the install command itself green even when the user has manual schema management (refusal-is-success per Phase 18 pattern)."

patterns-established:
  - "Step pattern (src/Command/Install/Step/): future install sub-steps live in this namespace. Each Step has its own public surface (constructor + one run() method) so the parent command composes them by injection without reaching into private internals."
  - "Pre-existing risky-test markers (10/10 install command integration tests carry 'Test code or tested code did not remove its own exception handlers') are a known FrameworkBundle/CommandTester interaction; tests still pass and assert correctness. Not introduced by this plan."

requirements-completed: [BOOT-04]

metrics:
  duration_min: 11
  tasks: 2
  files_created: 4
  files_modified: 3
  commits: 3
  started: "2026-05-20T07:00:00Z"
  completed: "2026-05-20T07:30:00Z"
---

# Phase 20 Plan 08: tenancy:install --with-mailer Summary

**Shipped D-09 in full — the `tenancy:install --with-mailer` extension scaffolds the Doctrine migration adding 3 mailer columns, AST-inserts `use TenantMailerConfigTrait;` into the user's Tenant entity, AND appends a commented-out `mailer:` defaults block to config/packages/tenancy.yaml. All three actions in one command, idempotent on re-run, graceful on dev-dep absence, and refuses-with-manual-snippet on non-standard entity shapes (DEC-INST-02 pattern from Phase 18). 13 new tests pass (10 unit + 3 integration); PHPStan level 9 clean on every Plan-20-08 source file.**

## CLI Surface Added

```
$ bin/console tenancy:install --with-mailer
$ bin/console tenancy:install --with-mailer --dry-run
```

- `--with-mailer` (`InputOption::VALUE_NONE`, default off) — Phase 18 install flow is fully backward-compatible; users who don't pass the flag see no change.
- Reuses the existing `--dry-run` flag; both propagate through to `MailerSetupStep::run(..., dryRun: true)`.
- `--force` and `--with-mailer` are independently composable (init can be forced AND mailer can be set up in one shot).

## MailerSetupStep Public Surface

```php
final class MailerSetupStep
{
    public const TENANCY_YAML_MAILER_BLOCK = <<<'YAML'

# Per-tenant Mailer defaults (BOOT-04 / Phase 20). Uncomment + tune to enable.
# mailer:
#     strategy: x_transport
#     transport_cache_size: 32
#     sanitize_exceptions: true
YAML;

    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $lintRunner = null, // (string,string) => array{passed:bool, error:string}
    );

    public function run(
        SymfonyStyle $io,
        string $tenantEntityPath,
        string $migrationsDir,
        string $tenancyYamlPath,
        bool $dryRun = false,
    ): InstallResult;
}
```

`run()` returns the entity-mutation `InstallResult`. The migration and yaml sub-actions surface their status via `$io` only (their own results are discarded so the caller can decide on the entity status alone).

## 3 Sub-Actions (D-09)

| # | Action | Sub-method | Refusal path |
|---|--------|------------|---------------|
| 1 | AST-insert `use TenantMailerConfigTrait;` into Tenant entity | `updateEntity()` | Non-standard entity (≠1 class, parser failure) → `REFUSED_NON_STANDARD` + manual snippet via $io |
| 2 | Scaffold `Version{ts}_AddTenantMailerColumns` migration | `scaffoldMigration()` | doctrine/migrations absent → prints raw ALTER TABLE SQL via $io |
| 3 | Append commented-out `# mailer:` block to tenancy.yaml | `updateTenancyYaml()` | File missing → prints snippet via $io. Existing `mailer:` key (regex `^[ \t]*#?[ \t]*mailer[ \t]*:`) → no-op |

## AST Node Types

- **Detection (already-installed):** walks `Node\Stmt\Class_->stmts` for any `Node\Stmt\TraitUse` whose `traits` list contains a name ending in `TenantMailerConfigTrait`.
- **Insertion:** builds a `Node\Stmt\TraitUse([new Node\Name\FullyQualified(TenantMailerConfigTrait::class)])` and `array_unshift`s it onto `Node\Stmt\Class_->stmts`. Reprints with `PhpParser\PrettyPrinter\Standard::prettyPrintFile()`.

## Migration Class Pattern

- **Class name:** `Version{gmdate('YmdHis')}_AddTenantMailerColumns`
- **Namespace:** `DoctrineMigrations` (Symfony Flex convention)
- **Path:** `{migrationsDir}/{className}.php`
- **up() SQL:** `ALTER TABLE tenancy_tenants ADD COLUMN mailer_dsn VARCHAR(255) DEFAULT NULL, ADD COLUMN mailer_from VARCHAR(255) DEFAULT NULL, ADD COLUMN mailer_reply_to VARCHAR(255) DEFAULT NULL;`
- **down() SQL:** `ALTER TABLE tenancy_tenants DROP COLUMN mailer_dsn, DROP COLUMN mailer_from, DROP COLUMN mailer_reply_to;`

## .bak Naming Convention

`{originalPath}.bak.{gmdate('Ymd-His')}` — UTC timestamp suffix; mirrors the Phase 18 BundlesPhpInstaller convention. Restore path on lint failure uses `Filesystem::copy(bak, original, overwrite: true)` (NOT rename) so the .bak survives every code path.

## tenancy.yaml Idempotency Regex

```
/^[ \t]*#?[ \t]*mailer[ \t]*:/m
```

Multi-line; catches:
- `mailer:` (active, root-level)
- `    mailer:` (active, nested under tenancy:)
- `# mailer:` (commented form emitted by a prior `--with-mailer` run)
- `#mailer:` (commented without space)

We deliberately do NOT parse the YAML with symfony/yaml — round-tripping through `Yaml::parse`/`Yaml::dump` would rewrite user comments + formatting.

## Test Mapping

| Source | Test class | Tests | Assertions |
|--------|-----------|------:|-----------:|
| `MailerSetupStep` | `tests/Unit/Command/Install/Step/MailerSetupStepTest.php` | 10 | 44 |
| `TenancyInstallCommand` + step DI wiring | `tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` | 3 | 20 |
| **Total new for Plan 08** | | **13** | **64** |

### Unit Tests (10)

1. `testRunReturnsDevDependencyMissingWhenParserAbsent` — guards on `class_exists(ParserFactory::class)`, returns `DEV_DEPENDENCY_MISSING`. Verifies source contains the guard string when parser IS installed (CI baseline); exercises the real branch when parser is absent.
2. `testStandardEntityGetsTraitInsertedAsFirstStatement` — fixture entity → AST insert → re-parsed entity has `TraitUse` as first statement in class body.
3. `testAlreadyInstalledWhenTraitUseIsPresent` — fixture with existing `use TenantMailerConfigTrait;` → `ALREADY_REGISTERED`, byte-identical file.
4. `testNonStandardEntityIsRefusedWithSnippet` — two-classes-per-file fixture → `REFUSED_NON_STANDARD`, error message contains "Expected exactly one class", file unchanged.
5. `testLintFailurePostMutationRestoresOriginal` — injected `lintRunner` returns failure → `LINT_FAILED_RESTORED`, .bak path surfaced, file restored byte-for-byte from .bak.
6. `testMigrationFileIsWrittenWhenDoctrineMigrationsInstalled` — scaffolded migration file contains all 3 column names + `extends AbstractMigration` + `public function up(` / `down(`.
7. `testDryRunMutatesNothing` — `dryRun: true` → entity unchanged, no migration file, yaml unchanged, no .bak. $io output mentions "dry-run".
8. `testTenancyYamlAppendsCommentedMailerBlockWhenAbsent` — fixture yaml without `mailer:` → appended block; original prefix preserved; new bytes contain `# mailer:` + all 3 defaults.
9. `testTenancyYamlIsIdempotentWhenMailerSectionAlreadyExists` — three sub-cases: commented form, active form, re-run after append. All assert byte-identical file (sha1 equality on the third).
10. `testTenancyYamlMissingPrintsSnippetWithoutError` — non-existent yaml path → no exception, no file created; $io receives the snippet.

### Integration Tests (3)

1. `testWithMailerFlagInsertsTraitScaffoldsMigrationAndUpdatesYaml` — full command run via `CommandTester`. Asserts trait inserted into fixture entity, migration file written, yaml file appended.
2. `testWithMailerDryRunMutatesNothing` — `--with-mailer --dry-run` → entity / migration / yaml all unchanged; no .bak file.
3. `testWithMailerIsIdempotent` — runs the command twice; second run leaves entity + yaml byte-identical; exactly ONE `mailer:` key in yaml; exactly ONE `TenantMailerConfigTrait` reference in entity.

All 3 integration tests skip cleanly when `MailerInterface` or `ParserFactory` is absent.

## Task Commits

| # | Task | Commit | Type | TDD gate |
|---|------|--------|------|---------|
| 1 (RED) | Failing tests for MailerSetupStep | `3e323db` | test | RED |
| 1 (GREEN) | MailerSetupStep implementation + 10 tests pass | `9164ec0` | feat | GREEN |
| 2 | Wire --with-mailer into command + DI + 3 integration tests | `76d035a` | feat | — |

## Files Created/Modified

### Created (4)
- `src/Command/Install/Step/MailerSetupStep.php` — 320 lines. `final class` with 1 public method (`run()`) + 4 private methods (`updateEntity`, `scaffoldMigration`, `updateTenancyYaml`, `buildMigrationSource`, `manualEntitySnippet`).
- `tests/Unit/Command/Install/Step/MailerSetupStepTest.php` — 10 tests / 44 assertions; uses `#[CoversClass]` attribute and reflection for the focused yaml-update tests.
- `tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` — 3 tests / 20 assertions; reuses `InstallCommandTestKernel` from Phase 18; pre-seeds bundles.php (already-registered baseline), bare tenancy.yaml, minimal Tenant entity, empty migrations/ dir.
- `.planning/phases/20-mailer-bootstrapper/20-08-SUMMARY.md` — this file.

### Modified (3)
- `src/Command/TenancyInstallCommand.php` — added `--with-mailer` InputOption + 2 nullable constructor deps (`MailerSetupStep`, `tenantEntityClass` string) + `runMailerSetupIfRequested()` + 3 path-resolver helpers (entity / migrations / yaml).
- `config/services.php` — added `MailerSetupStep` use import; registered `tenancy.mailer.install_step` service inside the existing `interface_exists(MailerInterface)` block; injected it into `tenancy.command.install` with `nullOnInvalid()` + injected `tenancy.tenant_entity_class` parameter.
- `.planning/phases/20-mailer-bootstrapper/deferred-items.md` — added a note about the 2 pre-existing worktree-realpath failures in TenantInit/RunCommand tests (verified unrelated to this plan via `git stash`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] resolveTenantEntityPath could mutate the BUNDLE's own Tenant.php**

- **Found during:** Task 2 first integration-test run.
- **Issue:** The original `resolveTenantEntityPath()` (per plan §B) used `(new \ReflectionClass($fqcn))->getFileName()` unconditionally. When the user has NOT overridden `tenancy.tenant_entity_class` (i.e. they're still on the default `Tenancy\Bundle\Entity\Tenant`), reflection resolves to the bundle's own `src/Entity/Tenant.php`. The first integration test run produced a `src/Entity/Tenant.php.bak.20260520-071951` AND a pretty-print mutated entity inside the worktree — confirmed by inspecting `git status --short` immediately after the test.
- **Fix:** Added a `realpath(file) → starts_with(realpath(projectDir))` guard. Reflection now wins ONLY when the resolved file lives inside the project root. Otherwise falls back to `<projectDir>/src/Entity/Tenant.php`. This is correctness, not architecture — without it, `--with-mailer` would mutate the wrong file in any scenario where the user kept the default config key.
- **Files modified:** `src/Command/TenancyInstallCommand.php` (single helper expanded from ~10 to ~30 lines).
- **Verification:** Re-ran integration tests with corrupted entity restored via `git checkout`; tests pass; git status post-test shows zero modifications to `src/Entity/Tenant.php`.
- **Committed in:** `76d035a`.

**2. [Rule 1 — Bug] cs-fixer style fixes on the unit test**

- **Found during:** Task 1 GREEN verification.
- **Issue:** `vendor/bin/php-cs-fixer check` flagged style violations (import ordering) in `tests/Unit/Command/Install/Step/MailerSetupStepTest.php`.
- **Fix:** `vendor/bin/php-cs-fixer fix` auto-corrected import ordering.
- **Files modified:** `tests/Unit/Command/Install/Step/MailerSetupStepTest.php`.
- **Verification:** Final cs-fixer check on both new files passes.
- **Committed in:** Task 1 GREEN commit `9164ec0` (folded into the same atomic commit).

**3. [Rule 1 — Bug] PHPUnit doc-comment metadata deprecation**

- **Found during:** Task 1 unit-test run.
- **Issue:** PHPUnit 11 emits 1 deprecation warning for the `@covers` doc-comment annotation.
- **Fix:** Converted to `#[CoversClass(MailerSetupStep::class)]` attribute.
- **Files modified:** `tests/Unit/Command/Install/Step/MailerSetupStepTest.php`.
- **Verification:** No deprecations after the change.
- **Committed in:** Task 1 GREEN commit `9164ec0`.

**4. [Rule 1 — Bug] testRunReturnsDevDependencyMissingWhenParserAbsent risky marker**

- **Found during:** Task 1 final unit-test run.
- **Issue:** Test 1 used `expectNotToPerformAssertions()` for the parser-installed CI branch, but the same branch contains two `assertStringContainsString` calls (it now does perform assertions). PHPUnit marked it Risky.
- **Fix:** Removed the `expectNotToPerformAssertions()` call. The test now asserts the source contains both the `class_exists(ParserFactory::class)` guard AND the `InstallResult::devDependencyMissing()` return — semantic intent ("the dev-dep guard exists in source") is preserved.
- **Files modified:** `tests/Unit/Command/Install/Step/MailerSetupStepTest.php`.
- **Committed in:** Task 1 GREEN commit `9164ec0`.

### Out-of-scope discoveries

**5. [Out of scope] Pre-existing worktree-realpath failures in TenantInit/RunCommand integration tests**

- **Found during:** Task 2 full `tests/Integration/Command/` suite run.
- **Issue:** `TenantInitCommandIntegrationTest::testInitCommandReceivesProjectDir` and `TenantRunCommandIntegrationTest` analogous test fail with `assertSame` mismatches between the worktree path and the parent repo path. Both pre-date Plan 20-08 — verified by `git stash` (the failures reproduce identically against the bare baseline).
- **Disposition:** Out of scope per executor scope-boundary rules.
- **Logged to:** `.planning/phases/20-mailer-bootstrapper/deferred-items.md` (committed alongside this plan's changes).

**6. [Out of scope] Pre-existing PHPStan `arguments.count` errors in config/services.php**

- **Found during:** Final PHPStan sweep over all modified files.
- **Issue:** 2 errors at lines 47 + 52 — both flagging `->public(false)` as a 1-arg call on a 0-required-arg method. Same baseline noted in the Plan 20-04 SUMMARY's deviations.
- **Disposition:** Pre-existing; out of scope.
- **Verification:** Running PHPStan ONLY against the Plan 20-08 src + test files (excluding services.php) returns "No errors". My services.php additions did not introduce new errors.

**7. [Out of scope] Pre-existing duplicate-class fatal in the full PHPUnit run**

- **Found during:** Full `vendor/bin/phpunit` run.
- **Issue:** Around test 410-ish, `Cannot redeclare class Tenancy\Bundle\Entity\Tenant` fatal. The parent repo's `src/Entity/Tenant.php` gets autoloaded alongside the worktree's copy in some test ordering. Reproduces against the bare baseline (verified via `git stash`).
- **Disposition:** Worktree-isolation issue; tracked under the same umbrella as the TestProduct duplicate-class noted in Plan 20-00's deferred items.
- **Verification:** Unit suite (`vendor/bin/phpunit --testsuite unit`) is clean — 402 tests, 1096 assertions, 0 failures. Integration suite scoped to install-command tests passes (10/10 pass with 53 assertions).

**Total deviations:** 4 auto-fixed (all Rule-1 bug fixes — one load-bearing for correctness, three test-hygiene) + 3 out-of-scope (pre-existing worktree / config-loader.php baseline issues).

## Threat Surface Audit

Per the plan's `<threat_model>`:

- **T-20-08-01 (Tampering — broken Tenant entity from bad AST insert): `mitigate` VERIFIED.** Atomic write via `Filesystem::dumpFile()` + timestamped `.bak.{gmdate('Ymd-His')}` + post-mutation `php -l` (default `PhpExecutableFinder` runner, swappable via constructor `$lintRunner` arg for tests). On lint failure: restore .bak via `Filesystem::copy(bak, original, overwrite: true)` — copy, NOT rename, so the .bak survives every code path. Test 5 (`testLintFailurePostMutationRestoresOriginal`) injects a forced-failure runner and asserts byte-identical restoration.
- **T-20-08-02 (Tampering — non-standard entity corrupted by mutation): `mitigate` VERIFIED.** `updateEntity()` checks `count($classes) === 1` after AST walk via `NodeFinder::findInstanceOf($ast, Node\Stmt\Class_::class)`. Anything else (zero classes, multiple classes, parse failure) returns `REFUSED_NON_STANDARD` with a manual snippet in `errorMessage`. Test 4 (`testNonStandardEntityIsRefusedWithSnippet`) feeds a two-classes-per-file fixture and asserts byte-identical file.
- **T-20-08-03 (Info Disclosure — migration file leaks sensitive defaults): `accept` CONFIRMED.** Generated migration adds three NULLABLE VARCHAR(255) columns with no default values. Migration content is deterministic and inspectable.
- **T-20-08-04 (DoS — re-running --with-mailer accumulates .bak files): `accept` CONFIRMED.** Each entity-mutation run writes a fresh `.bak.{UTC-timestamp}` file. Documented as expected side effect; mitigation deferred. The bundle's existing docs note adding `*.bak.*` to .gitignore.
- **T-20-08-05 (Tampering — duplicate mailer block on re-run): `mitigate` VERIFIED.** `updateTenancyYaml()` runs `preg_match('/^[ \\t]*#?[ \\t]*mailer[ \\t]*:/m', $contents)` BEFORE appending. If found: `ALREADY_REGISTERED`. Test 9 covers commented form, active form, and post-append re-run — all three assert byte-identical file.
- **T-20-08-06 (Info Disclosure — yaml block exposes secrets): `accept` CONFIRMED.** The appended block contains only non-sensitive literal defaults (`strategy: x_transport`, `transport_cache_size: 32`, `sanitize_exceptions: true`). No DSNs, credentials, or tenant data.

No `threat_flag` entries to add. No new threat surface introduced beyond the threat-model enumeration.

## TDD Gate Compliance

Plan is `type: execute` but Task 1 declared `tdd="true"` — RED+GREEN gate sequence verified:

| Task | RED commit | GREEN commit | Order |
|------|------------|--------------|-------|
| 1    | `3e323db`  | `9164ec0`    | RED → GREEN |
| 2    | — (no tdd flag) | `76d035a` | feat (integration tests written alongside the wiring change) |

No REFACTOR commits required — the implementation was minimal first.

## Validation Compliance

Plan acceptance criteria checks (Task 1 + Task 2 combined):

- `[ -f src/Command/Install/Step/MailerSetupStep.php ]` true
- `grep -c 'final class MailerSetupStep' src/Command/Install/Step/MailerSetupStep.php` → 1
- `grep -c 'declare(strict_types=1)' src/Command/Install/Step/MailerSetupStep.php` → 2 (one in file, one in scaffolded migration template — intentional)
- `grep -c 'class_exists(ParserFactory::class)' src/Command/Install/Step/MailerSetupStep.php` → 1
- `grep -c 'ParserFactory.*createForNewestSupportedVersion' src/Command/Install/Step/MailerSetupStep.php` → 1
- `grep -v ^# src/Command/Install/Step/MailerSetupStep.php | grep -c 'Node.Stmt.TraitUse'` → 2 (detection + insertion)
- `grep -c 'TenantMailerConfigTrait' src/Command/Install/Step/MailerSetupStep.php` → 14
- `grep -c 'mailer_dsn|mailer_from|mailer_reply_to' src/Command/Install/Step/MailerSetupStep.php` → 5
- `grep -c 'AbstractMigration' src/Command/Install/Step/MailerSetupStep.php` → 7
- `grep -c '\.bak\.' src/Command/Install/Step/MailerSetupStep.php` → 2 (write + restore paths)
- `grep -c 'lintRunner|php -l' src/Command/Install/Step/MailerSetupStep.php` → 6
- `grep -c 'TENANCY_YAML_MAILER_BLOCK|updateTenancyYaml' src/Command/Install/Step/MailerSetupStep.php` → 7
- `grep -c 'appendToFile' src/Command/Install/Step/MailerSetupStep.php` → 1
- `grep -c 'tenancy.yaml' src/Command/Install/Step/MailerSetupStep.php` → 8
- `grep -c 'strategy: x_transport|transport_cache_size: 32|sanitize_exceptions: true' src/Command/Install/Step/MailerSetupStep.php` → 3
- `vendor/bin/phpunit tests/Unit/Command/Install/Step/MailerSetupStepTest.php` → OK (10 tests, 44 assertions)
- `vendor/bin/phpstan analyse src/Command/Install/Step/MailerSetupStep.php --level=9` → No errors
- `grep -c "'with-mailer'" src/Command/TenancyInstallCommand.php` → 2
- `grep -c 'MailerSetupStep' src/Command/TenancyInstallCommand.php` → 4
- `grep -c "getOption('with-mailer')" src/Command/TenancyInstallCommand.php` → 1
- `grep -c 'mailerSetupStep->run' src/Command/TenancyInstallCommand.php` → 1
- `grep -c 'resolveTenancyYamlPath' src/Command/TenancyInstallCommand.php` → 2
- `grep -c 'tenancy.mailer.install_step' config/services.php` → 2 (registration + injection)
- `[ -f tests/Integration/Command/TenancyInstallCommandWithMailerTest.php ]` true
- `grep -c 'testWithMailerFlagInsertsTraitScaffoldsMigrationAndUpdatesYaml' tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` → 1
- `grep -c 'testWithMailerDryRunMutatesNothing' tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` → 1
- `grep -c 'testWithMailerIsIdempotent' tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` → 1
- `vendor/bin/phpunit tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` → OK (3 tests, 20 assertions)
- `vendor/bin/phpstan analyse src/Command/TenancyInstallCommand.php --level=9` → No errors

All acceptance criteria green. PHPStan baseline (`config/services.php` lines 47, 52) is pre-existing and documented in Plan 20-04's deferred items.

## Phase 20 Closure Readiness

Plan 08 is the LAST plan of Phase 20 (Wave 6). All 9 plans (00-08) have shipped. The orchestrator can finalize phase status:

- Plans 00-08 all have `SUMMARY.md` files with `Self-Check: PASSED` and matching git commits
- BOOT-04 requirement is now satisfied end-to-end:
  - Plan 01: `TenantInterface` mailer methods + `TenantMailerConfigTrait`
  - Plans 02-07: bootstrapper, transport decorator, sanitizing decorator, DI wiring, compile-time guards, profiler integration, async canary
  - Plan 08: install-time scaffolding via `tenancy:install --with-mailer`
- All Phase 20 deferred items live in `.planning/phases/20-mailer-bootstrapper/deferred-items.md` for Phase 21 cleanup

## Self-Check: PASSED

Verified all 4 created files exist on disk and all 3 task commits are present in `git log`:

```
$ git log --oneline e5f00e3..HEAD
76d035a feat(20-08): wire --with-mailer option into tenancy:install + integration tests
9164ec0 feat(20-08): add MailerSetupStep — AST trait insert + migration scaffold + tenancy.yaml append
3e323db test(20-08): add failing tests for MailerSetupStep
```

Verified files:
- `src/Command/Install/Step/MailerSetupStep.php` — FOUND
- `tests/Unit/Command/Install/Step/MailerSetupStepTest.php` — FOUND (10 tests, 44 assertions)
- `tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` — FOUND (3 tests, 20 assertions)
- `src/Command/TenancyInstallCommand.php` — MODIFIED (added `--with-mailer` option + nullable MailerSetupStep dep + 3 path-resolver helpers)
- `config/services.php` — MODIFIED (registered `tenancy.mailer.install_step` + injected into install command)
- `.planning/phases/20-mailer-bootstrapper/deferred-items.md` — MODIFIED (added pre-existing worktree-realpath failures note)

---
*Phase: 20-mailer-bootstrapper*
*Plan: 08*
*Completed: 2026-05-20*
