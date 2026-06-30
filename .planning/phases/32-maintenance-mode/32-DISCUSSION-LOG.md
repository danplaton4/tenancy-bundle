# Phase 32: Maintenance Mode - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-30
**Phase:** 32-maintenance-mode
**Areas discussed:** 503 page & Retry-After, State richness, Allow-list design, Command ergonomics

---

## 503 page & Retry-After

### Default 503 body (no custom template)
| Option | Description | Selected |
|--------|-------------|----------|
| Built-in HTML string | Hardcoded HTML page in the listener; no Twig render on hot path; can't itself 500 | ✓ |
| Bundled Twig template | Ship `@Tenancy/maintenance.html.twig`, rendered via Twig; more stylable but render can fail | |

### Custom-template override (MAINT-07)
| Option | Description | Selected |
|--------|-------------|----------|
| Global config template path | `tenancy.maintenance.template` Twig path; render only when set; fall back to built-in HTML on error | ✓ |
| Per-tenant template | Each tenant names its own 503 template (per-tenant state) | |

### Retry-After source
| Option | Description | Selected |
|--------|-------------|----------|
| Global config default (sec) | `tenancy.maintenance.retry_after`, default 3600 | ✓ |
| Fixed hardcoded | Constant baked into the listener, not configurable | |

### Content negotiation (JSON 503?)
| Option | Description | Selected |
|--------|-------------|----------|
| Yes — negotiate | JSON body for `Accept: application/json`/XHR, HTML otherwise; same 503 + headers | ✓ |
| No — always HTML | Always HTML body; status + Retry-After header carry the signal | |

**User's choice:** Deferred all four to Claude's recommendation ("you recommend / please recommend").
**Notes:** Locked per recommendation. Driving principle: the maintenance page must be unkillable (hardcoded HTML default), Twig only for an opt-in override with HTML fallback; one global Retry-After knob; content-negotiated JSON because the bundle targets APIs + SPAs. Always sets `Cache-Control: no-store` (MAINT-03 + CDN 5xx-caching warning).

---

## State richness

| Option | Description | Selected |
|--------|-------------|----------|
| Pure bool | One column `bool $inMaintenance`; interface gains exactly `isInMaintenance(): bool`; Retry-After stays global config | ✓ |
| Bool + metadata | Add `?string $maintenanceMessage` + `?int $maintenanceRetryAfter`; richer UX, wider BC surface | |
| Bool + 'until' timestamp | Add `?DateTimeImmutable $inMaintenanceUntil` auto-expiry; scheduling beyond MAINT scope | |

**User's choice:** Pure bool (Recommended).
**Notes:** This is *the* BC break of v0.5 (roadmap UPGRADE 0.4→0.5 names `isInMaintenance()`). Keeping the interface to one method minimizes that surface. Per-tenant message/retry-after/until deferred; an app can read `$tenant` in its custom Twig template (D-02) for a per-tenant message today.

---

## Allow-list design

### Scope
| Option | Description | Selected |
|--------|-------------|----------|
| Global config block | `tenancy.maintenance.allow_ips/allow_routes/allow_paths`; mirrors OriginHeaderResolver | ✓ |
| Per-tenant allow-lists | Each tenant carries its own bypass list (more state) | |

### allow_paths matching
| Option | Description | Selected |
|--------|-------------|----------|
| Prefix match | `str_starts_with` on pathinfo; one entry covers a subtree incl. Phase 33 `/_tenancy` | ✓ |
| Exact match | Only the exact path string bypasses | |
| Regex / RequestMatcher | Full Symfony RequestMatcher patterns; most flexible, more rope | |

**User's choice:** Global config block + prefix match.
**Notes:** Dimensions (IP and route and path) were already fixed by MAINT-06 / Success Criterion 4. Matching for IP (`IpUtils::checkIp`, CIDR) and route (exact `_route` name) taken as discretion; bypass = OR across the three. This config block is the cross-phase handoff where Phase 33 exempts health routes.

---

## Command ergonomics

### Idempotency
| Option | Description | Selected |
|--------|-------------|----------|
| Idempotent success | enable/disable no-op exits 0; event fires only on real state transition | ✓ |
| Warn / non-zero on no-op | Stricter signal; breaks idempotent scripting | |

### Target
| Option | Description | Selected |
|--------|-------------|----------|
| Single slug | Exactly one tenant (MAINT-01); `--all` is Out of Scope | ✓ |
| Variadic slugs | Accept multiple named slugs for batch convenience | |

### status --format=json
| Option | Description | Selected |
|--------|-------------|----------|
| Table + --format=json | Human table + opt-in JSON; parity with Phase 31 `tenancy:migrate --format=json` | ✓ |
| Table only | Human table only | |

**User's choice:** Idempotent success + single slug + table + --format=json.
**Notes:** Events dispatch only on an actual boolean change (no duplicate `TenantMaintenanceEnabled` on a no-op). Single-slug matches MAINT-01; site-wide / `--all` is the deferred v0.6 global feature. `status` lists only tenants currently in maintenance (MAINT-09).

---

## Claude's Discretion

- Entire 503-response shape (D-01..D-04) — user deferred to recommendation.
- Persistence path: landlord-EM column write via `findBySlug()`; **no** `boot()` / `TenantContext` set in the commands (contrast with resync).
- IP matching via `IpUtils::checkIp` (CIDR), route via exact `_route` name, bypass = OR.
- Final class names/namespaces, config-tree shape, and whether maintenance sits behind a `tenancy.maintenance.enabled` flag — left to planning.

## Deferred Ideas

- Per-tenant maintenance metadata (message / Retry-After / `inMaintenanceUntil` auto-expiry).
- Per-tenant custom 503 template selection.
- Per-tenant allow-lists.
- Global / site-wide (all-tenants) maintenance mode + variadic `--all` enable (already v0.6-deferred in REQUIREMENTS.md).
