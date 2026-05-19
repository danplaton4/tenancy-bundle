---
phase: 15
slug: architectural-fixes-v0-2
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-19
---

# Phase 15 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Populated from 15-RESEARCH.md § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Static analysis** | `vendor/bin/phpstan analyse --memory-limit=512M` |
| **Code style** | `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` |
| **Estimated runtime** | ~1s unit / ~3s full (pre-phase), +~5s for middleware integration test |

---

## Sampling Rate

- **After every task commit:** `vendor/bin/phpunit --testsuite unit` (fast feedback, ~1s)
- **After every plan wave:** Full suite + PHPStan (enforced by `.claude/hooks/pre-commit-quality.sh`)
- **Before `/gsd-verify-work`:** Full suite green + PHPStan level 9 clean + php-cs-fixer clean
- **Max feedback latency:** 10 seconds (unit); 30 seconds (full + phpstan)

Pre-commit hook is authoritative — it already runs php-cs-fixer + PHPStan + PHPUnit in sequence and blocks on any failure.

---

## Per-Task Verification Map

Populated by the planner (gsd-planner) based on final plan breakdown. Skeleton rows by requirement — each row gets finalized once the planner fixes task IDs and file paths.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 15-01-XX | 01 | 1 | FIX-01 | — | Decorated cache resolves as `CacheInterface` in DI | integration | `vendor/bin/phpunit tests/Integration/Cache/TenantAwareCacheAdapterContractTest.php` | ❌ W0 | ⬜ pending |
| 15-01-XX | 01 | 1 | FIX-01 | — | `DoctrineTenantProvider($em, CacheInterface, ...)` boots with decorated cache | integration | `vendor/bin/phpunit tests/Integration/Cache/DoctrineTenantProviderBootTest.php` | ❌ W0 | ⬜ pending |
| 15-01-XX | 01 | 1 | FIX-01 | — | Tag-aware decorator wired when inner is `TagAwareAdapterInterface` | unit | `vendor/bin/phpunit tests/Unit/Cache/TenantAwareTagAwareCacheAdapterTest.php` | ❌ W0 | ⬜ pending |
| 15-01-XX | 01 | 1 | FIX-01 | — | `CacheDecoratorContractPass` rejects decorator missing an interface | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/CacheDecoratorContractPassTest.php` | ❌ W0 | ⬜ pending |
| 15-02-XX | 02 | 1 | FIX-02 | — | `ResolverChain::resolve()` returns null when no resolver matches | unit | `vendor/bin/phpunit tests/Unit/Resolver/ResolverChainTest.php` | ✅ | ⬜ pending (modify) |
| 15-02-XX | 02 | 1 | FIX-02 | — | Public route with no tenant returns 200; `TenantContext::hasTenant()` is false | integration | `vendor/bin/phpunit tests/Integration/EventListener/NoTenantRequestTest.php` | ❌ W0 | ⬜ pending |
| 15-02-XX | 02 | 1 | FIX-02 | — | `strict_mode: true` still throws `TenantMissingException` when `#[TenantAware]` entity queried without tenant | integration | `vendor/bin/phpunit tests/Integration/Filter/StrictModeWithNullResolutionTest.php` | ❌ W0 | ⬜ pending |
| 15-02-XX | 02 | 1 | FIX-02 | — | `TenantNotFoundException` still thrown when identifier present but invalid | unit | `vendor/bin/phpunit tests/Unit/Resolver/HostResolverTest.php` | ✅ | ⬜ pending (verify no regression) |
| 15-03-XX | 03 | 2 | FIX-03 | — | `TenantDriverMiddleware` registered via `doctrine.middleware` tag on `tenant` connection | integration | `vendor/bin/phpunit tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php` | ❌ W0 | ⬜ pending |
| 15-03-XX | 03 | 2 | FIX-03 | — | Two tenants → two distinct SQLite files; data isolated per real connect/query | integration | `vendor/bin/phpunit tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php` | ❌ W0 | ⬜ pending |
| 15-03-XX | 03 | 2 | FIX-03 | — | `DatabaseSwitchBootstrapper::boot()` calls `$connection->close()` only | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` | ✅ | ⬜ pending (rewrite) |
| 15-03-XX | 03 | 2 | FIX-03 | — | `TenantConnection`, `TenantConnectionInterface` removed from src/ | grep | `! grep -rq 'TenantConnection' src/ config/` | n/a | ⬜ pending |
| 15-04-XX | 04 | 3 | FIX-04 | — | No `wrapperClass`, `ReflectionProperty`, `sqlite://` placeholder refs in docs | grep | `! grep -rEq '(wrapperClass\|ReflectionProperty\|sqlite://)' docs/ src/Command/TenantInitCommand.php` | n/a | ⬜ pending |
| 15-04-XX | 04 | 3 | FIX-04 | — | `tenancy:init` YAML output includes tenant-family driver sample + middleware registration note | integration | `vendor/bin/phpunit tests/Integration/Command/TenantInitCommandYamlContentTest.php` | ✅ | ⬜ pending (modify) |
| 15-04-XX | 04 | 3 | FIX-04 | — | CHANGELOG 0.2.0 section + UPGRADE.md 0.1→0.2 section present | grep | `grep -q '## \[0.2.0\]' CHANGELOG.md && grep -q '0.1 → 0.2' UPGRADE.md` | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Coverage note:** Every FIX-0N requirement has at least one integration-level verification (proves it works in a kernel boot, not just in isolation). The DBAL middleware has a *real* two-DB connect/query test — not a param-level assertion — per CONTEXT § FIX-03 non-negotiable.

---

## Wave 0 Requirements

Wave 0 (setup / test scaffolding) lands before any Fix work starts. Files the planner should create or stage:

- [ ] `tests/Integration/Cache/TenantAwareCacheAdapterContractTest.php` — boot kernel, resolve `CacheInterface`, assert no TypeError
- [ ] `tests/Integration/Cache/DoctrineTenantProviderBootTest.php` — actually instantiate `DoctrineTenantProvider` with decorated cache
- [ ] `tests/Unit/Cache/TenantAwareTagAwareCacheAdapterTest.php` — new decorator class's unit surface
- [ ] `tests/Unit/DependencyInjection/CacheDecoratorContractPassTest.php` — compiler pass contract assertion
- [ ] `tests/Integration/EventListener/NoTenantRequestTest.php` — public-route kernel with no matching resolver
- [ ] `tests/Integration/Filter/StrictModeWithNullResolutionTest.php` — strict_mode still guards #[TenantAware] when chain returns null
- [ ] `tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php` — DI assertion for middleware tag + per-connection scoping
- [ ] `tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php` — two SQLite file DBs, real roundtrip
- [ ] Test kernel helper for two-tenant SQLite-file config (extract from `DoctrineBootstrapperIntegrationTest.php` pattern per RESEARCH.md)

Rewrites (existing files — not fresh creation):
- [ ] `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` — assert `close()`-only behavior
- [ ] `tests/Unit/Resolver/ResolverChainTest.php` — replace `expectException(TenantNotFoundException)` with `assertNull()` case
- [ ] `tests/Integration/Command/TenantInitCommandYamlContentTest.php` — assert new YAML sample contents

Deletions:
- [ ] `tests/Unit/DBAL/TenantConnectionTest.php` — obsolete (class deleted)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `composer create-project symfony/skeleton` + `composer require doctrine/orm doctrine/doctrine-bundle danplaton4/tenancy-bundle:@dev` → `bin/console cache:clear` does not TypeError | FIX-01 | Reproduces the exact bug from issue #5 in a truly external environment | Create a temp Symfony 7.4 project, `composer require` from local path repo, run `bin/console cache:clear`, assert exit 0 |
| Live demo with MySQL DB for tenant A + tenant B; browse both subdomains and confirm different data | FIX-03 | Issue #8 was found in a real MySQL demo, not an SQLite test | Spin up `docker compose up mysql`, seed two databases, subdomain-resolve two tenants, confirm `curl` to tenant-a.localhost and tenant-b.localhost returns different records |

Both are performed during `/gsd-verify-work` UAT gate — not automatable inside this repo's CI because they require external projects. Treat the automated SQLite test as the deterministic guard; treat these two as the reality check.

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (12 new test files)
- [ ] No watch-mode flags (PHPUnit runs one-shot, not --watch)
- [ ] Feedback latency < 10s unit, < 30s full+phpstan
- [ ] Both manual UAT verifications documented with reproducible steps
- [ ] `nyquist_compliant: true` set in frontmatter (AFTER planner has filled task IDs and checker has signed off)

**Approval:** pending
