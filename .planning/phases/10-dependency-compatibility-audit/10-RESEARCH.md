# Phase 10: Dependency Compatibility Audit - Research

**Researched:** 2026-04-10
**Domain:** PHP/Symfony dependency compatibility, CI matrix expansion, optional dependency guards
**Confidence:** HIGH

## Summary

This phase audits and fixes all dependency compatibility issues across the PHP 8.2/8.3/8.4 x Symfony 7.x/8.x matrix. Research reveals one critical compatibility bug: `TenantAwareCacheAdapter` uses `NamespacedPoolInterface` (introduced in `symfony/cache-contracts` v3.6.0, March 2025) but `composer.json` declares `^7.0` for Symfony packages, which would allow Symfony 7.0-7.2 installations where this interface does not exist. The minimum Symfony constraint must be raised to at least `^7.3||^8.0` (practical recommendation: `^7.4||^8.0` since 7.4 is the LTS and the only 7.x version tested in CI).

No PHP 8.4-only syntax (property hooks, asymmetric visibility) was found in `src/`. No deprecated Symfony 8.0 APIs are used. The Doctrine guard pattern (`class_exists`/`interface_exists`) is mostly sound but has gaps: the Messenger guard is present in DI but the Messenger classes themselves (TenantStamp, TenantSendingMiddleware, TenantWorkerMiddleware) have unguarded top-level `use` statements for Messenger interfaces. These are safe at runtime (PHP resolves `use` statements lazily) but should be validated by a new no-messenger CI job. DoctrineBundle 3.x requires PHP 8.4+, aligning with Symfony 8.0 only -- the current `^2.13||^3.0` constraint is correct.

**Primary recommendation:** Raise Symfony floor to `^7.4||^8.0`, add `--prefer-lowest` and no-messenger CI jobs, produce formal audit report, update version references in planning docs.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Full audit report + code fixes -- not just CI tweaks, but a formal report documenting all dependency interactions, compatibility findings, and fixes applied
- **D-02:** Audit covers ALL deps including v1.1 planned ones (Flysystem, Mailer) -- but v1.1 deps are constraints-only (check version ranges compatible with Symfony 7+8, no code changes)
- **D-03:** Audit report lives in `.planning/` only -- internal artifact, findings applied to code/CI, not shipped as a public COMPATIBILITY.md
- **D-04:** Automated PHP source scan: grep/PHPStan all `src/` files for syntax requiring PHP >8.2 (property hooks, asymmetric visibility, etc.) -- flag and fix any issues
- **D-05:** Deprecation check: run test suite with deprecation notices enabled for Symfony 7.x and 8.x APIs, flag and fix proactively
- **D-06:** Comprehensive `class_exists`/`interface_exists` guard audit -- trace every Doctrine and Messenger import path in `src/` and verify each has a guard. Don't trust the existing no-doctrine CI job alone.
- **D-07:** Symfony 6.4 LTS officially dropped -- bundle supports Symfony 7+8 only. Clean up any 6.4 references in REQUIREMENTS.md and docs.
- **D-08:** PHP 8.2+ is the floor (Symfony 7 already requires this). No PHP 8.4-only syntax allowed in bundle source code.
- **D-09:** Add `--prefer-lowest` job -- catches floor constraint violations. Standard OSS practice.
- **D-10:** Add no-messenger CI job -- validates `interface_exists` guards for Messenger, mirrors existing no-doctrine pattern
- **D-11:** Symfony 8 + DoctrineBundle 3.x stays as a separate CI job (current approach) -- clearer failure isolation

### Claude's Discretion
- **Symfony constraint range**: Claude decides `^7.0||^8.0` (broad) vs `^7.4||^8.0` (narrower) based on actual API breaking changes discovered during audit
- **DoctrineBundle strategy**: Claude decides best approach for supporting DoctrineBundle 2.x and/or 3.x after researching actual compatibility constraints
- **MigrationsBundle 4.x**: Claude decides whether to test 3.x only or both 3.x and 4.x based on current release status

### Deferred Ideas (OUT OF SCOPE)
None -- discussion stayed within phase scope
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| D-01 | Formal audit report + code fixes | Research identified specific compatibility issues: NamespacedPoolInterface floor, guard gaps, Symfony 8.0 breaking changes checklist |
| D-02 | Audit all deps including v1.1 | Flysystem-bundle 3.6 supports Symfony 7+8 + PHP 8.2+; symfony/mailer follows Symfony versioning natively |
| D-03 | Report in .planning/ only | Architecture pattern: .planning/phases/10-dependency-compatibility-audit/AUDIT-REPORT.md |
| D-04 | PHP source scan for >8.2 syntax | Scan complete: zero PHP 8.4-only syntax found in src/ |
| D-05 | Deprecation check | Symfony 8.0 UPGRADE-8.0.md checked: no deprecated APIs used by bundle |
| D-06 | Guard audit | Full import trace documented below: 3 guard points found, gap analysis complete |
| D-07 | Drop Symfony 6.4 references | REQUIREMENTS.md line 54 and PROJECT.md line 124/129 reference 6.4 |
| D-08 | PHP 8.2+ floor, no 8.4-only syntax | Confirmed: no property hooks, no asymmetric visibility in src/ |
| D-09 | --prefer-lowest CI job | Pattern documented: `composer update --prefer-lowest --prefer-stable` |
| D-10 | No-messenger CI job | Pattern mirrors no-doctrine job: remove messenger, run subset of tests |
| D-11 | Symfony 8 + DoctrineBundle 3.x separate job | Already exists in CI, may need refinement |
</phase_requirements>

## Standard Stack

### Core (already in composer.json)
| Library | Current Version | Constraint | Purpose | Notes |
|---------|----------------|------------|---------|-------|
| php | 8.4.12 (local) | ^8.2 | Runtime | Floor correct: Symfony 7.x requires 8.2+ [VERIFIED: symfony.com/releases/7.4] |
| symfony/cache | 7.4.8 | ^7.0\|\|^8.0 | Cache adapter | **MUST raise to ^7.4\|\|^8.0** -- NamespacedPoolInterface requires cache-contracts ^3.6 |
| symfony/config | 7.4.8 | ^7.0\|\|^8.0 | Bundle config | Raise to ^7.4\|\|^8.0 for consistency |
| symfony/console | 7.4.8 | ^7.0\|\|^8.0 | CLI commands | Raise to ^7.4\|\|^8.0 |
| symfony/dependency-injection | 7.4.8 | ^7.0\|\|^8.0 | DI container | Raise to ^7.4\|\|^8.0 |
| symfony/event-dispatcher | 8.0.8 | ^7.0\|\|^8.0 | Events | Raise to ^7.4\|\|^8.0 |
| symfony/http-foundation | 8.0.8 | ^7.0\|\|^8.0 | Request/Response | Raise to ^7.4\|\|^8.0 |
| symfony/http-kernel | 8.0.8 | ^7.0\|\|^8.0 | Kernel events | Raise to ^7.4\|\|^8.0 |
| symfony/process | 8.0.8 | ^7.0\|\|^8.0 | Process spawning | Raise to ^7.4\|\|^8.0 |

### Optional Dependencies (require-dev + suggest)
| Library | Current Version | Constraint | Purpose | Notes |
|---------|----------------|------------|---------|-------|
| doctrine/dbal | 4.4.3 | ^4.4 | DB abstraction | Floor correct [VERIFIED: packagist] |
| doctrine/doctrine-bundle | 2.18.2 | ^2.13\|\|^3.0 | Doctrine integration | 3.x requires PHP 8.4+ [VERIFIED: packagist] |
| doctrine/migrations | 3.9.6 | ^3.9 | Tenant migrations | No stable 4.0 yet (4.0.x-dev only) [VERIFIED: packagist] |
| doctrine/orm | 3.6.3 | ^3.3 | ORM/Entity | Floor correct [VERIFIED: packagist] |
| symfony/messenger | 8.0.8 | ^7.0\|\|^8.0 (dev) | Async context | Raise to ^7.4\|\|^8.0 |
| symfony/framework-bundle | 7.4.8 | ^7.0\|\|^8.0 (dev) | Test kernel | Raise to ^7.4\|\|^8.0 |
| symfony/phpunit-bridge | 8.0.8 | ^7.0\|\|^8.0 (dev) | Deprecation testing | Raise to ^7.4\|\|^8.0 |

### v1.1 Planned Dependencies (constraints-only audit)
| Library | Latest Version | Recommended Constraint | Symfony 7+8 Compatible | Notes |
|---------|---------------|----------------------|----------------------|-------|
| league/flysystem-bundle | 3.6.0 | ^3.6 | Yes (^5.4\|\|^6.0\|\|^7.0\|\|^8.0) | PHP ^8.0 [VERIFIED: packagist] |
| league/flysystem | 3.x | ^3.0 | N/A (no Symfony dep) | PHP ^8.0.2 [VERIFIED: packagist] |
| symfony/mailer | 7.4.x / 8.0.x | ^7.4\|\|^8.0 | Yes (follows Symfony versioning) | [VERIFIED: packagist] |

## Architecture Patterns

### Dependency Guard Pattern (existing)

The bundle uses two guard mechanisms for optional dependencies:

**1. DI-level guards (compile time):**
```php
// config/services.php - Doctrine guard
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
        // ...
}

// config/services.php - Messenger guard
if (interface_exists(MessageBusInterface::class)) {
    $services->set('tenancy.messenger.sending_middleware', TenantSendingMiddleware::class)
        // ...
}
```

**2. Bundle build-time guards:**
```php
// TenancyBundle::build() - Messenger compiler pass guard
if (interface_exists(MessageBusInterface::class)) {
    $container->addCompilerPass(new MessengerMiddlewarePass(), ...);
}

// TenancyBundle::loadExtension() - Migrations guard
if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
    $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
        // ...
}
```

### Guard Audit Results

**Complete import trace for all `src/` files with optional dependency imports:**

| File | Imports | Guard | Safe Without Dep? |
|------|---------|-------|-------------------|
| `Bootstrapper/DoctrineBootstrapper.php` | `Doctrine\ORM\EntityManagerInterface` | services.php `interface_exists` | Yes -- class never autoloaded without guard passing [VERIFIED: code audit] |
| `Cache/TenantAwareCacheAdapter.php` | `Symfony\Contracts\Cache\NamespacedPoolInterface` | **NONE** | **NO** -- always registered, always loaded. Requires cache-contracts ^3.6 [VERIFIED: code audit] |
| `Command/TenantMigrateCommand.php` | 6 Doctrine Migrations classes | `class_exists(DependencyFactory)` in TenancyBundle | Yes -- service never registered without guard [VERIFIED: code audit] |
| `DBAL/TenantConnection.php` | `Doctrine\DBAL\Connection`, `Driver`, `Configuration` | Only registered when `database.enabled=true` | Yes -- class never autoloaded in standard mode [VERIFIED: code audit] |
| `DBAL/TenantConnectionInterface.php` | None (pure interface) | N/A | Yes [VERIFIED: code audit] |
| `Driver/SharedDriver.php` | `Doctrine\ORM\EntityManagerInterface` | Only registered when `driver=shared_db` | Yes -- guarded by config [VERIFIED: code audit] |
| `Entity/Tenant.php` | `Doctrine\ORM\Mapping as ORM` | No direct guard | **RISK** -- but only used via DoctrineTenantProvider which requires Doctrine [VERIFIED: code audit] |
| `EventListener/EntityManagerResetListener.php` | `Doctrine\Persistence\ManagerRegistry` | `nullOnInvalid()` in DI, nullable type-hint | Yes -- PHP resolves use-aliases lazily; null constructor arg avoids class resolution [VERIFIED: code audit] |
| `Filter/TenantAwareFilter.php` | `Doctrine\ORM\Mapping\ClassMetadata`, `SQLFilter` | Only registered when `driver=shared_db` | Yes -- guarded by config [VERIFIED: code audit] |
| `Messenger/TenantStamp.php` | `Symfony\Component\Messenger\Stamp\StampInterface` | services.php `interface_exists` | Yes -- class only autoloaded by Messenger middleware classes [VERIFIED: code audit] |
| `Messenger/TenantSendingMiddleware.php` | 3 Messenger interfaces | services.php `interface_exists` | Yes -- never registered without guard [VERIFIED: code audit] |
| `Messenger/TenantWorkerMiddleware.php` | 3 Messenger interfaces | services.php `interface_exists` | Yes -- never registered without guard [VERIFIED: code audit] |
| `Provider/DoctrineTenantProvider.php` | `Doctrine\ORM\EntityManagerInterface` | **NONE** -- always registered in services.php | **RISK** -- but Doctrine is effectively required for the bundle to function [VERIFIED: code audit] |
| `Testing/InteractsWithTenancy.php` | `Doctrine\ORM\Tools\SchemaTool` | No guard (trait, user imports) | Acceptable -- testing trait assumes Doctrine [VERIFIED: code audit] |
| `TenancyBundle.php` | `Symfony\Component\Messenger\MessageBusInterface` | `interface_exists()` guard at line 144 | Yes -- guarded [VERIFIED: code audit] |

### Critical Finding: NamespacedPoolInterface Floor Constraint

**Problem:** `TenantAwareCacheAdapter` implements `NamespacedPoolInterface` from `symfony/cache-contracts`. This interface was added in v3.6.0 (2025-03-13). [VERIFIED: packagist version history, GitHub PR #59813]

**Impact:** With `symfony/cache: ^7.0`, a Symfony 7.0-7.2 installation would pull `symfony/cache-contracts ^3.0` (not ^3.6), where `NamespacedPoolInterface` does not exist. The class would fail to load with a fatal error.

**Fix:** Raise minimum Symfony constraint to `^7.4||^8.0`. This ensures:
- `symfony/cache` 7.4+ pulls `cache-contracts ^3.6` which contains `NamespacedPoolInterface` [VERIFIED: composer show output]
- Aligns with CI matrix (only 7.4 and 8.0 are tested)
- 7.4 is the current LTS with support until Nov 2029 [VERIFIED: symfony.com/releases/7.4]

**Alternative considered:** Adding `symfony/cache-contracts: ^3.6` as an explicit require. Rejected because it creates a confusing constraint mismatch (cache ^7.0 but contracts ^3.6). Raising the Symfony floor is cleaner and matches what we actually test.

### Symfony Version / PHP Version Matrix

| Symfony | PHP Required | Status | Bundle Support |
|---------|-------------|--------|----------------|
| 7.0-7.3 | 8.2+ | End of life | **NO** -- NamespacedPoolInterface missing |
| 7.4 (LTS) | 8.2+ | Maintained until Nov 2029 | **YES** |
| 8.0 | 8.4+ | Maintained until Jul 2026 | **YES** |
| 8.1 (upcoming) | 8.4+ | Release May 2026 | YES (^8.0 covers it) |

[VERIFIED: symfony.com/releases]

### CI Matrix Expansion Plan

**Current matrix:**
```
PHP 8.2 x Symfony 7.4 (standard)
PHP 8.3 x Symfony 7.4 (standard)
PHP 8.4 x Symfony 7.4 (standard)
PHP 8.4 x Symfony 8.0 (include)
No-doctrine (PHP 8.2, guards check)
PHPStan (PHP 8.4)
CS-Fixer (PHP 8.2)
Coverage (PHP 8.4, Symfony 7.4)
```

**Proposed additions:**
```
+ Prefer-lowest (PHP 8.2, Symfony 7.4, --prefer-lowest --prefer-stable)
+ No-messenger (PHP 8.2, guards check -- mirrors no-doctrine)
+ Symfony 8.0 + DoctrineBundle 3.x (already isolated, verify correctness)
```

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Constraint floor testing | Manual version checks | `composer update --prefer-lowest --prefer-stable` in CI | Catches real floor violations automatically [CITED: freek.dev/533] |
| Deprecation detection | Manual code review | `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in phpunit.xml | Symfony phpunit-bridge catches all deprecations automatically [CITED: symfony.com/doc/current/setup/upgrade_major.html] |
| Guard completeness | Grep-only analysis | No-doctrine + no-messenger CI jobs | Runtime verification > static analysis for autoload issues |
| PHP syntax audit | Manual reading | PHPStan level 9 + php-cs-fixer | Already catches most syntax issues; manual scan supplements [VERIFIED: codebase] |

## Common Pitfalls

### Pitfall 1: NamespacedPoolInterface Floor Constraint
**What goes wrong:** Bundle declares `symfony/cache: ^7.0` but uses `NamespacedPoolInterface` only available since cache-contracts 3.6.0 (Symfony 7.3+). Installing with Symfony 7.0-7.2 causes fatal error.
**Why it happens:** Feature was added in Symfony 7.3 but constraint was set broadly from project start.
**How to avoid:** Raise constraint to `^7.4||^8.0` and add `--prefer-lowest` CI job to catch future floor issues.
**Warning signs:** `--prefer-lowest` test failure; class-not-found errors on Symfony 7.0-7.2.

### Pitfall 2: DoctrineBundle 3.x Requires PHP 8.4
**What goes wrong:** DoctrineBundle 3.x requires PHP ^8.4 [VERIFIED: packagist]. Composer on PHP 8.2/8.3 with `doctrine/doctrine-bundle: ^2.13||^3.0` resolves to 2.x. On PHP 8.4, it MAY resolve to 3.x unless locked.
**Why it happens:** DoctrineBundle 3.0 dropped PHP 8.2/8.3 support.
**How to avoid:** The current `^2.13||^3.0` constraint is correct -- Composer's platform resolver handles this. The separate Symfony 8 CI job (PHP 8.4) naturally tests 3.x. Add explicit `--prefer-lowest` testing to verify 2.13 floor.
**Warning signs:** CI failures on Symfony 8.0 job when DoctrineBundle 3.x introduces API changes.

### Pitfall 3: Doctrine Migrations 4.x Not Stable
**What goes wrong:** Planning for doctrine/migrations 4.0 support when it's only available as 4.0.x-dev [VERIFIED: packagist].
**Why it happens:** Assumption that 4.0 is released based on MigrationsBundle 4.0 release.
**How to avoid:** Keep `doctrine/migrations: ^3.9` constraint. Do NOT add ^4.0 until a stable release exists. Note: DoctrineMigrationsBundle 4.0 requires PHP ^8.4 [VERIFIED: packagist] but the core `doctrine/migrations` 3.9.6 works with PHP ^8.1.
**Warning signs:** Dev-stability flag required to install.

### Pitfall 4: PHP `use` Statements and Optional Dependencies
**What goes wrong:** Assuming PHP `use` statements cause fatal errors when referenced class doesn't exist.
**Why it happens:** Confusion about PHP autoload behavior -- `use` is a namespace alias resolved lazily, NOT at class-load time.
**How to avoid:** The actual risk is type-hints checked at instantiation time. With `?ManagerRegistry $managerRegistry` and `null` injection via `nullOnInvalid()`, PHP never resolves the class. Validate with no-doctrine CI (already exists) and new no-messenger CI.
**Warning signs:** Fatal error "Class not found" during container compilation (not class loading).

### Pitfall 5: Mixed Symfony Component Versions
**What goes wrong:** Composer resolves different Symfony components to different major versions (e.g., cache 7.4 + event-dispatcher 8.0) when constraints allow `^7.0||^8.0`.
**Why it happens:** Symfony Flex's version alignment is opt-in and not enforced by default.
**How to avoid:** Use `symfony/flex` `tools: flex` in CI (already done). In local dev, Flex enforces consistent versions. The constraint `^7.4||^8.0` is permissive enough for both.
**Warning signs:** Mixed versions in `composer show | grep symfony`.

## Code Examples

### --prefer-lowest CI job pattern
```yaml
# Source: established OSS CI pattern [CITED: freek.dev/533]
prefer-lowest:
  runs-on: ubuntu-latest
  name: Prefer Lowest
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
        tools: flex
        coverage: none
    - uses: ramsey/composer-install@v3
      with:
        composer-options: --prefer-lowest --prefer-stable
      env:
        SYMFONY_REQUIRE: '7.4.*'
    - run: vendor/bin/phpunit
```

### No-messenger CI job pattern
```yaml
# Source: mirrors existing no-doctrine job pattern [VERIFIED: ci.yml]
no-messenger:
  runs-on: ubuntu-latest
  name: No Messenger (guards check)
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
        tools: flex
        coverage: none
    - uses: ramsey/composer-install@v3
    - name: Remove Messenger package
      run: composer remove --dev symfony/messenger --no-interaction
    - name: Run unit tests without Messenger
      run: vendor/bin/phpunit tests/Unit/Context tests/Unit/Event tests/Unit/Resolver tests/Unit/Cache tests/Unit/Exception tests/Unit/Attribute tests/Unit/DependencyInjection tests/Unit/Command tests/Unit/Bootstrapper tests/Unit/EventListener
```

### Deprecation detection in PHPUnit
```xml
<!-- phpunit.xml.dist addition for deprecation testing -->
<!-- Source: Symfony docs [CITED: symfony.com/doc/current/setup/upgrade_major.html] -->
<php>
    <env name="SYMFONY_DEPRECATIONS_HELPER" value="max[direct]=0"/>
</php>
```

### PHP 8.4-only syntax scanner (D-04)
```bash
# Grep for property hooks syntax: { get { ... } } or { set(...) { ... } }
grep -rn '{\s*\(get\|set\)\s*[{(;]' src/ || echo "No property hooks found"

# Grep for asymmetric visibility: private(set), protected(set), public(set)
grep -rn 'private(set)\|protected(set)\|public(set)' src/ || echo "No asymmetric visibility found"

# PHPStan with --php-version flag to verify 8.2 compat
vendor/bin/phpstan analyse --php-version=80200
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Symfony 6.4 LTS support | Symfony 7.4 LTS + 8.0 | Nov 2025 | 6.4 references need cleanup |
| `symfony/cache` key-prefix | `NamespacedPoolInterface` + `withSubNamespace()` | Symfony 7.3 (May 2025) | Requires cache-contracts ^3.6 minimum |
| DoctrineBundle 2.x only | DoctrineBundle 2.x + 3.x | Oct 2025 | 3.x requires PHP 8.4+, compatible with Sf 6.4-8.0 |
| doctrine/migrations 3.x only | 3.x stable, 4.x dev-only | Current | Stay on ^3.9, no 4.x until stable |

**Deprecated/outdated:**
- Symfony 6.4 references in REQUIREMENTS.md (OSS-01, line 54) and PROJECT.md (lines 124, 129) -- must be updated
- `^7.0` Symfony floor constraint -- must be raised to `^7.4` due to NamespacedPoolInterface dependency

## Discretion Recommendations

### Symfony Constraint Range: `^7.4||^8.0` (recommended)

**Evidence:**
- `NamespacedPoolInterface` requires `symfony/cache-contracts ^3.6`, which ships with Symfony 7.3+ [VERIFIED: packagist]
- Symfony 7.0-7.3 are all end-of-life [VERIFIED: symfony.com/releases]
- CI only tests 7.4 and 8.0 [VERIFIED: ci.yml]
- 7.4 is LTS with support until Nov 2029 [VERIFIED: symfony.com/releases/7.4]
- No users can reasonably be on Symfony 7.0-7.3 in April 2026

**Decision:** Use `^7.4||^8.0` for all Symfony require/require-dev/suggest entries.

### DoctrineBundle Strategy: Keep `^2.13||^3.0`

**Evidence:**
- DoctrineBundle 3.x requires PHP ^8.4 [VERIFIED: packagist]
- DoctrineBundle 3.x supports Symfony ^6.4||^7.0||^8.0 [VERIFIED: packagist]
- On PHP 8.2/8.3, Composer resolves to 2.x automatically
- On PHP 8.4 (Symfony 8.0), Composer can resolve to 3.x
- Current `^2.13||^3.0` constraint already handles this correctly
- The existing separate Symfony 8.0 CI job tests the 3.x path

**Decision:** Keep `^2.13||^3.0` constraint unchanged. The separate Symfony 8.0 CI job already validates 3.x compatibility.

### MigrationsBundle 4.x: Test 3.x only

**Evidence:**
- `doctrine/migrations` 4.0 is dev-only (4.0.x-dev) -- no stable release [VERIFIED: packagist]
- `doctrine/doctrine-migrations-bundle` 4.0 exists (Dec 2025) and requires PHP ^8.4 [VERIFIED: packagist]
- Current bundle requires `doctrine/migrations: ^3.9` (the core library, not the bundle)
- The bundle does NOT depend on `doctrine/doctrine-migrations-bundle` at all

**Decision:** Keep `doctrine/migrations: ^3.9` constraint. Do NOT add 4.x support until stable. The DoctrineMigrationsBundle is not a dependency of this bundle.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | phpunit.xml.dist |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| D-04 | No PHP 8.4-only syntax in src/ | smoke | `grep -rn 'private(set)\|protected(set)' src/ && exit 1 \|\| exit 0` | N/A (grep) |
| D-05 | No Symfony deprecations | integration | `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0 vendor/bin/phpunit` | phpunit.xml.dist |
| D-06 | Guard completeness | CI | No-doctrine + no-messenger CI jobs | ci.yml |
| D-08 | PHP 8.2 compat | smoke | `vendor/bin/phpstan analyse --php-version=80200` | phpstan.neon |
| D-09 | Floor constraint valid | CI | `composer update --prefer-lowest --prefer-stable && vendor/bin/phpunit` | ci.yml |
| D-10 | Messenger guards | CI | New no-messenger CI job | ci.yml (new) |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --testsuite unit`
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate:** Full suite green + CI passes on all matrix combos

### Wave 0 Gaps
- [ ] No-messenger CI job definition in ci.yml -- covers D-10
- [ ] --prefer-lowest CI job definition in ci.yml -- covers D-09
- [ ] `SYMFONY_DEPRECATIONS_HELPER` not currently set in phpunit.xml.dist -- covers D-05

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | N/A |
| V3 Session Management | No | N/A |
| V4 Access Control | Indirectly | Tenant isolation guards prevent cross-tenant data leaks |
| V5 Input Validation | No | N/A (audit phase, not feature phase) |
| V6 Cryptography | No | N/A |

### Known Threat Patterns for Dependency Audit

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Optional dep guard bypass | Information Disclosure | `class_exists`/`interface_exists` + CI no-dep jobs |
| Floor constraint violation | Denial of Service | `--prefer-lowest` CI catches impossible install combos |
| Deprecated API removal | Denial of Service | `SYMFONY_DEPRECATIONS_HELPER` catches proactively |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | PHP `use` statements are resolved lazily (not at class-load time) | Guard Audit | LOW -- if wrong, no-doctrine CI would already fail (it passes). Well-established PHP behavior. |
| A2 | `nullOnInvalid()` prevents class resolution for nullable type-hints | Guard Audit | LOW -- verified by existing no-doctrine CI job success |
| A3 | Symfony 7.0-7.3 have no active users in April 2026 | Discretion Recommendations | LOW -- all are EOL, 7.4 LTS supersedes them |

## Open Questions (RESOLVED)

1. **Deprecation baseline noise** -- RESOLVED: Plan 01 Task 2 handles via `max[direct]=0` default with `max[direct]=0&max[indirect]=999` fallback if vendor noise occurs.
   - What we know: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` will catch direct deprecations.
   - What's unclear: Whether indirect deprecations from Doctrine or other vendors will create noise that needs baseline.
   - Recommendation: Run once, assess, potentially set `max[indirect]=999` to allow vendor deprecations.

2. **EntityManagerResetListener without Doctrine** -- RESOLVED: Out of scope for Phase 10. Low-priority hygiene improvement noted for future phases. Current behavior is safe (PHP resolves use-aliases lazily, `nullOnInvalid()` prevents class resolution).
   - What we know: The class is always registered in DI with `nullOnInvalid()`. Works because PHP resolves use-aliases lazily.
   - What's unclear: Whether PHPStan or future PHP versions could change this behavior.
   - Recommendation: Consider wrapping registration in `interface_exists(ManagerRegistry::class)` guard for robustness. Low priority but good hygiene.

3. **DoctrineTenantProvider always-registered** -- RESOLVED: Architectural decision documented in guard audit. Doctrine is effectively required for the bundle's core function (tenant resolution via DoctrineTenantProvider). The `suggest` mechanism covers optional Doctrine sub-features (migrations, DBAL driver), not Doctrine itself.
   - What we know: `tenancy.provider` is always registered, references `doctrine.orm.default_entity_manager`. Without Doctrine, the service would fail.
   - What's unclear: Whether a no-Doctrine user could use the bundle at all (no tenant provider = no tenant resolution).
   - Recommendation: Document as architectural decision -- Doctrine is effectively required for the bundle's core function. The `suggest` mechanism is for specific Doctrine sub-features (migrations, DBAL driver), not for Doctrine itself.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Runtime | Yes | 8.4.12 | -- |
| Composer | Dependency management | Yes | 2.9.5 | -- |
| PHPUnit | Testing | Yes | 11.5.55 | -- |
| PHPStan | Static analysis | Yes | 2.1.46 | -- |
| php-cs-fixer | Code style | Yes | 3.94.2 | -- |

**Missing dependencies with no fallback:** None
**Missing dependencies with fallback:** None

## Sources

### Primary (HIGH confidence)
- Packagist: doctrine/doctrine-bundle versions and PHP requirements
- Packagist: doctrine/migrations versions (3.9.6 stable, 4.0.x-dev only)
- Packagist: symfony/cache-contracts (NamespacedPoolInterface added in v3.6.0)
- symfony.com/releases: Symfony 7.4 (Nov 2025, PHP 8.2+, LTS), Symfony 8.0 (Nov 2025, PHP 8.4+)
- Codebase audit: All 40 src/ files read and analyzed for imports, guards, syntax
- GitHub PR #59813: NamespacedPoolInterface merged into Symfony 7.3 branch

### Secondary (MEDIUM confidence)
- Symfony UPGRADE-8.0.md: Breaking changes checklist (github.com/symfony/symfony/blob/8.1/UPGRADE-8.0.md)
- Symfony blog: Preparing for 7.4 and 8.0 (symfony.com/blog/preparing-for-symfony-7-4-and-symfony-8-0)
- Packagist: league/flysystem-bundle 3.6.0 (Symfony 7+8 support)

### Tertiary (LOW confidence)
- None -- all claims verified against primary or secondary sources

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- all versions verified against Packagist and installed versions
- Architecture: HIGH -- complete code audit of all 40 src/ files performed
- Pitfalls: HIGH -- each pitfall verified against actual code and dependency metadata
- Discretion recommendations: HIGH -- backed by version data, release dates, and EOL schedules

**Research date:** 2026-04-10
**Valid until:** 2026-05-10 (30 days -- Symfony minor releases every 6 months, Doctrine stable)
