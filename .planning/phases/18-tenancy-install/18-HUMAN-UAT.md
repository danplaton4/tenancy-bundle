---
status: passed
phase: 18-tenancy-install
source: [18-VERIFICATION.md]
started: 2026-05-18T08:42:00Z
updated: 2026-05-21T17:30:00Z
---

## Current Test

[all tests complete]

## Tests

### 1. Run `bin/console tenancy:install` on a real fresh Symfony skeleton project (not a test kernel) and confirm the terminal shows the success transcript, bundles.php is updated correctly, tenancy.yaml is created, and exit code is 0.
expected: TenancyBundle registered in config/bundles.php, config/packages/tenancy.yaml created, 'Next steps' printed, exit code 0.
result: passed
evidence: |
  Reproduction environment: /tmp/tenancy-uat-fresh-8445 (composer create-project symfony/skeleton:8.0.*).
  Path repository: /Users/danplaton/dev/tenancy-bundle-src -> the bundle at commit c7a5fc9.

  Step 1 — composer require danplaton4/tenancy-bundle:
    Symlinking from /Users/danplaton/dev/tenancy-bundle-src
    Symfony operations: 1 recipe (auto-generated)
    Configuring danplaton4/tenancy-bundle (>=dev-master): From auto-generated recipe
    Executing script cache:clear [OK]
    Executing script assets:install public [OK]
  → cache:clear no longer crashes. Zero-config kernel boot bug closed.

  Step 2 — Flex auto-registered TenancyBundle in config/bundles.php (confirmed).

  Step 3 — composer require --dev nikic/php-parser:^5 (per README Quick Start).

  Step 4 — bin/console tenancy:install:
    Tenancy Bundle — Installer
    [NOTE] Tenancy\Bundle\TenancyBundle is already registered in config/bundles.php — no changes made.
    Tenancy Bundle — Configuration Initializer
    [OK] Created config/packages/tenancy.yaml
    Doctrine ORM not detected — recommended driver: shared_db
    Next Steps section + sample doctrine.yaml printed
    Exit code: 0

  Step 5 — Idempotency re-run (bin/console tenancy:install again):
    [NOTE] already registered in config/bundles.php — no changes made.
    [WARNING] Configuration file already exists: config/packages/tenancy.yaml. Use --force to overwrite.
    Exit code: 0

  Step 6 — config/packages/tenancy.yaml contents verified: full commented config block,
    driver/strict_mode/landlord_connection/tenant_entity_class/cache_prefix_separator
    keys all present with documentation, ready to uncomment.

## Summary

total: 1
passed: 1
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

(none — all human-testing items closed)
