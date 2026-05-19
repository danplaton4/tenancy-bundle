---
phase: 20-mailer-bootstrapper
plan: 01
subsystem: contract
tags: [interface, entity, bc-break, trait, upgrade-docs]

requires:
  - phase: 20-mailer-bootstrapper
    plan: 00
    provides: StubTenantMailerExtension trait + TenantMailerConfigTraitTest stub (Plan 00)
provides:
  - 3 new abstract methods on TenantInterface (getMailerDsn / getMailerFrom / getMailerReplyTo, all ?string)
  - TenantMailerConfigTrait — drop-in default impl + 3 ORM columns for user-land custom Tenant entities
  - Bundle's own Tenant entity now carries mailerDsn / mailerFrom / mailerReplyTo columns (inlined per D-02 self-doc)
  - UPGRADE.md §0.2 to 0.3 — BC-break docs with 2 migration paths + raw SQL snippet
  - All existing TenantInterface implementations (2 real StubTenants + 4 anonymous-class stubs) now satisfy the extended contract
affects: [20-02, 20-03, 20-04, 20-05, 20-06, 20-07, 20-08]

tech-stack:
  added: []  # no new composer deps — TenantMailerConfigTrait uses already-imported Doctrine\ORM\Mapping
  patterns:
    - "BC-break-with-trait-mitigation: ship the interface change in the same plan as a trait that satisfies it by default; users get a clear error pointing at the trait migration recipe"
    - "Bundle-own-entity-inlines-columns: src/Entity/Tenant.php expands columns inline (self-doc) while users compose via the trait — D-02 / 20-PATTERNS.md"

key-files:
  created:
    - src/Mailer/TenantMailerConfigTrait.php
    - .planning/phases/20-mailer-bootstrapper/20-01-SUMMARY.md
  modified:
    - src/TenantInterface.php
    - src/Entity/Tenant.php
    - tests/Unit/Mailer/TenantMailerConfigTraitTest.php
    - tests/Integration/Messenger/Support/StubTenant.php
    - tests/Integration/Resolver/Support/StubTenant.php
    - tests/Unit/Filter/TenantAwareFilterTest.php
    - tests/Integration/DoctrineBootstrapperIntegrationTest.php
    - tests/Integration/CacheBootstrapperIntegrationTest.php
    - tests/Integration/SharedDbFilterIntegrationTest.php
    - UPGRADE.md

key-decisions:
  - "Phase 20-01: bundle's own Tenant entity inlines 3 columns + 6 methods rather than `use TenantMailerConfigTrait;` — explicit self-documentation per D-02 / 20-PATTERNS.md §src/Entity/Tenant.php"
  - "Phase 20-01: TenantMailerConfigTrait setters return `static` (not `self`) — chainable across subclasses via late-static-binding; matches the Plan 00 StubTenantMailerExtension trait shape"
  - "Phase 20-01: 4 anonymous-class TenantInterface impls inline 3 null-returning method bodies rather than `use` the test-Integration trait — keeps Unit-layer tests independent of Integration-layer support code"

requirements-completed: [BOOT-04]

metrics:
  duration_min: 4
  tasks: 3
  files_created: 2
  files_modified: 10
  commits: 4
  started: "2026-05-19T21:52:51Z"
  completed: "2026-05-19T21:57:40Z"
---

# Phase 20 Plan 01: TenantInterface mailer methods + trait + UPGRADE docs Summary

**Landed the load-bearing TenantInterface BC break for Phase 20 — three new `?string` getters, a drop-in `TenantMailerConfigTrait` mitigation, inlined columns on the bundle's own `Tenant`, and a complete `UPGRADE.md §0.2→0.3` migration guide. Full suite stays green at 434 tests / 1179 assertions / 9 incomplete / 0 failures.**

## Performance

- **Duration:** ~4 min (3 tasks)
- **Started:** 2026-05-19T21:52:51Z
- **Completed:** 2026-05-19T21:57:40Z
- **Commits:** 4 (TDD: 1× test, 1× feat trait, then 1× feat interface, 1× docs)

## Accomplishments

- **Interface contract change (the load-bearing artifact for Phase 20).** `TenantInterface` now declares three new `?string` getters. Every Wave 2+ task (`MailerBootstrapper`, `TenantMessageDecorator`, `TenantAwareTransportsDecorator`) can type-hint a real interface, not a stub.
- **`TenantMailerConfigTrait` ships in the same plan as the BC break.** Users with a custom Tenant entity add one line — `use TenantMailerConfigTrait;` — and inherit three nullable columns + six methods. The trait carries `#[ORM\Column]` attributes so `doctrine:migrations:diff` generates the schema change automatically.
- **TDD on the trait.** RED gate (test fails with "Trait not found") committed as `f26f676`, then GREEN gate (trait implemented, 6/6 tests pass with 33 assertions) committed as `699f0ad`. Tests cover defaults, round-trip, static return, ORM-attribute presence, and reflective property shape.
- **Bundle's own `Tenant` entity inlines the columns** for self-documentation per D-02 — readers of the entity see the full schema in one place rather than chasing a trait import.
- **All six existing `implements TenantInterface` sites updated** so the 434-test suite still compiles: 2 real StubTenants (Messenger, Resolver) opt-in via `use StubTenantMailerExtension;`; 4 anonymous-class stubs (one Unit Filter test, three Integration tests) inline three null-returning method bodies.
- **`UPGRADE.md §0.2 to 0.3`** has the full migration recipe: explicit 3-method signature block, Migration Path A (trait, recommended), Migration Path B (manual), raw SQL `ALTER TABLE` snippet for the bundle's default `tenancy_tenants` table, and a forward-reference to the upcoming `docs/user-guide/mailer-bootstrapper.md`.

## Task Commits

| # | Task | Commit | Type | Files |
|---|------|--------|------|-------|
| 1 (RED) | Failing test for TenantMailerConfigTrait | `f26f676` | test | 1 file (tests/Unit/Mailer/TenantMailerConfigTraitTest.php — replaced Plan 00 stub) |
| 1 (GREEN) | Implement TenantMailerConfigTrait | `699f0ad` | feat | 1 file (src/Mailer/TenantMailerConfigTrait.php) |
| 2 | Extend TenantInterface + Tenant entity + all StubTenant impls | `eae03bf` | feat | 8 files |
| 3 | UPGRADE.md §0.2 to 0.3 — BC-break docs | `b1b1ccf` | docs | 1 file (UPGRADE.md) |

## Exact lines added

### `src/TenantInterface.php` (appended after `isActive(): bool`)

```php
public function getMailerDsn(): ?string;

public function getMailerFrom(): ?string;

public function getMailerReplyTo(): ?string;
```

### `src/Entity/Tenant.php` (added 3 columns after `$isActive`, 6 methods after `setDomain()`)

Properties (with self-doc comment block per 20-PATTERNS.md):

```php
// Mailer config (Phase 20 / BOOT-04).
// Users with a custom Tenant entity can equivalently `use \Tenancy\Bundle\Mailer\TenantMailerConfigTrait;`
// instead of inlining these 3 columns. See UPGRADE.md §0.2→0.3.
#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerDsn = null;

#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerFrom = null;

#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerReplyTo = null;
```

Methods (6 total, mirroring the existing `setDomain` / `getDomain` pattern; setters return `self`):

```php
public function getMailerDsn(): ?string { return $this->mailerDsn; }
public function setMailerDsn(?string $mailerDsn): self  { $this->mailerDsn = $mailerDsn; return $this; }

public function getMailerFrom(): ?string { return $this->mailerFrom; }
public function setMailerFrom(?string $mailerFrom): self { $this->mailerFrom = $mailerFrom; return $this; }

public function getMailerReplyTo(): ?string { return $this->mailerReplyTo; }
public function setMailerReplyTo(?string $mailerReplyTo): self { $this->mailerReplyTo = $mailerReplyTo; return $this; }
```

### Trait FQCN

`Tenancy\Bundle\Mailer\TenantMailerConfigTrait` — lives at `src/Mailer/TenantMailerConfigTrait.php`. Carries 3 `#[ORM\Column(type: 'string', length: 255, nullable: true)]` properties and 6 methods (3 getters returning `?string`, 3 setters returning `static`).

### UPGRADE.md §0.2 to 0.3 subsections (4)

1. `### TenantInterface — three new methods (BC break)`
2. `### Migration path A: use TenantMailerConfigTrait (recommended)`
3. `### Migration path B: manual implementation`
4. `### Migration SQL snippet`

## Decisions Made

- **Bundle's own `Tenant` entity inlines columns instead of `use TenantMailerConfigTrait;`** (per D-02 / 20-PATTERNS.md §src/Entity/Tenant.php). Inlining means the entity file is fully self-documenting — readers don't have to follow a trait import to see all columns. The trait still ships and is the recommended path for user-land tenants, where the "one-line addition" matters more than self-doc.
- **`TenantMailerConfigTrait` setters return `static` not `self`.** Matches the Plan 00 `StubTenantMailerExtension` trait shape and supports chaining across subclasses via late-static-binding. The bundle's `Tenant` entity setters return `self` to match the existing in-file pattern (`setDomain(): self`).
- **4 anonymous-class TenantInterface impls inline null-returning method bodies** instead of `use`ing the test-Integration trait. Pulling `tests/Integration/Support/StubTenantMailerExtension` into a Unit test (`tests/Unit/Filter/TenantAwareFilterTest.php`) would break the Unit-vs-Integration layering — Unit tests should not depend on Integration support code. Three null-returning method bodies cost ~12 lines each but keep the layering clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `tests/Integration/Support/StubTenant.php` does not exist**

- **Found during:** Task 2 (before extending TenantInterface)
- **Issue:** Plan §Task 2 referenced "the existing StubTenant in `tests/Integration/Support/`" with a single line of change. That file does not exist in the repo. Instead, six classes implement `TenantInterface`: two named `StubTenant` files (`tests/Integration/Messenger/Support/StubTenant.php` and `tests/Integration/Resolver/Support/StubTenant.php`) and four anonymous-class stubs inside `tests/Unit/Filter/TenantAwareFilterTest.php`, `tests/Integration/DoctrineBootstrapperIntegrationTest.php`, `tests/Integration/CacheBootstrapperIntegrationTest.php`, and `tests/Integration/SharedDbFilterIntegrationTest.php`.
- **Without a fix:** extending `TenantInterface` would break compilation in all six places — the entire test suite would fail to load, blocking the plan's own `vendor/bin/phpunit --testsuite unit` verify step.
- **Fix:** added `use \Tenancy\Bundle\Tests\Integration\Support\StubTenantMailerExtension;` to both real StubTenant files; inlined three null-returning method bodies (`getMailerDsn`/`getMailerFrom`/`getMailerReplyTo` → `null`) into each of the four anonymous classes (Unit-vs-Integration layering rationale documented in Decisions).
- **Files modified:** 2 real StubTenants + 4 anonymous-class hosts (see Task 2 commit `eae03bf`)
- **Verification:** `vendor/bin/phpunit` full suite → 434 tests / 1179 assertions / 0 failures / 0 errors; suite previously passed at 429 tests + 5 new TenantMailerConfigTraitTest cases.
- **Committed in:** `eae03bf` (Task 2 commit)

**2. [Rule 3 - Blocking] Worktree lacked `composer.lock` and `vendor/`**

- **Found during:** workspace setup, before Task 1
- **Issue:** identical to Plan 20-00's blocker — worktrees only share git-tracked files, and `composer.lock` is gitignored. PHPUnit could not run.
- **Fix:** `cp ../../../composer.lock . && composer update symfony/mailer symfony/mime` — installs Plan 00's new require-dev packages plus the previously-locked deps. Both `composer.lock` and `vendor/` remain gitignored.
- **Files modified:** none committed (composer.lock + vendor/ stay gitignored)
- **Verification:** `vendor/bin/phpunit` runs; `composer show symfony/mailer` resolves
- **Committed in:** n/a — workspace setup

### Out-of-scope discoveries (not fixed)

**3. [Out of scope] Pre-existing PHPStan level-9 errors in test files**

- **Found during:** Task 2 verification
- **Issue:** 7 PHPStan errors flagged by `vendor/bin/phpstan analyse … --level=9` on `tests/Unit/Filter/TenantAwareFilterTest.php` (3 errors), `tests/Integration/DoctrineBootstrapperIntegrationTest.php` (2 errors), `tests/Integration/SharedDbFilterIntegrationTest.php` (2 errors). All pre-date Plan 20-01 and are unrelated to the new mailer methods (line numbers shift by ~15 because of the added stub methods, but the errors themselves are the same — confirmed via `git stash && phpstan analyse … && git stash pop`).
- **Disposition:** Out of scope per executor scope-boundary rules.
- **Verification:** PHPStan is clean on every Plan 20-01 src file (`src/TenantInterface.php`, `src/Entity/Tenant.php`, `src/Mailer/TenantMailerConfigTrait.php`) and on the two real StubTenant + `StubTenantMailerExtension` trait files.
- **Logged to:** existing `.planning/phases/20-mailer-bootstrapper/deferred-items.md` (already created by Plan 20-00 for the `TestProduct.php` / `TestTenantProduct.php` warnings — same category).

**Total deviations:** 2 auto-fixed (both Rule 3 blocking — one was code-correctness, one was workspace setup) + 1 out-of-scope (PHPStan errors deferred).
**Impact on plan:** The plan executed exactly as written for the load-bearing artifacts (interface, entity, trait, UPGRADE.md). The Rule 3 StubTenant fix-up was strictly mechanical — the same edit shape applied six times instead of once.

## Threat Surface Audit

Per the plan's `<threat_model>`:

- **T-20-01-01 (Tampering, BC break) — `mitigate` disposition** verified. `TenantMailerConfigTrait` ships in the same plan; UPGRADE.md §0.2→0.3 documents both migration paths; all six existing `TenantInterface` implementations updated so internal tests still compile. Users will see PHP's `must implement method getMailerDsn` error pointing at the trait recipe.
- **T-20-01-02 (Info Disclosure, mailerDsn at rest) — `accept`** confirmed. The column is a plain `VARCHAR(255)` — no bundle-level encryption. UPGRADE.md does not promise any encryption-at-rest; the docs frame it as the user's database-level concern, matching the threat-model disposition.
- **T-20-01-03 (DoS, doctrine migration) — `accept`** confirmed. All three new columns are nullable (`#[ORM\Column(... nullable: true)]`). Adding nullable columns is a non-blocking ALTER on MySQL 8+, PostgreSQL 11+, and SQLite. UPGRADE.md provides the raw SQL for migration-less setups.

No new threat surface introduced beyond the threat-model enumeration. No `threat_flag` entries to add.

## Validation Compliance

- ✅ `[ -f src/Mailer/TenantMailerConfigTrait.php ]` — file exists
- ✅ `grep -c 'trait TenantMailerConfigTrait' src/Mailer/TenantMailerConfigTrait.php` → 1
- ✅ `grep -cE 'public function getMailer(Dsn|From|ReplyTo)\(\): \?string;' src/TenantInterface.php` → 3
- ✅ `private ?string $mailerDsn`/`mailerFrom`/`mailerReplyTo` all present in `src/Entity/Tenant.php`
- ✅ `php -r "… new ReflectionClass(Tenancy\\Bundle\\Entity\\Tenant::class) → hasMethod(getMailer{Dsn,From,ReplyTo})"` → `1`
- ✅ `grep -E '^## 0\.2 (→|to) 0\.3' UPGRADE.md` matches
- ✅ All UPGRADE.md `<acceptance_criteria>` counts met (TenantMailerConfigTrait ×3, getMailerDsn ×4, getMailerFrom ×2, getMailerReplyTo ×2, ALTER TABLE ×1, column names ×5, Migration path ×2)
- ✅ `vendor/bin/phpunit` full suite → 434 tests / 1179 assertions / 9 incomplete / **0 failures / 0 errors**
- ✅ `vendor/bin/phpstan … --level=9` clean on every Plan 20-01 src file and on the test-support trait file (`StubTenantMailerExtension.php`)

## Next Phase Readiness

Wave 2 plans (20-02 through 20-08) can now type-hint `TenantInterface::getMailerDsn(): ?string` (and the From / ReplyTo variants) and rely on the contract holding. Specifically:

- **20-02 (MailerBootstrapper):** calls `TenantContext::getTenant()?->getMailerDsn()` to resolve the per-tenant transport DSN
- **20-03 (TenantMessageDecorator):** reads `getMailerFrom()` / `getMailerReplyTo()` to stamp the `MessageEvent`
- **20-04 (TenantAwareTransportsDecorator):** routes the `tenant_<slug>` transport using `getMailerDsn()` as the cache key
- **20-05 (Sanitizing decorator):** redacts `getMailerDsn()` values out of `TransportException` messages
- **20-06 (Async canary):** asserts the worker actually uses `getMailerDsn()` not the landlord fallback
- **20-07 (Compile-time guard):** can reference the interface methods directly in its strategy switches
- **20-08 (UPGRADE / docs refresh):** can link forward to `docs/user-guide/mailer-bootstrapper.md` (referenced as "coming in v0.3 docs refresh" by this plan's UPGRADE.md addition)

No blockers for Wave 2.

## Self-Check: PASSED

Verified all created/modified files exist on disk and all 4 task commits are present in `git log`:

```
$ git log --oneline -4
b1b1ccf docs(20-01): document the v0.2->0.3 TenantInterface BC break
eae03bf feat(20-01): extend TenantInterface with 3 mailer getters + propagate to all impls
699f0ad feat(20-01): add TenantMailerConfigTrait with 3 ORM columns + getters/setters
f26f676 test(20-01): add failing test for TenantMailerConfigTrait
```

Verified files:
- `src/Mailer/TenantMailerConfigTrait.php` — FOUND
- `src/TenantInterface.php` — FOUND, contains 3 new method signatures
- `src/Entity/Tenant.php` — FOUND, contains 3 new columns + 6 methods
- `UPGRADE.md` — FOUND, contains §0.2 to 0.3 + Migration paths A/B + ALTER TABLE
- `tests/Unit/Mailer/TenantMailerConfigTraitTest.php` — FOUND, all 6 tests passing
- `tests/Integration/Messenger/Support/StubTenant.php`, `tests/Integration/Resolver/Support/StubTenant.php` — both use `StubTenantMailerExtension`
- 4 anonymous-class stubs — all have inline null-returning method bodies

## TDD Gate Compliance

Plan was not declared `type: tdd` at plan level, but Task 1 declared `tdd="true"`. RED/GREEN gates met:
- RED: `f26f676` (test commit, test fails with "Trait not found")
- GREEN: `699f0ad` (feat commit, test 6/6 passing)
- No REFACTOR needed — trait implementation is already minimal.

---
*Phase: 20-mailer-bootstrapper*
*Plan: 01*
*Completed: 2026-05-19*
