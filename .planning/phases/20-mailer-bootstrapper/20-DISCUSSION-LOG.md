# Phase 20: Mailer Bootstrapper - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-19
**Phase:** 20-mailer-bootstrapper
**Areas discussed:** Transport provider enumeration, From/Reply-To source, Transport cache bound, Async-routing guard

---

## Area Selection

User selected all 4 gray areas via multiSelect, with an additional freeform
directional steer: *"everything that brings value and we got on the
competitors, sometimes better"*. This is captured in CONTEXT.md `<specifics>`
as the competitive-positioning anchor (`stancl/tenancy` parity + async
correctness + compile-time guard + DSN sanitization as default).

---

## Transport Provider Enumeration

| Option | Description | Selected |
|--------|-------------|----------|
| Hybrid: provider hook + lazy fallback (Recommended) | `TenantTransportProviderInterface::getTenantsForTransportWarmup()` default `[]`; lazy decorator on miss. Best of both worlds, two integration paths. | |
| Lazy-only: `TransportFactoryDecorator` on first send | Single mechanism. Every first-send-per-tenant pays a DB lookup + transport construction. Simpler surface. | ✓ |
| Eager-only: warm all tenants at boot | Force user to enumerate all tenants at boot. Breaks at 10k+ tenants or sharded landlords. | |

**User's choice:** Lazy-only.
**Notes:** User explicit reason: *"i need the most robust way, hybrid things are not very easy adopted"* — adoption simplicity outweighs first-send latency. LRU cache (Area 3) absorbs the lookup cost after the first message per tenant per worker process.

---

## From / Reply-To Source

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated columns + interface methods (Recommended) | `mailerFrom` NOT NULL + `mailerReplyTo` nullable on Tenant; `getMailerFrom()` + `getMailerReplyTo()` on `TenantInterface`; trait covers defaults. | ✓ |
| Extract from DSN query string | `smtp://...?from=...&reply_to=...`. One column, parsing on every send, leaks sender into DSN logs. | |
| `From` only, `Reply-To` deferred to v0.4 | Ship just `mailerFrom`. Smaller BC surface. | |

**User's choice:** Dedicated columns.
**Notes:** Aligns with stancl/tenancy's separate-data-columns pattern. BC-break surface on `TenantInterface` expands from 1 method (DEC-MAIL-03 locked) to 3 — `TenantMailerConfigTrait` absorbs all three in one trait import.

---

## Transport Cache Bound

| Option | Description | Selected |
|--------|-------------|----------|
| Configurable LRU, default 32 (Recommended) | Config key `tenancy.mailer.transport_cache_size`. LRU + full clear on `TenantContextCleared`. SMTP close on eviction. | ✓ |
| Hard-coded LRU 16 | No config knob; pushes calibration to users. | |
| Unbounded with mandatory clear on `TenantContextCleared` | Relies on event firing only. Roadmap criterion 6 (socket-leak prevention test of 100 distinct tenants) effectively rejects this. | |

**User's choice:** Configurable LRU, default 32.
**Notes:** Calibration call: 32 covers realistic worker fanout in multi-tenant SaaS workloads. Configurable for high-tenant-throughput edge cases.

---

## Async-Routing Guard

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-detect with explicit override (Recommended) | Compiler pass inspects `framework.messenger.routing` for `SendEmailMessage`; `tenancy.mailer.async: auto\|true\|false` (default `auto`) override. Mirrors `tenancy.driver` pattern. | ✓ |
| Auto-detect only | No config flag; simpler but no escape hatch for custom message classes. Likely needs a flag added later anyway. | |
| Explicit flag only | User opts in via `tenancy.mailer.async: true`. Contradicts the bundle's zero-config DX promise. | |

**User's choice:** Auto-detect with explicit override.
**Notes:** Matches the bundle's existing auto-detect-with-escape-hatch pattern. Auto-detect handles the 95% case; the override exists for custom `SendEmailMessage` subclasses or non-standard messenger routing.

---

## Claude's Discretion

- Exact integration point for transport decoration (Symfony's `Transports`
  registry vs `TransportFactoryInterface` extension vs custom DSN scheme) —
  deferred to researcher; planner picks based on Symfony 7.x idiom.
- Migration file format — copy-pasteable snippet in `UPGRADE.md` vs a full
  shipped migration class. Planner to choose; `tenancy:install` will NOT be
  extended in this phase.
- Test infrastructure for the async canary (test transport + custom Messenger
  transport) — researcher to define.
- DSN sanitization regex scope beyond `smtp://`/`smtps://` — planner extends as
  needed.
- Whether the shipped `Tenant` entity uses `TenantMailerConfigTrait` itself or
  inlines the 3 columns + getters (the latter is more readable, the former is
  better self-documentation of the recommended user path).

---

## Mid-Discussion Scope Correction

After the initial write of CONTEXT.md, user pushed back on the deferred items.
Explicit quote: *"why defered? seems that we got to run, and ensure all the
time that fucking docs are made"*.

Translating: the previously-deferred profiler-panel mailer section and
`tenancy:install --with-mailer` extension are user-visible value-adds that
align with the "everything that brings value, sometimes better than
competitors" directional steer from area selection. These are NOT new
capabilities — they are extensions of phases 19 and 18 that complete the
BOOT-04 user-visible surface. Folded back into scope as D-08 and D-09.

**Also corrected:** `commit_docs: false` in `.planning/config.json` was
silently dropping doc commits. Combined with a stale `.planning/` entry in
`.gitignore` (despite 64 tracked planning files), new planning artifacts
were not landing in git. Both fixed: gitignore line removed,
`commit_docs: true` set. Going forward, every `/gsd-*` command that writes
docs will commit them.

## Deferred Ideas (genuinely new capabilities — not extensions)

- **Per-tenant mailer template overrides** — new capability; separate phase
  for v0.4+.
- **Bounce-handling hooks / DSN credential rotation** — operational features,
  demand-gated.
- **Tenant-creation-time DSN validation** — future DX phase candidate.
- **IMAP/POP3 inbox per tenant** — separate capability, not a Mailer extension.
