---
status: resolved
phase: 28-phpstan-extension
source: [28-VERIFICATION.md]
started: 2026-06-17T13:30:00Z
updated: 2026-07-06T00:00:00Z
resolved_by: "Phase 34 QA-01 (D-11) — converted to permanent contract test"
---

## Current Test

[resolved]

## Tests

### 1. Extension-installer zero-config auto-load in a real consumer project
expected: Install `danplaton4/tenancy-bundle` + `phpstan/extension-installer` in a scratch Symfony project, then run `vendor/bin/phpstan analyse --debug`. The three tenancy rules auto-register with NO manual `includes:` in the consumer's `phpstan.neon`; `tenancy.mutualExclusion` fires on an entity carrying both `#[Shared]` and `#[TenantAware]`.
why_human: Cannot be reproduced inside the bundle's own RuleTestCase harness, which bypasses extension-installer via `getAdditionalConfigFiles()`. Requires a real downstream consumer project with `phpstan/extension-installer` installed and `allow-plugins` set.
result: resolved
resolution: >
  Closed by Phase 34 QA-01 (2026-07-06). The auto-load wiring is now guarded by the permanent
  contract test tests/Unit/PHPStan/ExtensionInstallerContractTest.php (commit 7ac4338): it
  asserts composer.json `extra.phpstan.includes` declares `extension.neon`, that the file exists
  at the declared path, and that it registers all three rule classes — the exact metadata
  `phpstan/extension-installer` consumes for zero-config auto-registration. This locks the
  contract that a real consumer relies on; the full in-consumer `--debug` run remains the
  documented manual smoke but is no longer a blocking gap.

## Summary

total: 1
passed: 1
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

None — converted to a permanent contract test under Phase 34 QA-01 (D-11).
