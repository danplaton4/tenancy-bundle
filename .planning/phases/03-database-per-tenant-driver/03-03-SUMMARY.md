---
phase: 03-database-per-tenant-driver
plan: "03"
subsystem: database
tags: [symfony, doctrine, dbal, bundle, dependency-injection, configuration]

# Dependency graph
requires:
  - phase: 03-01
    provides: TenantConnection and TenantConnectionInterface (DBAL wrapper)
  - phase: 03-02
    provides: DatabaseSwitchBootstrapper with TenantDriverInterface
provides:
  - tenancy.database.enabled boolean config node (default false) in TenancyBundle
  - Conditional DI registration of DatabaseSwitchBootstrapper with tenancy.bootstrapper tag
  - Conditional DI registration of EntityManagerResetListener (stub, Plan 04 implements)
  - Conditional rewiring of DoctrineTenantProvider to doctrine.orm.landlord_entity_manager
  - Conditional prependExtension targeting entity_managers.landlord.mappings vs orm.mappings
affects: [04-entity-manager-reset-listener, 05-bundle-wiring-capstone]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Opt-in feature flag pattern via config node with defaultFalse — database isolation is disabled unless explicitly enabled
    - Conditional DI registration in loadExtension() using $config['database']['enabled'] check
    - prependExtension reads raw extension config via getExtensionConfig() to determine mapping target before loadExtension runs

key-files:
  created: []
  modified:
    - src/TenancyBundle.php

key-decisions:
  - "database.enabled defaults false — database-per-tenant is opt-in, preserving Phase 2 behavior for single-EM apps"
  - "Conditional service registration done in loadExtension() (not services.php) because services.php is a static file that cannot branch on config values"
  - "prependExtension reads raw tenancy config via getExtensionConfig('tenancy') because it runs before loadExtension — config is not yet resolved"
  - "EntityManagerResetListener registered conditionally here even though Plan 04 creates the class — forward declaration in DI avoids two-phase wiring"

patterns-established:
  - "Config-gated services: use loadExtension() if/else block, not parallel services.php files"
  - "prependExtension conditional mapping: read getExtensionConfig() to pick landlord EM vs default EM"

requirements-completed: [ISOL-01, ISOL-02]

# Metrics
duration: 5min
completed: 2026-03-19
---

# Phase 03 Plan 03: Bundle Wiring — database.enabled Flag and Conditional Services Summary

**tenancy.database.enabled config flag wires DatabaseSwitchBootstrapper and EntityManagerResetListener conditionally, with prependExtension targeting landlord EM mapping when enabled**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-03-19T06:15:00Z
- **Completed:** 2026-03-19T06:20:00Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments
- Added `tenancy.database` config node with `enabled: false` default — database isolation is opt-in
- When `enabled: true`, DI container registers DatabaseSwitchBootstrapper with `tenancy.bootstrapper` tag and EntityManagerResetListener with autoconfigure
- When `enabled: true`, DoctrineTenantProvider is rewired from `doctrine.orm.default_entity_manager` to `doctrine.orm.landlord_entity_manager`
- prependExtension now reads raw extension config to conditionally map Tenant entity to `entity_managers.landlord.mappings` (database mode) or `orm.mappings` (single-EM mode)

## Task Commits

Each task was committed atomically:

1. **Task 1: Add tenancy.database config node and conditional service wiring** - `25cc3ab` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified
- `src/TenancyBundle.php` - Added database config node, conditional service registration block in loadExtension(), conditional prependExtension() logic

## Decisions Made
- `database.enabled` defaults `false` — preserves backward compatibility with single-EM Symfony apps from Phase 2
- Conditional registration lives in `loadExtension()`, not `config/services.php`, because the PHP services file is static and cannot branch on resolved config
- `prependExtension()` reads raw extension config via `getExtensionConfig('tenancy')` because it runs before `loadExtension()` — the config tree has not been resolved yet at that point
- `EntityManagerResetListener` is registered here (Plan 03) even though Plan 04 will create the actual class — forward-declaring DI wiring avoids two separate wiring phases and keeps all conditional registrations in one place

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Plan 04 can now create `EntityManagerResetListener` and it will be automatically picked up by the DI definition registered here
- Plan 05 (capstone) can verify end-to-end wiring with `tenancy.database.enabled: true` in test kernel config
- The `config/services.php` DoctrineTenantProvider still uses `doctrine.orm.default_entity_manager` — this is intentional as the conditional rewire happens in `loadExtension()` after services.php is imported

---
*Phase: 03-database-per-tenant-driver*
*Completed: 2026-03-19*
