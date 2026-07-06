# Phase 34: Ops Docs & Carry-Forward - Context

**Gathered:** 2026-07-06
**Status:** Ready for planning

<domain>
## Phase Boundary

The **v0.5 milestone-closure phase**. Two workstreams, four requirements:

**(a) DOC-21 — net-new ops documentation.** A new `docs/ops/` section documenting the three
v0.5 features shipped in Phases 31–33: parallel migrations, per-tenant maintenance mode, and
tenant health checks — with production-ready Kubernetes probe YAML, runbook patterns, an
UPGRADE `0.4 → 0.5` section, mkdocs nav entries, and a `docs-lint.sh` guard for the new terms.

**(b) Carry-forward hardening (v0.4 debt folded into v0.5):**
- **DEMO-02** — reconcile the `examples/saas` `Dockerfile` ↔ `composer.lock` PHP-version drift.
- **GOV-02** — decide + write down the Nyquist `VALIDATION.md` enforcement policy for v0.5.
- **QA-01** — close the two open v0.4 `human_needed` UAT items (Phase 26 resync TTY confirm,
  Phase 28 PHPStan extension-installer auto-load).

Scope is **fixed** by ROADMAP.md Phase 34 + REQUIREMENTS.md §DOC-21/DEMO-02/GOV-02/QA-01. No
new runtime capabilities — discussion clarified only HOW to document and close, not WHAT to add.

**Gate invariant (Phase 30 precedent):** full PHPUnit suite, PHPStan L9 (`--memory-limit=512M`
locally), `php-cs-fixer`, and `scripts/docs-lint.sh` must all stay green. This is the last phase
before a v0.5 tag.

</domain>

<decisions>
## Implementation Decisions

### DOC-21 — Ops docs structure & depth

- **D-01: New top-level `Operations` nav group in `mkdocs.yml`.** REQUIREMENTS.md specifies a
  "new `docs/ops/` section", so the three pages get their own top-level nav group (not folded
  into the existing User Guide). Exact placement of the group within the nav order is Claude's
  discretion (natural spot: after "Examples" or after "User Guide"). Three files:
  `docs/ops/maintenance-mode.md`, `docs/ops/health-checks.md`, `docs/ops/parallel-migrations.md`.
- **D-02: Reference + focused runbooks (not reference-only, not exhaustive cookbook).** Each page
  is a feature reference (overview / config / behavior) PLUS 1–2 concrete operational runbooks.
  Target runbooks: **rolling fleet migration during a deploy** (parallel-migrations page),
  **enabling maintenance during a deploy + operator bypass** (maintenance-mode page), and
  **triaging a red readiness probe / DSN-safe health output** (health-checks page). Exact runbook
  wording is Claude's discretion; keep depth in line with existing user-guide pages (Phase 29
  D-01 style baseline).
- **D-03: `health-checks.md` MUST include Kubernetes probe YAML** — a liveness probe on
  `/_tenancy/health/live` and a readiness probe (per-tenant `/_tenancy/health/ready/{slug}`) with
  **correct `periodSeconds` + `failureThreshold`** values, PLUS the **CDN 5xx-caching warning**
  (don't let a CDN cache the 503/5xx health/maintenance responses). These are explicit
  Success-Criterion-1 requirements, not optional.
- **D-04: `docs-lint.sh` gains a guard for the new ops terms** (Phase 29 D-04 idiom: reuse the
  existing `check()` helper, `docs/`-scoped, sets `EXIT=1` on violation). Which terms to guard is
  Claude's discretion — candidates: the endpoint paths (`/_tenancy/health/live`,
  `/_tenancy/health/ready`), `Retry-After`, `Cache-Control: no-store`, `--parallel` /
  `--concurrency`. Follow the docs/-only scoping (UPGRADE.md/CHANGELOG.md excluded, per Phase 29).
- **D-05: `UPGRADE.md` gets a `0.4 → 0.5` section** documenting the `TenantInterface::isInMaintenance()`
  BC break and the `TenantMaintenanceConfigTrait` migration path (mirrors the existing
  `TenantMailerConfigTrait` / filesystem-trait upgrade recipes — see the 0.2→0.3 and 0.3→0.4
  sections). Follow Phase 29 D-05: always add the section even for a low-BC minor; note the trait
  makes the break a no-op for adopters who use it. `docs-lint.sh` does NOT scan UPGRADE.md.

### DEMO-02 — examples/saas PHP-version drift

- **D-06: Pin the demo to PHP 8.2 and regenerate its lock — do NOT bump the Dockerfile.** Add a
  `config.platform.php` entry (`8.2.x`) to `examples/saas/composer.json`, then regenerate
  `examples/saas/composer.lock` so no transitive dependency resolves to a `php: ^8.4` requirement.
  The `Dockerfile` stays on `dunglas/frankenphp:1-php8.2-bookworm`. Rationale: the bundle's
  supported floor is PHP 8.2+; the demo should prove the bundle works at the floor, and pinning is
  the minimal-churn "single coherent version" fix. `examples/saas/bin/smoke.sh` must be green on
  8.2 afterward.
- **D-07: Verify the culprit during planning.** A demo lock dependency currently requires `php: ^8.4`
  (surfaced near `doctrine/instantiator` in the lock). The planner/researcher should confirm the
  exact package and that pinning `platform.php=8.2` + `composer update` resolves to 8.2-compatible
  versions (may down-pin a dev dependency). If a *production* (non-dev) dep genuinely needs 8.4,
  escalate — but the expectation is this is a dev-dependency drift from a lock generated on 8.4.

### GOV-02 — Nyquist VALIDATION.md enforcement policy

- **D-08: Documented discovery-only stance (advisory, non-blocking).** `VALIDATION.md` is an
  *advisory* coverage artifact; the **live green PHPUnit suite is the real gate**. This matches the
  v0.4 precedent and the current de-facto reality (Phase 31 shipped with no VALIDATION.md while 32
  and 33 have one). The decision is to make this explicit, not to add a gate. Rationale: a hard gate
  is retroactively inconsistent (Phase 31) and adds per-phase friction disproportionate to a
  solo/small-maintainer OSS bundle. `nyquist_validation: true` in `.planning/config.json` stays as-is
  (it governs the *discovery* workflow, not a phase-complete block).
- **D-09: Write the policy in the contributor guide** (`docs/contributor-guide/`, e.g. a short section
  in the existing testing/quality page or a dedicated note), publicly visible, and cross-referenced
  from `.planning/`. (Discretion: exact file/section; alternative is internal-only in `.planning/`,
  but the requirement wants it "in place" and discoverable.)
- **D-10: Backfill Phase 31's VALIDATION.md for artifact consistency.** Since 32/33 have one and the
  stance is advisory-only (cheap doc, not a gate), add a Phase 31 `VALIDATION.md` so the v0.5 set is
  uniform. Low-value polish; leave to the planner whether to fold into a plan or note as follow-up.

### QA-01 — Close the two open v0.4 UAT items

- **D-11: Automated code-level testability seams for BOTH items** (not manual protocols). Convert
  each `human_needed` item into a permanent regression test:
  - **Phase 26 — `tenancy:shared:resync` TTY confirm.** `src/Command/SharedEntityResyncCommand.php`
    already has the seam: `$io->confirm('Proceed with live resync?', false)` at `:171`, gated by a
    `--force`/`-f` bypass and a clean-abort-on-`-n` path (`:169`). Write a command test (via
    `CommandTester` with a non-interactive / scripted input stream) asserting: (1) `--force` skips
    the prompt, (2) declining the prompt aborts cleanly with a success/no-op exit, (3) confirming
    proceeds. This is the standard Symfony `CommandTester::setInputs()` pattern.
  - **Phase 28 — PHPStan extension-installer auto-load.** The bundle ships
    `extra.phpstan.includes: ["extension.neon"]` in root `composer.json`. Write a test asserting the
    zero-config auto-load contract — e.g. that `extension.neon` exists and is referenced by the
    installer metadata, and/or a lightweight PHPStan-with-extension smoke that proves the three rules
    load without a manual `phpstan.neon` include. Exact mechanism is Claude's discretion; the intent
    is "prove the gap is closed, not just opened" (Phase 30 D-07 principle).

### Claude's Discretion

- Ops-nav group position within `mkdocs.yml`; per-page section ordering/tone (mirror existing
  user-guide pages; Phase 29 length baselines: mailer ~140, shared-db ~210, cli-commands ~240,
  filesystem ~430 as the comprehensive end).
- Exact k8s probe `periodSeconds`/`failureThreshold` values (must be sane for the endpoint's cost:
  liveness is zero-I/O and can poll frequently; per-tenant readiness is heavier — document why).
- Which specific new terms `docs-lint.sh` guards, and the exact `check()` invocations.
- Exact wording of the UPGRADE 0.4→0.5 section and the GOV-02 policy note.
- QA-01 Phase 28 test mechanism; whether Phase 31 VALIDATION.md backfill is a plan or a follow-up.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### The contract (read first)
- `.planning/ROADMAP.md` — Phase 34 entry (goal + 5 success criteria; the k8s-YAML +
  CDN-warning + UPGRADE + smoke-green + Nyquist-policy + UAT-closure acceptance bars).
- `.planning/REQUIREMENTS.md` §DOC-21, §DEMO-02, §GOV-02, §QA-01 — full acceptance criteria and
  the "net-zero new production deps" constraint.

### Closure/docs-phase precedents (patterns to follow)
- `.planning/phases/29-docs-refresh/29-CONTEXT.md` — docs-page style baseline, `docs-lint.sh`
  `check()` idiom, UPGRADE "always add a section" rule (D-05), nav-insertion pattern.
- `.planning/phases/30-v0-4-pre-tag-closure-integration-warnings-roadmap-drift/30-CONTEXT.md` —
  closure-phase discipline: scope fixed by audit, gate-invariant (full suite + PHPStan + cs-fixer
  + docs-lint green), "prove the seam is closed" testing principle (D-07).

### Source-of-truth for the three ops pages (what the docs must describe accurately)
- `.planning/phases/31-parallel-migrations/31-CONTEXT.md` + `31-01`/`31-02` SUMMARYs — parallel
  `tenancy:migrate` surface (`--parallel`, `--concurrency` default 4 / hard cap 32, `--dry-run`,
  `--format=json`, `shared_db` guard, atomic per-tenant output, null-exit=failure).
- `.planning/phases/32-maintenance-mode/32-CONTEXT.md` + `32-VALIDATION.md` — maintenance listener
  @ priority 16, HTTP 503 + `Retry-After` + `Cache-Control: no-store`, allow-list bypass
  (IP/CIDR/route/path), Twig-override-with-HTML-fallback, the three `tenancy:maintenance:*`
  commands, `isInMaintenance()` BC break + `TenantMaintenanceConfigTrait`.
- `.planning/phases/33-health-checks/33-CONTEXT.md` + `33-VALIDATION.md` — `/_tenancy/health/live`
  (zero-I/O 200), `/_tenancy/health/ready/{slug}` (IETF `application/health+json` 200/503, 404
  unknown / 503 inactive), bounded fleet dashboard, `tenancy:health` CLI, `HealthResponseSanitizer`
  DSN redaction, opt-in (default-disabled) endpoints, optional `liip/monitor-bundle` auto-register.
- `docs/roadmap.md` — public roadmap (kept in sync at Phase 30); note v0.5 features when relevant.

### Existing docs assets (style + files to modify)
- `mkdocs.yml` — nav structure; add the new `Operations` top-level group (D-01).
- `scripts/docs-lint.sh` (123 lines) — `check()` helper + section-whitelist awk; extend with the
  new-ops-terms guard (D-04). Run from repo root; CI invokes it.
- `UPGRADE.md` — per-minor upgrade sections (0.1→0.2 … 0.4.0→0.4.1); add `0.4 → 0.5` (D-05). NOT
  scanned by docs-lint.
- `docs/user-guide/cli-commands.md`, `docs/user-guide/mailer-bootstrapper.md`,
  `docs/user-guide/shared-db.md`, `docs/user-guide/filesystem-bootstrapper.md` — length/tone
  baselines for the new ops pages.

### DEMO-02 assets
- `examples/saas/composer.json` — add `config.platform.php` = 8.2.x (D-06). Currently no platform pin;
  `require.php` is `>=8.2`.
- `examples/saas/composer.lock` — regenerate after the pin (D-06); currently carries a `php: ^8.4`
  dep (D-07, verify culprit).
- `examples/saas/Dockerfile` — `dunglas/frankenphp:1-php8.2-bookworm` base; stays unchanged (D-06).
- `examples/saas/bin/smoke.sh` — the CI release-gate that must be green on 8.2 afterward.

### QA-01 assets
- `src/Command/SharedEntityResyncCommand.php` — `:169-171` confirm gate + `--force` bypass
  (Phase 26 seam; D-11). Existing command tests under `tests/` are the placement analog.
- `composer.json` — `extra.phpstan.includes: ["extension.neon"]` (`:78-86`) + the bundle's
  `extension.neon` (Phase 28 auto-load seam; D-11).

### GOV-02 assets
- `.planning/config.json` — `nyquist_validation: true` (governs the discovery workflow; stays as-is per D-08).
- `.planning/phases/32-maintenance-mode/32-VALIDATION.md`,
  `.planning/phases/33-health-checks/33-VALIDATION.md` — existing v0.5 VALIDATION.md examples.
- `.planning/v0.4-MILESTONE-AUDIT.md` — origin of the discovery-only vs enforce question + all
  carry-forward items (the 5 Nyquist discovery flags, the 2 UAT items, the saas drift).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scripts/docs-lint.sh` `check(pattern, desc, targets...)` helper — reuse for the new-ops-terms
  guard (Phase 29 D-04 precedent); already sets `EXIT=1` and prints a clean OK line.
- Symfony `CommandTester::setInputs()` + `--force`/`--no-interaction` handling — the standard seam
  for testing the QA-01 Phase 26 confirm gate; other command tests in `tests/` show the pattern.
- `TenantMaintenanceConfigTrait` / `TenantMailerConfigTrait` / filesystem trait — the established
  "BC-break mitigated by an opt-in trait" pattern the UPGRADE 0.4→0.5 recipe documents.

### Established Patterns
- User-guide pages: H1 title (often feature-tagged), Overview, Install/Quick-start, Configuration,
  Exceptions/FAQ/Pitfalls, "See also". New ops pages should match this shape.
- `docs-lint.sh` is `docs/`-scoped and deliberately excludes CHANGELOG.md/UPGRADE.md from stale-term
  scans — the new check follows the same scoping.
- Doctrine tenant caching: `DoctrineTenantProvider` caches resolved tenants ~300s under
  `tenancy.tenant.<slug>`; the `tenancy:maintenance:*` commands invalidate that key after flush.
  The maintenance-mode docs should reflect this (a toggle isn't visible until the cache key is
  invalidated / TTL lapses) so the runbook is accurate.

### Integration Points
- `mkdocs.yml` nav — new pages must be registered or they won't render in the built site.
- CI runs `scripts/docs-lint.sh` and `examples/saas/bin/smoke.sh` — both participate in the phase
  gate; the extended lint + the DEMO-02 fix must keep them green.
- `mkdocs build --strict` remains CI-deferred (mkdocs not installable locally); `docs-lint.sh` is
  the local green proxy (Phase 30 deferred note).

</code_context>

<specifics>
## Specific Ideas

- Ops runbook targets (D-02): rolling fleet migration during a deploy (parallel-migrations),
  enabling maintenance during a deploy + operator bypass via allow-list (maintenance-mode), and
  triaging a red readiness probe with DSN-safe output (health-checks).
- The health-checks page's k8s YAML must distinguish the cheap zero-I/O liveness probe (frequent
  poll OK) from the heavier per-tenant readiness probe (justify `periodSeconds`/`failureThreshold`).
- CDN 5xx-caching warning (D-03): both the maintenance 503 and any health 5xx must be marked
  no-cache so a CDN doesn't pin a whole tenant offline after a transient failure.
- QA-01 tests must "prove the gap is closed, not just opened" (Phase 30 D-07): the resync test
  actually exercises the confirm branch; the PHPStan test proves zero-config auto-load, not just
  that a file exists.

</specifics>

<deferred>
## Deferred Ideas

- **Global / site-wide maintenance mode** and **migration checkpoint/resume** — explicitly v0.6+/by-demand
  in REQUIREMENTS.md "Future Requirements"; not this phase.
- **`mkdocs build --strict` in CI** — still CI-deferred (mkdocs not installable locally); tracked, not
  closed here. `docs-lint.sh` remains the local proxy.
- **The 5 v0.4 Nyquist discovery flags (phases 24/26/28/29/30)** — under D-08's discovery-only stance
  these stay advisory; no code action. (Distinct from the D-10 Phase 31 backfill, which IS in scope.)

### Reviewed Todos (not folded)
None — `todo match-phase 34` returned 0 matches.

</deferred>

---

*Phase: 34-ops-docs-carry-forward*
*Context gathered: 2026-07-06*
</content>
</invoke>
