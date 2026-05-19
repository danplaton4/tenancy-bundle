---
phase: 19-profiler-tab
plan: 03
subsystem: profiler/views
tags: [profiler, twig, web-profiler-bundle, wdt, dev-tooling]
requires:
  - "src/Profiler/TenantDataCollector.php (Plan 02 — getTemplate() and $data shape)"
  - "src/TenancyBundle.php (AbstractBundle auto-registers @Tenancy Twig namespace)"
  - "@WebProfiler/Profiler/layout.html.twig (Symfony WebProfilerBundle base)"
provides:
  - "WDT toolbar badge (block toolbar) showing tenant slug / — / ⚠ by state"
  - "Profiler menu entry (block menu) with status-colored badge"
  - "Full profiler panel (block panel) rendering all 8 data keys for resolved state"
  - "@Tenancy/Collector/_icon.svg.twig partial — inline chain-glyph SVG"
affects:
  - "Plan 04 — data_collector tag's template attribute must equal '@Tenancy/Collector/tenant.html.twig'"
  - "Plan 06 — WDT integration test renders these templates and greps for state substrings"
tech-stack:
  added: []
  patterns:
    - "WebProfilerBundle conventions: toolbar/menu/panel block names"
    - "Symfony WDT pattern: {% set icon %}…{% endset %} + {% set text %}…{% endset %} + include('@WebProfiler/Profiler/toolbar_item.html.twig', { link, status })"
    - "@Tenancy Twig namespace auto-discovery via AbstractBundle (Symfony 7.x)"
key-files:
  created:
    - "src/Resources/views/Collector/_icon.svg.twig"
    - "src/Resources/views/Collector/tenant.html.twig"
  modified: []
decisions:
  - "Three explicit branches keyed by collector.data.state (D-05): resolved / error / null"
  - "WDT badge: slug (resolved) / ⚠ (error) / — (null) per D-10"
  - "Status colors: green=resolved, yellow=null, red=error (toolbar + menu)"
  - "Resolver FQCN shortened in toolbar tooltip via |split('\\\\')|last; full FQCN in panel <code>"
  - "Twig auto-escaping mitigates T-19-08 (XSS via error.message) — no |raw anywhere"
  - "No <script>, no <style>, no external assets — all CSS comes from WebProfilerBundle's class names"
metrics:
  duration_minutes: 6
  completed_date: 2026-05-19
  tasks_completed: 2
  files_created: 2
  commits: 2
---

# Phase 19 Plan 03: Profiler Twig Templates Summary

Two Twig templates implementing the Tenancy profiler panel — main `tenant.html.twig` with three state branches plus reusable `_icon.svg.twig` inline-SVG partial — wired to the locked 8-key data shape from `TenantDataCollector` and auto-discovered under the `@Tenancy` namespace.

## What Was Built

### `src/Resources/views/Collector/_icon.svg.twig`
- 24×24 inline SVG, chain-glyph (two interlinked paths)
- `stroke="currentColor"` ensures the icon inherits parent text color (light/dark profiler theme safe)
- No `<style>`, no `<script>`, no `<title>`, no `<desc>` — bare markup as WebProfilerBundle expects
- No Twig logic — pure SVG fragment; `.twig` extension only enables `{{ include() }}` via the bundle namespace
- Included **twice** from `tenant.html.twig`: in the `toolbar` block (next to the WDT badge) and in the `menu` block (next to the sidebar label)

### `src/Resources/views/Collector/tenant.html.twig`
- `extends '@WebProfiler/Profiler/layout.html.twig'` — required by WebProfilerBundle
- Defines three blocks: `toolbar`, `menu`, `panel`
- **`block toolbar`** uses the canonical `{% set icon %}…{% endset %} + {% set text %}…{% endset %} + include('@WebProfiler/Profiler/toolbar_item.html.twig')` pattern
  - WDT badge content: tenant slug (resolved) / `⚠` (error) / `—` (null)
  - Tooltip popover shows State, Driver, plus full identity row (Slug, Tenant, Connection, Resolved by) only when state is `resolved`
  - `status_color`: `red` for error, `yellow` for null, default green
- **`block menu`** uses a `label-status-error`/`label-status-warning` class to colorize the sidebar badge per state, with the chain icon next to a `<strong>Tenancy</strong>` label
- **`block panel`** has three explicit branches:
  - `resolved` → 4 metric tiles (Slug, Tenant, Driver, Connection), `<code>` block for resolver FQCN, `<ul>` of bootstrapper FQCNs (with `is empty` fallback)
  - `error` → `<strong>` exception class + `<p>` exception message inside an `empty empty-panel` block
  - else (null) → "No tenant resolved" + explanatory copy about public/landlord/health-check routes
- Twig auto-escaping handles HTML special chars in `error.class`/`error.message` (mitigation for threat T-19-08); no `|raw` filter anywhere

## Block Names + Ordering (canonical)

The template defines blocks in this exact order, all required and case-sensitive:

1. `{% block toolbar %}` — WDT badge + tooltip popover
2. `{% block menu %}` — sidebar entry (clicking opens the panel)
3. `{% block panel %}` — full profiler page content

## Substrings Plan 06 Should Assert

When Plan 06's WDT integration test renders the panel through a real Twig engine, these substrings will appear in the rendered HTML and are the canonical assertion targets:

| State | Substring in toolbar HTML | Substring in panel HTML |
|-------|---------------------------|-------------------------|
| `resolved` | `<span class="sf-toolbar-value">` followed by the tenant slug | `<span class="value">{slug}</span>` and `<span class="label">Slug</span>` |
| `null` | `<span class="sf-toolbar-value">—</span>` (em-dash) | `No tenant resolved for this request.` |
| `error` | `<span class="sf-toolbar-value">⚠</span>` (warning sign) | `<strong>{error.class}</strong>` (e.g. `Tenancy\Bundle\Exception\TenantNotFoundException`) followed by the message |
| any | `<strong>Tenancy</strong>` in menu block | `<h2>Tenancy</h2>` in panel block |

Additional robust substrings for state-classification assertions:
- `sf-toolbar-status-green` — resolved
- `sf-toolbar-status-yellow` — null
- `sf-toolbar-status-red` — error
- `label-status-error` — error (menu)
- `label-status-warning` — null (menu)

## Note for Plan 04 (DI Service Registration)

When registering `TenantDataCollector` in `config/services.php` under the `kernel.debug` guard, the `data_collector` tag's `template` attribute **must equal** `'@Tenancy/Collector/tenant.html.twig'` — this is the same string returned by `TenantDataCollector::getTemplate()` (line 83 of `src/Profiler/TenantDataCollector.php`), and it is also what `AbstractBundle`'s auto-namespacing makes `tenant.html.twig` available as.

Tag shape (planner reference):

```php
->tag('data_collector', [
    'id'       => 'tenancy',                                 // matches TenantDataCollector::getName()
    'template' => '@Tenancy/Collector/tenant.html.twig',     // matches TenantDataCollector::getTemplate()
])
```

The `id` MUST be `'tenancy'` (the collector's `getName()` return value) so the profiler indexes the panel correctly; the `template` MUST be the exact `@Tenancy/Collector/tenant.html.twig` namespaced path.

## Deviations from Plan

None — plan executed exactly as written. Both templates created verbatim from Plan 03 specs, all structural grep gates pass.

## Verification Results

**Task 03-01 (`_icon.svg.twig`):**
- File exists at `src/Resources/views/Collector/_icon.svg.twig`
- `xmlns="http://www.w3.org/2000/svg"`: 1 occurrence
- `viewBox="0 0 24 24"`: 1 occurrence
- `stroke="currentColor"`: 1 occurrence
- `width="24"`: 1 occurrence
- `<path` elements: 3 (spacer + 2 chain links)
- No `<style>` or `<script>` tags: 0 occurrences
- No Twig delimiters (`{%` or `{{`): 0 occurrences

**Task 03-02 (`tenant.html.twig`):**
- File exists at `src/Resources/views/Collector/tenant.html.twig`
- `extends '@WebProfiler/Profiler/layout.html.twig'`: 1 occurrence
- `{% block toolbar %}`, `{% block menu %}`, `{% block panel %}`: 1 each
- `collector.data.state == 'resolved'`: 3 occurrences (toolbar badge, toolbar tooltip if-block, panel branch)
- `collector.data.state == 'error'`: 5 occurrences (toolbar badge, toolbar status_color, menu status, panel branch, plus ternary helper)
- `@Tenancy/Collector/_icon.svg.twig`: 2 occurrences (toolbar + menu)
- All 8 data keys referenced: `slug` (3), `tenant_label` (2), `driver` (2), `connection_name` (2), `resolved_by` (2), `bootstrappers` (3), `error` (2), `state` (multiple)
- No `<script>`, no `<style>`, no `|raw` filter: 0 occurrences each

## Commits

- `21796ff` — feat(19-03): add inline SVG icon partial for tenancy profiler panel
- `e74a3e8` — feat(19-03): add tenancy profiler panel template

## Self-Check: PASSED

- `src/Resources/views/Collector/_icon.svg.twig`: FOUND
- `src/Resources/views/Collector/tenant.html.twig`: FOUND
- Commit `21796ff`: FOUND in git log
- Commit `e74a3e8`: FOUND in git log
