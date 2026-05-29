---
phase: 23-tech-debt-closure
plan: 04
subsystem: demo-smoke
tags: [demo, smoke, mailer, mailpit, ci, regression-guard]
requirements: [DEMO-01, BOOT-04, SMOKE-MAILER-01]
dependency_graph:
  requires:
    - 21-VERIFICATION.md (live_stack_evidence — per-tenant From: pattern proven manually via Mailpit UI)
    - 20-VERIFICATION.md (BOOT-04 — TenantMessageDecorator per-tenant From/Reply-To injection contract)
  provides:
    - "Per-tenant mailer-isolation CI assertion (regression guard for TenantMessageDecorator)"
  affects:
    - examples/saas/bin/smoke.sh
tech_stack:
  added: []
  patterns:
    - "Mailpit REST API assertion (/api/v1/messages)"
    - "jq -e exit-code regression-guard pattern"
key_files:
  created: []
  modified:
    - examples/saas/bin/smoke.sh
decisions:
  - "Two tenants (acme + globex) prove isolation — initech skipped per 23-CONTEXT.md D-05"
  - "1-second sleep between POST loop and Mailpit query — cheap insurance against any worker-drain timing"
  - "PORT_MAILPIT_UI honored via local MAILPIT_PORT shadow; matches compose.yaml default 8025"
  - "Reuse existing $CURL alias (already has --fail/--retry/--timeout) — no new env vars"
metrics:
  duration_minutes: ~4
  completed_date: 2026-05-29
  tasks_completed: 1
  files_modified: 1
  line_delta: "+22 / -0"
---

# Phase 23 Plan 04: smoke.sh per-tenant mailer-isolation assertion — Summary

Extends `examples/saas/bin/smoke.sh` with a per-tenant mailer-isolation section
that POSTs `/_demo/send-test-mail` for acme + globex, then queries Mailpit's
REST API and uses `jq -e` to assert distinct `From.Address` values landed —
converting the Phase 21 human-UAT mailer-isolation proof into a permanent
demo-smoke CI gate.

## What changed

One file, one section appended before the closing `==> All smoke assertions PASSED` echo:

```bash
# Per-tenant mailer isolation — dispatch one test mail per tenant, then query
# Mailpit's REST API to assert that each tenant's distinct From: address landed.
# A regression in Phase 20 TenantMessageDecorator (per-tenant From/Reply-To
# injection) would silently strip tenant identity from outbound mail; this
# assertion catches that.
# Initech intentionally skipped — two tenants prove isolation; the per-tenant
# landing-page block above already exercises initech end-to-end.
echo "==> Per-tenant mailer isolation (Mailpit assertion)"
MAILPIT_PORT="${PORT_MAILPIT_UI:-8025}"
for slug in acme globex; do
    $CURL -X POST -H "Host: ${slug}.tenancy.localhost" "$BASE/_demo/send-test-mail" >/dev/null
done
# Give Mailpit a brief moment to ingest both messages (sync transport in the
# demo, but a 1s buffer is cheap insurance against worker-drain timing).
sleep 1
MESSAGES=$($CURL "http://127.0.0.1:${MAILPIT_PORT}/api/v1/messages")
echo "$MESSAGES" | jq -e '.messages[] | select(.From.Address == "noreply@acme.example")' >/dev/null \
    || { echo "FAIL: mailer isolation — acme From: address not found in Mailpit (TenantMessageDecorator regression?)"; exit 1; }
echo "$MESSAGES" | jq -e '.messages[] | select(.From.Address == "noreply@globex.example")' >/dev/null \
    || { echo "FAIL: mailer isolation — globex From: address not found in Mailpit (TenantMessageDecorator regression?)"; exit 1; }
echo "    PASS: acme + globex From: addresses isolated correctly"
```

## Why this matters

`.planning/phases/21-demo-app/21-VERIFICATION.md` (live_stack_evidence section)
documents the per-tenant `From:` isolation pattern as manually verified through
the Mailpit UI for all three demo tenants. The v0.3 milestone audit
(`.planning/v0.3-MILESTONE-AUDIT.md`) flagged the absence of a CI assertion
for this: a regression in `TenantMessageDecorator` (Phase 20) — for example,
a wiring fault where the active tenant's `mailerFrom` stops flowing into the
outbound `Email` — would have shipped silently. This plan closes that gap.

The script exits non-zero with a clear "TenantMessageDecorator regression?"
hint when either expected `From:` address is missing, so a future contributor
seeing a red CI run can land on the offending component immediately.

## Verification

Automated gates (all green):

- `bash -n examples/saas/bin/smoke.sh` — syntax clean
- `grep -c "==> Per-tenant mailer isolation" …` → 1
- `grep -c "jq -e" …` → 2
- `grep -c "noreply@acme.example" …` → 1
- `grep -c "noreply@globex.example" …` → 1
- `grep -c "send-test-mail" …` → 1
- `grep -c "PORT_MAILPIT_UI" …` → 1
- Final non-empty line preserved: `echo "==> All smoke assertions PASSED"`
- File remains executable (mode 755 preserved by Edit)
- Pre-commit hook passed (php-cs-fixer + PHPStan level 9 + PHPUnit 568 tests / 2122 assertions)

Live-stack run intentionally deferred to Plan 23-07 (Wave 3 — full integration
smoke against the live demo stack).

## Live-stack verification status

**Skipped (environmental constraint, not a defect of this plan).**

Attempted: `BASE_PORT=8081 PORT_HTTP=8081 PORT_MAILPIT_UI=8026 docker compose up -d --wait --build` inside `examples/saas/`.

Result: Docker build failed at `composer install` step. The demo's
`composer.lock` resolved several Symfony components to `v8.0.x` series, which
require PHP `>=8.4`, but the demo `Dockerfile` installs PHP `8.2`. This is a
pre-existing lockfile/runtime mismatch in the demo (unrelated to smoke.sh —
the lockfile was generated under a PHP 8.4 environment and not refreshed for
the 8.2 demo image).

Per execution rules, this is out of scope for plan 23-04 (single-file scope:
`examples/saas/bin/smoke.sh`) and logged as a deferred item below for a future
plan to address.

The script syntax + grep gates + final-line invariant are all verified locally,
and the change will be exercised against the live stack in Plan 23-07 once the
lockfile/PHP-version drift is resolved.

## Deferred Issues

**Demo Dockerfile/composer.lock PHP version drift** (NOT in scope for 23-04):

- `examples/saas/Dockerfile` installs PHP 8.2; `examples/saas/composer.lock`
  has resolved core Symfony components to `v8.0.x` series which require
  `>=8.4`. `docker compose up --build` fails at `composer install`.
- Recommended fix path: either bump the demo `Dockerfile` to PHP 8.4, or run
  `composer update --prefer-lowest` inside the demo to pin components back to
  PHP-8.2-compatible series.
- Out of scope here — file out of plan's allowed surface
  (`examples/saas/bin/smoke.sh` only). Logged for a follow-up plan
  (likely 23-07 or a v0.3.3 hotfix plan if the CI image is also affected).

## Deviations from Plan

None — plan executed exactly as written.

## Threat Flags

None — change is test-only (CI assertion script). No new network endpoints,
no auth paths, no schema changes. The new `curl` call to Mailpit's API is
loopback-only (`127.0.0.1`) and inherits the existing `$CURL` flag set
(--fail, --max-time 10, --retry 5).

## Self-Check: PASSED

- `examples/saas/bin/smoke.sh` modified (verified via `git show 52bb045 --stat`)
- Commit `52bb045` exists in `master` history
- All acceptance grep gates returned the required counts
- Final line invariant preserved
- Pre-commit hook (cs-fixer + PHPStan level 9 + PHPUnit) passed
