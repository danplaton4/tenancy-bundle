# Phase 21: Demo App - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-22
**Phase:** 21-Demo App
**Areas discussed:** App scope & isolation proof, Driver showcased, v0.3 features folded into demo, Verification posture (TLS + smoke)

---

## Gray Area Selection (multi-select)

| Option | Description | Selected |
|--------|-------------|----------|
| App scope & isolation proof | Minimal vs CRUD vs diagnostics; what makes tenants 'clearly distinct' | ✓ |
| Driver showcased | DB-per-tenant only, shared-DB only, or both | ✓ |
| v0.3 features folded into demo | Profiler / Mailer / Origin / tenancy:install | ✓ |
| Verification posture (TLS + smoke) | TLS first-run UX + smoke depth + CI wait + smoke exec location | ✓ |

**User's choice:** All four (full discussion).

---

## App scope & isolation proof

### Q1: What does the demo app actually show in the browser to prove isolation?

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal landing page | Brand + posts list per tenant, read-only | ✓ |
| CRUD slice (Posts) | Plus a `/posts/new` form for interactive isolation proof | |
| Diagnostics panel | Landing page + `/_tenancy` route showing resolver/bootstrapper trace | |

**User's choice:** Minimal landing page.
**Notes:** CRUD was the alternative; rejected to avoid CSRF/auth surface that distracts from install-funnel thesis.

### Q2: How many seeded tenants does the demo ship with?

| Option | Description | Selected |
|--------|-------------|----------|
| 2 tenants (acme + beta) | Matches roadmap criterion text exactly | |
| 3 tenants | Revisited after discovering hype/tests prototype | ✓ |

**User's choice:** Initially 2, later revised to 3 once the existing prototype demos under `/Users/danplaton/dev/hype/tests/` were factored in.

### Q3: How are tenant fixtures seeded on container boot?

| Option | Description | Selected |
|--------|-------------|----------|
| Doctrine fixtures | doctrine-fixtures-bundle in require-dev; run from entrypoint | ✓ |
| Raw SQL on MariaDB init | SQL files mounted into `/docker-entrypoint-initdb.d` | |
| Custom console command | `bin/console demo:bootstrap` | |

**User's choice:** Doctrine fixtures.
**Notes:** Raw SQL rejected because tenant DBs don't exist until `tenancy:migrate --create-dbs` runs.

### Q4: What's the 'clearly distinct content' that proves isolation at a glance?

| Option | Description | Selected |
|--------|-------------|----------|
| Name + color + post list | Brand name, accent color, 2–3 seeded posts per tenant | ✓ |
| Just the post list | Identical layout, data-only differs | |
| Name + color, no posts | Brand vibe only, isolation visible via /_tenancy diagnostics | |

**User's choice:** Name + color + post list.

### Q5: Add a landlord index page (lifted from existing demo's UX) to the minimal-landing-page scope?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — landlord at root domain | tenancy.localhost lists tenants; proves null-tenant resolution | ✓ |
| No — subdomains only | Smaller surface; users must know to visit subdomains | |

**User's choice:** Yes — landlord at root domain.
**Notes:** Lifted directly from `/Users/danplaton/dev/hype/tests/symfony74-demo/README.md` proven UX.

### Q6: Tenant count revisit — keep 2, or align with existing demo's 3?

| Option | Description | Selected |
|--------|-------------|----------|
| Keep 2 (acme + beta) | Minimum to satisfy roadmap criterion | |
| 3 tenants (acme + globex + initech) | Mirrors existing prototype; 'fleet' read | ✓ |

**User's choice:** 3 tenants.

---

## Driver showcased

### Q1: Which isolation driver does the demo showcase?

| Option | Description | Selected |
|--------|-------------|----------|
| Database-per-tenant only | Strongest isolation story, single driver config | ✓ |
| Shared-DB only | tenant_id filter, simpler topology | |
| Both, switchable via env | DEMO_DRIVER toggle | |

**User's choice:** Database-per-tenant only.
**Notes:** Shared-DB demo deferred to v0.4 alongside SHARE-*.

### Q2: How are per-tenant databases provisioned?

| Option | Description | Selected |
|--------|-------------|----------|
| Pre-created via MariaDB init SQL | docker-entrypoint-initdb.d | |
| Created by `tenancy:migrate --create-dbs` on PHP boot | Self-referential; showcases bundle CLI | ✓ |
| Created by demo seed command | Custom `demo:bootstrap` | |

**User's choice:** `tenancy:migrate --create-dbs` from entrypoint.
**Notes:** Self-referential — demo exercises the bundle's own CLI as part of boot.

---

## v0.3 features folded into demo

### Q1: Which v0.3 features does examples/saas/ exercise on first run? (multi-select)

| Option | Description | Selected |
|--------|-------------|----------|
| Phase 19 Profiler tab | symfony/web-profiler-bundle in require-dev; APP_ENV=dev | ✓ |
| Phase 18 tenancy:install in walkthrough | README copy points to it; not invoked by demo boot | ✓ |
| Phase 17 OriginHeaderResolver | Configured alongside HostResolver; README curl examples | ✓ |
| Phase 20 Mailer + Mailpit | Mailpit container + /_demo/send-test-mail route | ✓ |

**User's choice:** All four.
**Notes:** Aligns with `feedback_scope_fold_competitive_extensions` memory — extensions of recently-shipped phases belong in the current showcase phase.

### Q2: Mailpit posture — always-up or compose-profile-gated?

| Option | Description | Selected |
|--------|-------------|----------|
| Always-up | docker compose up brings up Mailpit + email demo | ✓ |
| Profile-gated (`--profile mailer`) | Lean default; mailer is 'second visit' material | |

**User's choice:** Always-up.
**Notes:** Overrides the prior REQUIREMENTS.md "Mailpit by default = OOS" line. The Mailpit screenshot (three distinct From addresses from same code path) is the single most compelling competitive moment.

### Q3: Should OriginHeaderResolver demo be a route or just curl examples in README?

| Option | Description | Selected |
|--------|-------------|----------|
| README curl examples only | No new route; Origin piggybacks on existing landing routes | ✓ |
| Dedicated `/api/me` JSON route | More elaborate SPA flow demonstration | |

**User's choice:** README curl examples only.
**Notes:** Origin is backend behavior; curl is the cleanest demonstration. Dedicated SPA route deferred to a future "SPA + JWT" cookbook.

---

## Verification posture (TLS + smoke)

### Q1: Caddy TLS strategy on first run — how do we handle the browser cert warning?

| Option | Description | Selected |
|--------|-------------|----------|
| HTTP-by-default + HTTPS optional | Primary walkthrough uses HTTP; HTTPS is a subsection | ✓ |
| HTTPS-by-default with `caddy trust` step | Production-shaped; adds friction | |
| HTTP only, no HTTPS in demo | Simplest; loses 'Caddy + internal CA' showcase | |

**User's choice:** HTTP-by-default + HTTPS optional.
**Notes:** First-run friction is the v0.3 adoption target; cert warning at second 60 of the 2-min promise is a catastrophic UX regression.

### Q2: What does bin/smoke.sh actually assert?

| Option | Description | Selected |
|--------|-------------|----------|
| Body content per tenant | HTTP 200 + tenant-specific marker per tenant + landlord index | ✓ |
| Body content + null-tenant + Origin | Above + bogus Host + Origin chain | |
| Full CRUD isolation assertion | POST to A, GET from B, assert absent | |

**User's choice:** Body content per tenant (with one Origin curl folded in per D-14 — covers most of option 2 without extra null-tenant assertions).

### Q3: How does the CI smoke job wait for the demo to be ready?

| Option | Description | Selected |
|--------|-------------|----------|
| compose healthchecks + curl-retry loop | `up -d --wait` + 30s curl retry | ✓ |
| Fixed sleep + curl-retry | Brittle on slow runners | |
| curl-retry only | No healthcheck source-of-truth | |

**User's choice:** compose healthchecks + curl-retry loop.

### Q4: Where does bin/smoke.sh execute the curls from — host or in-container?

| Option | Description | Selected |
|--------|-------------|----------|
| Host → published port | DNS-independent; identical local + CI | ✓ |
| Inside the PHP container | Avoids host-port publishing; two-step local invocation | |

**User's choice:** Host → published port.

---

## Claude's Discretion

The following are flagged in CONTEXT.md `<decisions>` as planner-discretion:

- `.env.example` shape (env var names + defaults)
- Caddyfile structure (single vs split per-vhost)
- FrankenPHP worker mode vs classic dispatch — planner chooses based on Doctrine connection lifecycle interactions with DB-per-tenant driver
- Asset pipeline choice (Asset Mapper / Encore / inline CSS) — inline is probably right for ~50 lines
- Port binding (80/443 vs 8080/8443) — planner picks based on `sudo`-needed friction
- `Post` entity shape (`id, title, body, createdAt` is the obvious set)
- Whether to add `brandColor` to bundle's default `Tenant` entity or to a demo-local subclass

## Deferred Ideas

- Shared-DB driver demo → v0.4 alongside SHARE-*
- Per-tenant CRUD (`/posts/new` form) → future "tenancy admin" demo
- Dedicated SPA route (`/api/me`) → future "SPA + JWT" cookbook
- In-repo Symfony 8.x version of the demo → not needed; `hype/tests/symfony8x-demo` + bundle CI matrix cover it
- Production deployment blueprint → v0.5 Operations & Scale
- Mailpit hardening / auth → out of scope for local-dev demo
- APM / observability container → v0.5 candidate
- CRUD isolation smoke assertion → bundle integration suite covers it
- `docs/examples/saas-demo.md` long-form walkthrough → Phase 22 owns it
