---
phase: 21-demo-app
plan: "01"
subsystem: infra
tags: [symfony, doctrine, tenancy, mariadb, frankenphp, caddy, composer, path-repo]

requires:
  - phase: 17-origin-header-resolver
    provides: OriginHeaderResolver + OriginHeaderResolverConfigPass — allow_list config keys consumed by tenancy.yaml
  - phase: 19-profiler-tab
    provides: TenantDataCollector — enabled by symfony/web-profiler-bundle in require-dev
  - phase: 20-mailer-bootstrapper
    provides: MailerBootstrapper + TenantAwareTransportsDecorator — mailer.yaml DSN is the default overridden per-tenant
  - phase: 03-database-per-tenant-driver
    provides: TenantConnection (doctrine/TenantConnection.php) — referenced in doctrine.yaml wrapper_class

provides:
  - "Symfony 7.4 demo skeleton at examples/saas/ with Composer root path-repo back to ../../"
  - "config/bundles.php hand-wired with TenancyBundle + DoctrineBundle + TwigBundle + WebProfilerBundle + DoctrineFixturesBundle"
  - "tenancy.yaml: database_per_tenant driver, host.app_domain, strict_mode:true, tenant_entity_class, origin allow-list"
  - "doctrine.yaml: two-EM split (landlord at src/Entity/Landlord, tenant at src/Entity/Tenant) with TenantConnection wrapper"
  - "mailer.yaml: framework.mailer.dsn from MAILER_DSN env"
  - "Symfony Runtime entry points (bin/console + public/index.php)"
  - ".env/.env.example with all required env vars (no secrets)"

affects: [21-02-php-source, 21-03-container-stack, 21-04-ci-smoke-script]

tech-stack:
  added:
    - "symfony/framework-bundle:7.4.* (demo)"
    - "symfony/twig-bundle:7.4.* (demo)"
    - "symfony/mailer:7.4.* (demo)"
    - "symfony/web-profiler-bundle:7.4.* (demo, require-dev)"
    - "symfony/runtime:7.4.* (demo)"
    - "doctrine/doctrine-bundle:^2.13 (demo)"
    - "doctrine/dbal:^4.0 (demo)"
    - "doctrine/orm:^3.0 (demo)"
    - "doctrine/doctrine-fixtures-bundle:^4 (demo, require-dev)"
  patterns:
    - "Composer path-repo (type: path, url: ../../, symlink: true) for working-tree dev loop"
    - "MicroKernelTrait Symfony 7.4 minimal kernel — no method overrides"
    - "Two-EM Doctrine split: landlord EM owns App\\Entity\\Landlord + Tenancy\\Bundle\\Entity; tenant EM owns App\\Entity\\Tenant"
    - "tenant_entity_class in App\\Entity\\Landlord namespace (DemoTenant will subclass Tenancy\\Bundle\\Entity\\Tenant)"

key-files:
  created:
    - examples/saas/composer.json
    - examples/saas/.env
    - examples/saas/.env.example
    - examples/saas/.gitignore
    - examples/saas/.dockerignore
    - examples/saas/bin/console
    - examples/saas/public/index.php
    - examples/saas/src/Kernel.php
    - examples/saas/config/bundles.php
    - examples/saas/config/routes.yaml
    - examples/saas/config/packages/framework.yaml
    - examples/saas/config/packages/twig.yaml
    - examples/saas/config/packages/web_profiler.yaml
    - examples/saas/config/packages/tenancy.yaml
    - examples/saas/config/packages/doctrine.yaml
    - examples/saas/config/packages/mailer.yaml
  modified:
    - phpstan.neon (parallel: maximumNumberOfProcesses: 1 — OOM fix)

key-decisions:
  - "tenant_entity_class is App\\Entity\\Landlord\\DemoTenant (not App\\Entity\\DemoTenant) — DemoTenant lives in the Landlord sub-namespace per Plan 02"
  - "Two-EM Doctrine split: landlord EM explicitly maps both App\\Entity\\Landlord AND Tenancy\\Bundle\\Entity so the bundle's compiler passes find the tenant entity in the correct EM"
  - "PHPStan parallelism disabled (maximumNumberOfProcesses: 1) to prevent OOM at 128M default memory limit"
  - "pre-commit hook updated to pass --memory-limit=512M to PHPStan"
  - "doctrine.yaml uses when@prod block for auto_generate_proxy_classes: false (idiomatic Symfony)"
  - "strict_mode: true per CLAUDE.md hard constraint — never disabled in demo"

patterns-established:
  - "Composer path-repo pattern: url: ../../, symlink: true, versions.danplaton4/tenancy-bundle: 0.3.x-dev"
  - "origin.allow_list has BOTH http:// and https:// wildcard entries for *.tenancy.localhost (D-08, D-11)"

requirements-completed: [DEMO-01]

duration: 5min
completed: "2026-05-22"
---

# Phase 21 Plan 01: Demo App Scaffold Summary

**Symfony 7.4 demo skeleton at `examples/saas/` with Composer path-repo back to the bundle, two-EM Doctrine split (landlord+tenant on MariaDB), tenancy/origin/mailer config wired for database-per-tenant driver**

## Performance

- **Duration:** 5 min
- **Started:** 2026-05-22T11:22:36Z
- **Completed:** 2026-05-22T11:27:59Z
- **Tasks:** 3
- **Files modified:** 16 (15 created + phpstan.neon modified)

## Accomplishments

- Composer root at `examples/saas/composer.json` with path-repo `../../` (symlink:true, `0.3.x-dev`) — enables working-tree dev loop where `src/**` edits reflect in vendor immediately
- `config/bundles.php` hand-wired per D-10: TenancyBundle, DoctrineBundle, TwigBundle, WebProfilerBundle (dev/test), DoctrineFixturesBundle (dev/test) — no DoctrineMigrationsBundle
- `tenancy.yaml` wires: `database_per_tenant` driver + `host.app_domain: tenancy.localhost` + `strict_mode: true` (CLAUDE.md constraint) + `tenant_entity_class: App\Entity\Landlord\DemoTenant` + origin allow-list for `http(s)://*.tenancy.localhost` + resolvers `[host, origin, header, query_param, console]`
- `doctrine.yaml` defines two connections (landlord + tenant with `TenantConnection` wrapper) and two EMs: landlord at `src/Entity/Landlord` (+ `Tenancy\Bundle\Entity`), tenant at `src/Entity/Tenant`
- `mailer.yaml` sets `framework.mailer.dsn` from `%env(MAILER_DSN)%` — per-tenant override at runtime via Phase 20 `TenantAwareTransportsDecorator`
- Symfony Runtime entry points (`bin/console`, `public/index.php`) and env defaults (`.env`, `.env.example`) — no secrets committed

## Task Commits

Each task was committed atomically:

1. **Task 1: composer.json, .env, .gitignore, .dockerignore, bin/console, public/index.php** - `b339ee1` (feat)
2. **Task 2: Kernel + bundles.php + framework/twig/web_profiler config** - `a2d3cb3` (feat)
3. **Task 3: tenancy.yaml + doctrine.yaml + mailer.yaml** - `3e48182` (feat)

## Files Created/Modified

| File | Role |
|------|------|
| `examples/saas/composer.json` | Composer root — path-repo back to `../../`, require @dev, require-dev fixtures+profiler |
| `examples/saas/.env` / `.env.example` | Runtime env vars — `TENANCY_DOMAIN_BASE`, `MAILER_DSN`, `DATABASE_URL_LANDLORD`, MariaDB creds |
| `examples/saas/.gitignore` | Ignores `vendor/`, `var/`, `.env.local*`, `public/bundles/` |
| `examples/saas/.dockerignore` | Ignores `var/`, `vendor/`, `.git/`, `.env.local*`, `node_modules/` |
| `examples/saas/bin/console` | Symfony Runtime CLI entry point |
| `examples/saas/public/index.php` | Symfony Runtime front controller |
| `examples/saas/src/Kernel.php` | Symfony 7.4 `MicroKernelTrait` kernel — no method overrides |
| `examples/saas/config/bundles.php` | Hand-wired bundle list (D-10) |
| `examples/saas/config/routes.yaml` | Attribute-route loader + WDT/profiler routes under `when@dev` |
| `examples/saas/config/packages/framework.yaml` | APP_SECRET, session, profiler settings |
| `examples/saas/config/packages/twig.yaml` | `default_path`, `strict_variables` in test |
| `examples/saas/config/packages/web_profiler.yaml` | Toolbar under `when@dev`, disabled in test (D-07) |
| `examples/saas/config/packages/tenancy.yaml` | driver, host, strict_mode, tenant_entity_class, origin allow-list (D-04, D-08) |
| `examples/saas/config/packages/doctrine.yaml` | Two-EM split landlord+tenant with `TenantConnection` wrapper (D-04) |
| `examples/saas/config/packages/mailer.yaml` | `framework.mailer.dsn` from MAILER_DSN env (D-09) |
| `phpstan.neon` | `parallel.maximumNumberOfProcesses: 1` — OOM fix for 128M PHP limit |

## Decisions Made

- **`tenant_entity_class` namespace:** `App\Entity\Landlord\DemoTenant` (not `App\Entity\DemoTenant` from PATTERNS.md). PLAN.md lines 305-306 explicitly require the `Landlord` sub-namespace so Plan 02's `namespace App\Entity\Landlord;` declaration matches.
- **Doctrine landlord EM maps `Tenancy\Bundle\Entity`:** The explicit mapping entry ensures the bundle's compiler passes find `Tenancy\Bundle\Entity\Tenant` when using the two-EM split (without it, the bundle's `prependExtension` wiring would not locate the entity on the correct EM).
- **Doctrine `when@prod` block:** `auto_generate_proxy_classes: false` only in prod — idiomatic Symfony; dev auto-generates proxies for convenience.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan OOM on 128M PHP memory limit (pre-commit hook)**
- **Found during:** Task 1 commit (first commit attempt)
- **Issue:** `vendor/bin/phpstan analyse --no-progress` crashed with "Child process error: PHPStan process reached configured PHP memory limit: 128M". Pre-commit hook ran without `--memory-limit` flag. Blocked all commits.
- **Fix 1:** Added `parallel: maximumNumberOfProcesses: 1` to `phpstan.neon` — eliminated parallel worker spawning. Did not fully resolve (child process still OOM'd at 128M).
- **Fix 2:** Updated `.git/hooks/pre-commit` to pass `--memory-limit=512M` to the `phpstan analyse` command.
- **Files modified:** `phpstan.neon`, `.git/hooks/pre-commit`
- **Verification:** `vendor/bin/phpstan analyse --no-progress` exits 0; all subsequent commits succeed.
- **Committed in:** `b339ee1` (Task 1 commit, phpstan.neon included)

---

**Total deviations:** 1 auto-fixed (Rule 3 - blocking)
**Impact on plan:** PHPStan OOM was a pre-existing environment issue (128M default limit too low for PHPStan 2.x on PHP 8.4). Fix is minimal and does not affect bundle source. No scope creep.

## Issues Encountered

The worktree does not have a `vendor/` directory (git does not track vendor). Before the first commit, created a symlink `examples/saas/../vendor -> /path/to/main-repo/vendor` so the hook's `vendor/bin/php-cs-fixer` / `vendor/bin/phpstan` / `vendor/bin/phpunit` calls could resolve. This is expected worktree behavior.

## Known Stubs

None — this plan is config-only (YAML, PHP skeletons). No UI rendering, no data stubs.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: strict_mode | `examples/saas/config/packages/tenancy.yaml` | `strict_mode: true` explicitly set per T-21-01 mitigation — cross-tenant data leak prevention |

No new unmitigated threat surface introduced. `T-21-02` (Origin header spoofing) is accepted per threat model — the demo intentionally uses `Host:` injection in smoke tests.

## Next Phase Readiness

- **Plan 02 (PHP source)** can now create `src/Entity/Landlord/DemoTenant.php` (sub-namespaced per doctrine.yaml mapping) and `src/Entity/Tenant/Post.php` (maps to tenant EM). The Doctrine entity mappings are wired and ready.
- **Plan 03 (container stack)** can use `examples/saas/composer.json` path-repo with `../../` pointing at the bundle source — must set up bind-mount so `../../` resolves inside the container at build time.
- **Plan 04 (CI smoke)** depends on the full container stack from Plan 03.

---
*Phase: 21-demo-app*
*Completed: 2026-05-22*
