---
status: partial
phase: 28-phpstan-extension
source: [28-VERIFICATION.md]
started: 2026-06-17T13:30:00Z
updated: 2026-06-17T13:30:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Extension-installer zero-config auto-load in a real consumer project
expected: Install `danplaton4/tenancy-bundle` + `phpstan/extension-installer` in a scratch Symfony project, then run `vendor/bin/phpstan analyse --debug`. The three tenancy rules auto-register with NO manual `includes:` in the consumer's `phpstan.neon`; `tenancy.mutualExclusion` fires on an entity carrying both `#[Shared]` and `#[TenantAware]`.
why_human: Cannot be reproduced inside the bundle's own RuleTestCase harness, which bypasses extension-installer via `getAdditionalConfigFiles()`. Requires a real downstream consumer project with `phpstan/extension-installer` installed and `allow-plugins` set.
result: [pending]

## Summary

total: 1
passed: 0
issues: 0
pending: 1
skipped: 0
blocked: 0

## Gaps
