---
phase: 21-demo-app
plan: "04"
subsystem: verification
tags: [smoke-script, ci, github-actions, readme, gitignore, demo]

requires:
  - plan: 21-01
    provides: composer.json + app scaffold + .env
  - plan: 21-02
    provides: /health, LandlordController, TenantController, DemoMailController
  - plan: 21-03
    provides: compose.yaml, Dockerfile, Caddyfile, entrypoint.sh

provides:
  - "examples/saas/bin/smoke.sh: host-side DNS-independent curl smoke; readiness + landlord + per-tenant + OriginHeaderResolver"
  - ".github/workflows/demo-smoke.yml: CI gate on push/PR to master; checkout@v5 + --wait --build + log dump on failure + teardown always"
  - "examples/saas/README.md: long-form user walkthrough with three-step fallback ladder, Profiler, Mailer, Origin, HTTPS-optional, dev-loop, security, file layout"
  - "README.md (root): Try the demo section linking to examples/saas/README.md"
  - ".gitignore (root): examples/saas/var/, vendor/, .env.local, .env.*.local"

affects: [DEMO-01.2, DEMO-01.3, DEMO-01.4, DEMO-01.5]

tech-stack:
  added:
    - "GitHub Actions workflow: demo-smoke (push/PR on master)"
  patterns:
    - "actions/checkout@v5 (matches in-repo ci.yml line 21)"
    - "docker compose up -d --wait --build (D-15 readiness gate)"
    - "if: failure() log dump + if: always() teardown"
    - "Host-side smoke: curl --retry 5 --retry-all-errors --retry-connrefused"
    - "readiness loop: curl -sf /health max 30s (no fixed sleep)"

key-files:
  created:
    - examples/saas/bin/smoke.sh
    - .github/workflows/demo-smoke.yml
    - examples/saas/README.md
  modified:
    - README.md
    - .gitignore

decisions:
  - "Smoke script runs on host (D-13) with Host: header injection — same script local + CI"
  - "CI workflow uses checkout@v5 (ci.yml line 21 match), NOT v4 as RESEARCH excerpt erroneously showed"
  - "Task 4 (human-verify checkpoint) auto-approved under --auto mode; human walkthrough deferred to post-merge manual step"
  - "Root .gitignore appended (not overwritten) with examples/saas/{var,vendor,.env.local,.env.*.local}"
  - "vendor/ symlink created in worktree to resolve pre-commit hook's php-cs-fixer lookup (Rule 3 auto-fix)"

metrics:
  duration: 4min
  completed: "2026-05-22"
  tasks_completed: 3
  tasks_total: 4
  files_created: 3
  files_modified: 2
---

# Phase 21 Plan 04: Smoke Script + CI Gate + Walkthrough README Summary

Host-side DNS-independent curl smoke (`bin/smoke.sh`), GitHub Actions CI gate (`demo-smoke.yml` with `checkout@v5`, `--wait --build`, log-on-failure), and long-form user walkthrough README (`examples/saas/README.md`) including the three-step fallback ladder, Profiler/WDT walkthrough, Mailer/Mailpit walkthrough, OriginHeaderResolver example, HTTPS-optional section, and bundle-source dev loop.

## Performance

- **Duration:** 4 min
- **Started:** 2026-05-22T11:44:47Z
- **Completed:** 2026-05-22T11:49:13Z
- **Tasks:** 3/4 auto (Task 4 is human-verify, auto-approved under --auto mode)
- **Files created:** 3
- **Files modified:** 2

## What Was Built

### bin/smoke.sh

Host-side curl smoke script. Designed to run on the CI runner or developer's local machine — not inside the container. Uses `Host:` headers to route requests through Caddy without needing DNS resolution.

Structure:
1. **Readiness loop** — curls `/health` every 1s for up to 30s; exits 1 on timeout. No fixed `sleep`. Replaces any prior `sleep 30` anti-pattern.
2. **Landlord assertion** — `curl -H "Host: tenancy.localhost"` verifies all three slugs (acme, globex, initech) appear on the landlord page.
3. **Per-tenant body markers** — curls each subdomain with `Host: $slug.tenancy.localhost`; asserts `Acme Corporation`, `Globex Industries`, `Initech LLC` respectively (D-14 fixture-distinct markers).
4. **OriginHeaderResolver path** — `Host: tenancy.localhost` + `Origin: https://acme.tenancy.localhost`; asserts `Acme Corporation` in body (Phase 17 invariant).

Key flags: `--fail --max-time 10 --retry 5 --retry-all-errors --retry-connrefused` — handles transient errors without sleep loops (RESEARCH "Don't Hand-Roll").

### .github/workflows/demo-smoke.yml

CI workflow shape:

| Step | Condition | Command |
|------|-----------|---------|
| Checkout | always | `actions/checkout@v5` |
| Build and start | always | `docker compose up -d --wait --build` |
| Run smoke | always | `bash bin/smoke.sh` |
| Dump container logs | `if: failure()` | `docker compose logs` |
| Tear down | `if: always()` | `docker compose down -v` |

Key decisions:
- `actions/checkout@v5` — matches `.github/workflows/ci.yml` line 21. The RESEARCH excerpt at line 757 erroneously showed `v4`; this was explicitly corrected per PATTERNS §"Pattern D".
- `timeout-minutes: 10` — cold boot is ~110s; this provides headroom for slow CI runners.
- `defaults.run.working-directory: examples/saas` — keeps all `docker compose` invocations + `bash bin/smoke.sh` short; avoids `cd examples/saas &&` on every step.
- `--wait --build` — `--wait` blocks until all healthchecks pass; `--build` ensures the image is rebuilt on every CI run (catches Dockerfile drift per D-15).

### examples/saas/README.md

Long-form user walkthrough. Sections:

| Section | CONTEXT ref | Acceptance criterion |
|---------|-------------|----------------------|
| Two-minute boot | D-11 | docker compose command + URL list |
| Three-step fallback | D-12 | DEMO-01.3 + VALIDATION.md grep gate |
| Profiler walkthrough | D-07 | WDT panel description |
| Mailer walkthrough | D-09 | Mailpit localhost:8025 + curl POST per tenant |
| OriginHeaderResolver scenario | D-08 | Host: tenancy.localhost + Origin: https://acme |
| Optional HTTPS | D-11, RESEARCH Pitfall 4 | caddy trust + Firefox manual CA import |
| What if I want to install... | D-10 | tenancy:install reference (not exercised) |
| Bundle-source dev loop | CONTEXT Critical Edge | bind-mount + OPcache revalidate_freq=0 |
| Smoke + CI | DEMO-01.4/5 | bin/smoke.sh + workflow reference |
| Security notes | T-21-* | demo-only warnings |
| File layout | output spec | directory tree |

### Root README.md

"Try the demo" subsection added after Quick Start section:
- docker compose up command
- Links to `examples/saas/README.md` twice (inline href + prose)
- Three-sentence summary: tenants, stack, what's inside

### Root .gitignore

Appended (no overwrite):
```
# Demo app generated files
/examples/saas/var/
/examples/saas/vendor/
/examples/saas/.env.local
/examples/saas/.env.*.local
```

## Task Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | bin/smoke.sh + .gitignore demo entries | `ca9d502` | examples/saas/bin/smoke.sh, .gitignore |
| 2 | demo-smoke CI workflow + root README pointer | `9364d1a` | .github/workflows/demo-smoke.yml, README.md |
| 3 | examples/saas/README.md (long-form walkthrough) | `157ca99` | examples/saas/README.md |
| 4 | (checkpoint:human-verify) | auto-approved | — |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] vendor/ symlink in worktree for pre-commit hook**
- **Found during:** Task 1 commit
- **Issue:** Pre-commit hook runs `vendor/bin/php-cs-fixer` relative to the worktree working directory. The worktree does not have its own `vendor/` (it shares the main repo's `.git`).
- **Fix:** Created `vendor` symlink in the worktree root pointing to `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/vendor`. The symlink is not staged/tracked in git (covered by root `.gitignore`'s `vendor/` entry).
- **Files modified:** None tracked; symlink is ephemeral to worktree lifecycle.

**2. Task 4 auto-approved (checkpoint:human-verify under --auto)**
- Per `<auto_mode>` instructions: human-verify checkpoints are auto-approved when running under `--auto`. Task 4 requires a real browser + running docker compose stack — the automated verification suite covers all scriptable assertions (smoke script syntax, YAML validity, file existence, grep gates). Human walkthrough (WDT panel, Mailpit From addresses, browser-native *.localhost) is deferred to the user's post-merge manual step.

## Required Follow-Up

After first successful push to master:
- **Add `demo-smoke` as a required status check** on the GitHub repository (repo admin → Settings → Branches → Branch protection rules → master → "Require status checks to pass before merging" → add `smoke`). This is a manual repo-admin step — GitHub requires at least one run to have occurred before the check can be selected.

## Known Stubs

None. All files produce their stated output. The README documents the demo-only nature of credentials and unauthenticated endpoints.

## Threat Flags

No new threat surface beyond the plan's registered threat model.

| Threat | Implementation |
|--------|----------------|
| T-21-CI: No secrets leak | Workflow uses zero secrets — demo env vars are committed defaults |
| T-21-IM: Image supply chain | All images pinned in compose.yaml by version tag (Plan 03) |
| T-21-RM: README copy-paste commands | All read-only curl; single destructive `docker compose down -v` is documented |

## Self-Check

### Created files exist
- `examples/saas/bin/smoke.sh` — FOUND
- `.github/workflows/demo-smoke.yml` — FOUND
- `examples/saas/README.md` — FOUND

### Modified files exist
- `README.md` — FOUND (contains "Try the demo")
- `.gitignore` — FOUND (contains examples/saas/var/)

### Commits exist
- `ca9d502` — feat(21-04): bin/smoke.sh + .gitignore demo entries
- `9364d1a` — feat(21-04): demo-smoke CI workflow + root README pointer
- `157ca99` — feat(21-04): examples/saas/README.md (long-form walkthrough)

### Verification checks
1. `bash -n examples/saas/bin/smoke.sh` exits 0 — PASSED
2. `test -x examples/saas/bin/smoke.sh` — PASSED
3. `.github/workflows/demo-smoke.yml` YAML valid (python3 yaml.safe_load) — PASSED
4. `grep -c 'examples/saas/README.md' README.md` = 2 — PASSED
5. `grep -c '## Three-step fallback' examples/saas/README.md` = 1 — PASSED
6. Human checkpoint (Task 4) — auto-approved under --auto mode

## Self-Check: PASSED

---
*Phase: 21-demo-app*
*Completed: 2026-05-22*
