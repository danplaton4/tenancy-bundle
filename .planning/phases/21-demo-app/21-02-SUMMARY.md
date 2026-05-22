---
phase: 21-demo-app
plan: "02"
subsystem: examples/saas
tags: [demo, entities, controllers, fixtures, twig, seed-command]
requires: [21-01]
provides: [21-03, 21-04]
affects: []
tech_added: []
tech_patterns:
  - Boot/clear lifecycle (setTenant → boot → try/finally → clear) from TenantMigrateCommand
  - Single-table Doctrine inheritance (DemoTenant extends bundle Tenant, same table)
  - SymfonyFixturesLoader + ORMExecutor for landlord fixture loading in command context
  - Root DBAL connection for CREATE DATABASE (unprivileged runtime user lacks the right)
  - SchemaTool::updateSchema for idempotent schema application without doctrine/migrations
key_files_created:
  - examples/saas/src/Entity/Landlord/DemoTenant.php
  - examples/saas/src/Entity/Tenant/Post.php
  - examples/saas/src/Repository/PostRepository.php
  - examples/saas/src/DataFixtures/LandlordTenantsFixture.php
  - examples/saas/src/Controller/LandlordController.php
  - examples/saas/src/Controller/TenantController.php
  - examples/saas/src/Controller/DemoMailController.php
  - examples/saas/src/Controller/HealthController.php
  - examples/saas/src/Command/SeedDemoCommand.php
  - examples/saas/templates/base.html.twig
  - examples/saas/templates/landlord/index.html.twig
  - examples/saas/templates/tenant/index.html.twig
key_files_modified: []
decisions:
  - DemoTenant extends bundle Tenant (same tenancy_tenants table) with brandColor column — single-table extension without STI annotations
  - SeedDemoCommand is the single canonical provisioning entrypoint; replaces non-existent tenancy:migrate --create-dbs and executeAs() API
  - Host constraints on routes: LandlordController locked to tenancy.localhost, TenantController wildcard {slug}.tenancy.localhost
  - HealthController has no sentinel-file check — app:seed-demo runs BEFORE frankenphp in entrypoint (Pitfall 5 Option 2)
metrics:
  duration: ~25 minutes
  completed: "2026-05-22"
  tasks_completed: 3
  tasks_total: 3
  files_created: 12
  files_modified: 0
---

# Phase 21 Plan 02: Demo App — PHP Source Summary

PHP business logic for the `examples/saas/` demo: two entities (DemoTenant in landlord DB, Post in tenant DBs), four controllers, one fixture, one command, and three Twig templates — all wired to the entity managers declared in Plan 01's `doctrine.yaml`.

## What Was Built

### Entity Layer

**`examples/saas/src/Entity/Landlord/DemoTenant.php`** — Extends `Tenancy\Bundle\Entity\Tenant` with a single `brandColor` column. Maps to the SAME `tenancy_tenants` table (single-table extension, not STI). All inherited columns (slug, name, domain, connectionConfig, isActive, mailerDsn, mailerFrom, mailerReplyTo, createdAt, updatedAt) and lifecycle callbacks come from the parent. `#[ORM\HasLifecycleCallbacks]` is NOT redeclared (parent owns it).

**`examples/saas/src/Entity/Tenant/Post.php`** — Per-tenant entity with id/title/body/createdAt. Constructor accepts title + body (createdAt defaults to now). Read-only public getters only — no setters (D-01: demo is read-only). Lives in the `post` table inside each tenant DB.

**`examples/saas/src/Repository/PostRepository.php`** — Standard `ServiceEntityRepository` extension. No custom finders — `findAll()` from parent suffices for the demo.

### Fixture Layer

**`examples/saas/src/DataFixtures/LandlordTenantsFixture.php`** — Seeds three `DemoTenant` rows in the landlord DB:

| Slug | Name | brandColor | mailerFrom |
|------|------|------------|------------|
| acme | Acme Corporation | #f97316 | noreply@acme.example |
| globex | Globex Industries | #2563eb | noreply@globex.example |
| initech | Initech LLC | #16a34a | noreply@initech.example |

All three share `mailerDsn: smtp://mailpit:1025` and `connectionConfig: {dbname: tenant_<slug>}` so `DatabaseSwitchBootstrapper` resolves to the right per-tenant DB.

### Controller Routing Topology

| Controller | Route | Host Constraint | EM Injected |
|------------|-------|-----------------|-------------|
| LandlordController | GET / | `tenancy.localhost` (apex only) | `doctrine.orm.landlord_entity_manager` |
| TenantController | GET / | `{slug}.tenancy.localhost` | `doctrine.orm.tenant_entity_manager` |
| DemoMailController | POST /_demo/send-test-mail | none (any subdomain) | — (uses MailerInterface) |
| HealthController | GET /health | none | — |

**LandlordController** has a defensive guard (`if hasTenant() → 404`) because the host constraint on `tenancy.localhost` means the ResolverChain should always return null here. This is belt-and-suspenders.

**TenantController** has a defensive guard (`if !hasTenant() → 404`) because the HostResolver must have matched `{slug}.tenancy.localhost` before the controller runs. The bundle's `strict_mode: true` already raises on null-tenant; the guard is belt-and-suspenders.

**DemoMailController** is the ONE write path in the demo (CONTEXT D-09). Deliberately authn-free (T-21-DM: accept — localhost-only). From/Reply-To are injected by Phase 20 `TenantMessageDecorator` — the controller only sets `to`, `subject`, and `text`.

**HealthController** returns a simple 200 `OK`. No sentinel-file check needed because `app:seed-demo` runs BEFORE `exec frankenphp run` in the container entrypoint (Plan 03) — by the time the PHP server starts accepting requests, seeding is complete (RESEARCH §"Pitfall 5" Option 2).

### `app:seed-demo` Algorithm

The command implements three sequential steps:

**Step 1 — Landlord schema + tenants:**
```
SchemaTool::updateSchema([DemoTenant metadata])  // creates/updates tenancy_tenants
ORMExecutor::execute([LandlordTenantsFixture], append: true)  // inserts 3 tenants, re-run-safe
```

**Step 2 — CREATE DATABASE per tenant (via root DBAL connection):**
```
foreach tenantProvider->findAll():
    CREATE DATABASE IF NOT EXISTS `tenant_<slug>` CHARACTER SET utf8mb4
    GRANT ALL ON `tenant_<slug>`.* TO 'tenancy'@'%'
FLUSH PRIVILEGES
```
Root credentials come from `MARIADB_ROOT_PASSWORD` env var. The runtime `tenancy` user lacks `CREATE DATABASE` privilege — this pattern is standard per PATTERNS §"CREATE DATABASE pattern" (external analog SeedTenantsCommand lines 36-43).

**Step 3 — Per-tenant schema + seed posts (boot/clear loop):**
```
foreach tenantProvider->findAll():
    try:
        tenantContext.setTenant(tenant)
        bootstrapperChain.boot(tenant)          // switches DBAL connection to tenant_<slug>
        SchemaTool.updateSchema([Post metadata]) // idempotent
        if count([]) == 0:                       // idempotency guard
            persist Posts, flush
        tenantEm.clear()
    finally:
        tenantContext.clear()
        bootstrapperChain.clear()               // reverse-order clear
```

### Idempotency Strategy

| Concern | Strategy |
|---------|----------|
| Landlord schema | `SchemaTool::updateSchema` (safe on re-run) |
| Tenant fixtures | `ORMExecutor::execute($fixtures, append: true)` |
| Tenant DBs | `CREATE DATABASE IF NOT EXISTS` |
| GRANT | `GRANT ALL` is idempotent in MySQL/MariaDB |
| Post seeding | `count([]) === 0` guard before persist/flush |
| Tenant schemas | `SchemaTool::updateSchema` on each boot |

### Template Architecture

**`base.html.twig`** — Inline CSS using `--brand-color` CSS variable. The `brand_color` block defaults to `#0f172a` (dark slate). All pages extend this template. No Asset Mapper, no Encore — ~50 lines of CSS inline (Claude's Discretion per CONTEXT).

**`landlord/index.html.twig`** — Extends base, lists tenants with inline brand colors and links to `http://<slug>.tenancy.localhost/`.

**`tenant/index.html.twig`** — Extends base, overrides `brand_color` block with `tenant.brandColor`. Renders tenant name + slug + post list.

## Deviations from Plan

### Auto-fixed Issues

None — plan executed exactly as written.

### Documented CONTEXT Deviations (acknowledged in plan)

**1. [CONTEXT D-05] `tenancy:migrate --create-dbs` does not exist**
- **Found during:** Pre-planning research (21-RESEARCH.md §"Critical Discrepancy")
- **Issue:** `src/Command/TenantMigrateCommand.php` has only `--tenant`; no `--create-dbs` flag exists anywhere in bundle source.
- **Fix:** `App\Command\SeedDemoCommand` implements DB provisioning using the root DBAL connection + `DriverManager::getConnection` pattern (verified from external analog `hype/tests/symfony74-demo/src/Command/SeedTenantsCommand.php` lines 36-43).
- **Files modified:** `examples/saas/src/Command/SeedDemoCommand.php`

**2. [CONTEXT D-06] `TenantContextOrchestrator::executeAs()` does not exist**
- **Found during:** Pre-planning research (21-RESEARCH.md §"Critical Discrepancy")
- **Issue:** `src/EventListener/TenantContextOrchestrator.php` has no `executeAs()` method.
- **Fix:** Used the explicit `setTenant → boot → try/finally → clear` pattern verbatim from `src/Command/TenantMigrateCommand.php` lines 97-108. Preserves D-06's INTENT (idiomatic PHP, replayable on volume wipe via bundle's own write path) while using the actual API.
- **Files modified:** `examples/saas/src/Command/SeedDemoCommand.php`

### Notes on Verification Script

The plan's automated verification script checks `! grep -q 'executeAs' ...` and `! grep -q 'tenancy:migrate.*--create-dbs' ...`. These strings DO appear in the PHP docblock of `SeedDemoCommand.php` — as documentation of the deviation (lines 29-30). They do NOT appear as actual code calls anywhere. The docblock comment is intentional and required by the plan's `<deviations_from_context>` documentation mandate.

## Threat Model Compliance

| Threat | Disposition | Implementation |
|--------|-------------|----------------|
| T-21-01: Cross-tenant data leak in TenantController | mitigate | TenantController injects `doctrine.orm.tenant_entity_manager` (not landlord EM); `hasTenant()` guard present |
| T-21-DM: POST /_demo/send-test-mail no CSRF | accept | Comment in DemoMailController: "Deliberately authn-free for the demo. Remove from any non-local deployment." |
| T-21-SE: SeedDemoCommand uses root MariaDB credentials | accept | Root creds from `MARIADB_ROOT_PASSWORD` env; only used for `CREATE DATABASE`; runtime app uses unprivileged `tenancy` user |

## Self-Check

### Created files exist
- `examples/saas/src/Entity/Landlord/DemoTenant.php` — FOUND
- `examples/saas/src/Entity/Tenant/Post.php` — FOUND
- `examples/saas/src/Repository/PostRepository.php` — FOUND
- `examples/saas/src/DataFixtures/LandlordTenantsFixture.php` — FOUND
- `examples/saas/src/Controller/LandlordController.php` — FOUND
- `examples/saas/src/Controller/TenantController.php` — FOUND
- `examples/saas/src/Controller/DemoMailController.php` — FOUND
- `examples/saas/src/Controller/HealthController.php` — FOUND
- `examples/saas/src/Command/SeedDemoCommand.php` — FOUND
- `examples/saas/templates/base.html.twig` — FOUND
- `examples/saas/templates/landlord/index.html.twig` — FOUND
- `examples/saas/templates/tenant/index.html.twig` — FOUND

### Commits exist
- `0bac8a5` — feat(21-02): entities, fixture, and templates for demo app — FOUND
- `8f75429` — feat(21-02): four controllers for demo app (landlord, tenant, mail, health) — FOUND
- `1bb6700` — feat(21-02): SeedDemoCommand — resolves D-05/D-06 CONTEXT discrepancy — FOUND

## Self-Check: PASSED
