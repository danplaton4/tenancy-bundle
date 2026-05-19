---
status: complete
phase: 19-profiler-tab
source: [19-VERIFICATION.md, 19-HUMAN-UAT.md]
started: 2026-05-19T12:00:00Z
updated: 2026-05-19T14:15:00Z
---

## Current Test

[all tests complete]

## Tests

### 1. Resolved-tenant panel renders correctly in browser
expected: |
  Boot a real Symfony 7.x / 8.x application with WebProfilerBundle installed
  and `danplaton4/tenancy-bundle` as a dev dependency. Hit a request that
  resolves a tenant. Open `/_profiler/{token}?panel=tenancy`.

  The WDT badge shows the tenant slug; the Tenancy panel renders slug,
  tenant label, driver, connection name (label only — never a DSN), resolver
  FQCN, bootstrapper FQCN list. Toolbar/menu colors are green (resolved).
result: pass
evidence: |
  Live HTTP verification in /Users/danplaton/dev/hype/tests/symfony8x-demo
  (Symfony 8.0, shared_db driver, header resolver, docker MySQL).

    curl -H 'X-Tenant-ID: acme' http://127.0.0.1:8723/api/v1/invoices

  WDT toolbar HTML at `/_wdt/{token}`:
    - `sf-toolbar-status-green">resolved` status pill
    - `<span class="sf-toolbar-value">acme</span>` toolbar badge

  Panel HTML at `/_profiler/{token}?panel=tenancy`:
    - "Acme Corporation" (tenant_label)
    - "HeaderResolver" (resolved_by, basename)
    - "Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper" (bootstrappers list)
    - "<h3>Resolved by</h3>" and "<h3>Bootstrappers (2)</h3>" sections present

### 2. Null-state panel renders correctly in browser
expected: |
  With the same setup, hit a non-tenant route (e.g. `/`).
  The WDT badge shows the literal em-dash (—); the panel shows
  "No tenant resolved for this request." copy; menu badge is yellow.
result: pass
evidence: |
  Live HTTP verification in /Users/danplaton/dev/hype/tests/symfony8x-demo:

    curl http://127.0.0.1:8723/   (no X-Tenant-ID header)

  WDT toolbar HTML:
    - `sf-toolbar-status-yellow">null` status pill
    - `<span class="sf-toolbar-value">—</span>` em-dash badge

  Panel HTML:
    - "No tenant resolved for this request."
    - "This is the expected state for public, landlord, and health-check routes."

### 3. Error-state panel renders correctly in browser
expected: |
  Trigger a `Tenancy\Bundle\Exception\*` exception by hitting an unknown or
  inactive tenant route. The WDT badge shows the literal warning glyph (⚠);
  the panel shows the exception class FQCN and the HTML-escaped message;
  menu badge is red.
result: pass
evidence: |
  Live HTTP verification in /Users/danplaton/dev/hype/tests/symfony8x-demo.
  Marked the `globex` tenant inactive in the DB to trigger
  TenantInactiveException (which DOES propagate to kernel.exception —
  bad-slug TenantNotFoundException is caught and swallowed by resolvers
  per Phase 02-02 design):

    UPDATE tenancy_tenants SET isActive = 0 WHERE slug = 'globex';
    curl -H 'X-Tenant-ID: globex' http://127.0.0.1:8723/api/v1/invoices  → 403 Forbidden

  WDT toolbar HTML:
    - `sf-toolbar-status-red">error` status pill
    - `<span class="sf-toolbar-value">⚠</span>` warning-glyph badge

  Panel HTML:
    - "Tenancy\Bundle\Exception\TenantInactiveException" (exception class FQCN)
    - "&quot;globex&quot;" (HTML-escaped exception message — XSS mitigation holds)

  Cleanup: globex restored to active (isActive = 1) after verification.

## Summary

total: 3
passed: 3
issues: 0
pending: 0
skipped: 0
blocked: 0

## Cross-environment notes

**symfony8x-demo (Symfony 8.0, shared_db, header resolver):**
Full live HTTP verification of all 3 panel states succeeded.
Path repository symlinks the bundle from this repo via
`/Users/danplaton/dev/tenancy-bundle-src` (glob-safe symlink to working
copy). Composer Flex auto-created `config/packages/twig.yaml`,
`config/packages/web_profiler.yaml`, `config/routes/web_profiler.yaml`,
and registered `TwigBundle` + `WebProfilerBundle` in `config/bundles.php`.

**symfony74-demo (Symfony 7.4, database_per_tenant, subdomain resolver):**
Wiring verified — bundle symlinked, Phase 19 Profiler classes accessible
in vendor, Flex auto-created `config/packages/web_profiler.yaml` and
`config/routes/web_profiler.yaml`, `WebProfilerBundle` registered in
`config/bundles.php` under `dev` env, `_profiler/*` routes mounted,
`TenantDataCollector` + `TenantProfilerStash` present in dev container,
`bin/console cache:clear --env=dev` succeeds. Live HTTP verification
not run on 74 because the rendering code is identical (same bundle
files via symlink) — only the resolver type differs (HostResolver vs
HeaderResolver), and that's a `resolved_by` string in the panel, not
a rendering-path change. Pre-existing stale `wrapper_class:
Tenancy\Bundle\DBAL\TenantConnection` line removed from
`config/packages/doctrine.yaml` (leftover from retracted v1.0.0 era —
class was replaced by `TenantDriverMiddleware` in the v0.2 fixes).

**Bundle automated coverage:**
`tests/Integration/Profiler/TenantDataCollectorWdtTest.php` (4 tests,
42 assertions) runs the same end-to-end pipeline against an isolated
`ProfilerTestKernel` and asserts the same set of HTML markers
(`sf-toolbar-status-green`, `sf-toolbar-status-yellow`,
`sf-toolbar-status-red`, em-dash, warning glyph, escaped exception
message, slug/label/resolver/bootstrapper FQCNs in panel).

## Gaps

(none)
