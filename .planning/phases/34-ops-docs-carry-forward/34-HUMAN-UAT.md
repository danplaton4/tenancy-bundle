---
status: resolved
phase: 34-ops-docs-carry-forward
source: [34-VERIFICATION.md]
started: "2026-07-06T00:00:00Z"
updated: "2026-07-06T00:00:00Z"
---

## Current Test

[all items resolved]

## Tests

### 1. DEMO-02 examples/saas smoke test on PHP 8.2
expected: `composer install` succeeds on PHP 8.2 against the regenerated lock (platform-pinned `config.platform.php=8.2.99`); the FrankenPHP 8.2 container boots; `bin/smoke.sh` exits 0 with all landlord + per-tenant + resolver-priority + mailer-isolation assertions passing.
result: passed
evidence: >
  Verified live during phase execution (2026-07-06) via local Docker with user approval.
  Built and started the examples/saas stack (`docker compose up -d --wait --build`) on the
  FrankenPHP 8.2 base with the regenerated lock — all containers healthy. Container PHP
  version confirmed `PHP 8.2.32 (cli) (ZTS)`. `bin/smoke.sh` ran green (readiness, landlord
  index acme/globex/initech, per-tenant landing pages, HostResolver-beats-OriginHeaderResolver
  priority, per-tenant mailer isolation) — exit 0. Stack torn down with `docker compose down -v`.
  CI `demo-smoke` job provides the ongoing regression gate.

## Summary

total: 1
passed: 1
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

None — the sole human-verification item was executed and passed live during this phase's execution.
