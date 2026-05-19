---
status: partial
phase: 19-profiler-tab
source: [19-VERIFICATION.md]
started: 2026-05-19T12:00:00Z
updated: 2026-05-19T12:00:00Z
---

## Current Test

[awaiting human testing in a real Symfony 7.x/8.x app with WebProfilerBundle]

## Tests

### 1. Resolved-tenant panel renders correctly in browser
expected: Boot a real Symfony 7.x / 8.x application with WebProfilerBundle installed and `danplaton4/tenancy-bundle` as a dev dependency. Hit a request that resolves a tenant. Open `/_profiler/{token}?panel=tenancy`. The WDT badge shows the tenant slug; the Tenancy panel renders slug, tenant label, driver, connection name (label only, never a DSN), resolver FQCN, bootstrapper FQCN list; toolbar/menu colors are green (resolved).
result: [pending]

### 2. Null-state panel renders correctly in browser
expected: With the same setup, hit a `/health-check` route (or any non-tenant route). The WDT badge shows the literal em-dash (—); the panel shows "No tenant resolved for this request." copy; menu badge is yellow.
result: [pending]

### 3. Error-state panel renders correctly in browser
expected: Trigger a `Tenancy\Bundle\Exception\TenantNotFoundException` by hitting an unknown tenant route. The WDT badge shows the literal warning glyph (⚠); the panel shows the exception class FQCN and the HTML-escaped message; menu badge is red.
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps

(none — automated must-haves all pass; only visual/runtime UX awaits human confirmation)
