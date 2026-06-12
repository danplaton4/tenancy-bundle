---
status: partial
phase: 26-tenancy-shared-resync-command
source: [26-VERIFICATION.md]
started: 2026-06-12T21:38:53Z
updated: 2026-06-12T21:38:53Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Interactive TTY confirm prompt (SHARE-02-c)
expected: Run `bin/console tenancy:shared:resync` in a real TTY against a dev fixture (at least one `#[Shared]` entity seeded on landlord). The drift summary table renders correctly with tenant rows and Would-Insert/Would-Update/In-Sync columns, and the interactive `[y/N]` prompt appears with default-No behavior — pressing Enter aborts cleanly with SUCCESS exit.
result: [pending]

## Summary

total: 1
passed: 0
issues: 0
pending: 1
skipped: 0
blocked: 0

## Gaps
