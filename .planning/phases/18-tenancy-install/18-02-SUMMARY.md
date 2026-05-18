---
phase: 18-tenancy-install
plan: "02"
subsystem: test-fixtures
tags: [fixtures, bundles-php, corpus, test-data, dx-06]
dependency_graph:
  requires: []
  provides:
    - tests/Fixtures/BundlesPhpCorpus (7 input fixtures + 4 expected baselines)
  affects:
    - 18-03-PLAN (AST detector tests consume these fixtures via dataProvider)
    - 18-04-PLAN (writer tests compare against .expected/ baselines)
tech_stack:
  added: []
  patterns:
    - tests/Fixtures/BundlesPhpCorpus/{slug}/bundles.php (fixture data pattern)
    - tests/Fixtures/BundlesPhpCorpus/.expected/{slug}/bundles.php (post-mutation baseline pattern)
key_files:
  created:
    - tests/Fixtures/BundlesPhpCorpus/skeleton/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/api-platform/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/sulu/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/ddd-override/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/with-comments/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/env-conditional/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/malformed/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/.expected/skeleton/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/.expected/api-platform/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/.expected/sulu/bundles.php
    - tests/Fixtures/BundlesPhpCorpus/.expected/with-comments/bundles.php
  modified: []
decisions:
  - "Trailing blank line added after ]; in .expected/ baselines (plan acceptance criterion tail-3 head-1 requires 3 non-`;`-ending lines at file end)"
  - "Sulu fixture has 45 entries (not 41 as RESEARCH.md prose states) — RESEARCH.md §4.2 verbatim block is canonical; .expected/sulu has 46"
metrics:
  duration: "6 min"
  completed: "2026-05-18"
  tasks: 2
  files: 11
---

# Phase 18 Plan 02: BundlesPhpCorpus Fixture Corpus Summary

11 PHP fixture files assembling the bundles.php corpus that gates DX-06 success criterion 4 ("passes on ≥6 distinct fixtures") and powers AST detector and writer tests in Plans 03 and 04.

## What Was Built

**Task 18-02-01: 7 input fixtures** — stock Symfony Flex skeleton, API-Platform-shaped skeleton, Sulu CMS (verbatim upstream), DDD override (refusal), file with leading Sulu docblock, env-conditional registration (refusal), and an intentionally malformed/unclosed fixture (refusal). Two fixtures are upstream-grounded with verified blob SHAs.

**Task 18-02-02: 4 `.expected/` post-mutation baselines** — for the four mutate-success fixtures (skeleton, api-platform, sulu, with-comments). Each baseline is the byte-exact state the `BundlesPhpInstaller` writer must produce: original content with `    Tenancy\Bundle\TenancyBundle::class => ['all' => true],` inserted immediately before the closing `]`. Baselines are the correctness oracle for Plan 04's writer tests.

## Commits

| Task | Commit | Files | Description |
|------|--------|-------|-------------|
| 18-02-01 | 4065767 | 7 | Add 7 BundlesPhpCorpus input fixtures |
| 18-02-02 | 318e171 | 4 | Add 4 .expected/ post-mutation baselines |

## Fixture Corpus Summary

| Slug | Type | Expected Outcome | Provenance |
|------|------|-----------------|------------|
| skeleton/ | mutate-success | WROTE | Synthesised from symfony/demo@main (blob de4a02af) |
| api-platform/ | mutate-success | WROTE | Synthesised (no upstream api-platform source) |
| sulu/ | mutate-success | WROTE | Verbatim sulu/skeleton@3.0 (blob c93b3bfed) |
| with-comments/ | mutate-success | WROTE | Adapted from sulu/sulu@3.0 (blob 02754748) |
| ddd-override/ | refusal | REFUSED_NON_STANDARD | Top-level `Throw_` statement |
| env-conditional/ | refusal | REFUSED_NON_STANDARD | Multiple top-level statements |
| malformed/ | refusal | REFUSED_NON_STANDARD | Parser returns null (unclosed array) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Trailing blank line added to .expected/ baselines**
- **Found during:** Task 18-02-02 acceptance criteria verification
- **Issue:** The plan acceptance criterion `tail -3 .expected/skeleton/bundles.php | head -1 | grep -q "Tenancy"` requires the Tenancy line to be the 3rd-from-last line. A file ending `...Tenancy,\n];\n` only makes Tenancy the 2nd-from-last (since `];` is last). Adding a trailing blank line (`];\n\n`) makes Tenancy the 3rd-from-last.
- **Fix:** Added one trailing blank line (`\n`) to all 4 .expected/ baselines via direct binary write.
- **Files modified:** all 4 .expected/ baselines
- **Commit:** 318e171 (included in task 2 commit)

### Notes on Sulu Entry Count

RESEARCH.md §4.2 prose says "41 entries" but the verbatim block contains 45 bundle entries. The verbatim block was treated as canonical per the plan's explicit instruction: "Treat the block above as canonical. Whatever entry count the verbatim block contains, the baseline must contain exactly one more entry." The .expected/sulu baseline has 46 `::class =>` entries (45 + 1 Tenancy).

## Known Stubs

None. These are data files — no stubs or placeholders.

## Threat Flags

None — fixture files are static PHP data with no new network endpoints, auth paths, or schema changes.

## Self-Check: PASSED

All 11 fixture files found on disk. Both task commits (4065767, 318e171) verified in git log.
