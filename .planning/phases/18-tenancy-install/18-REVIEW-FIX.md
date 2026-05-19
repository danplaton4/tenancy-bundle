---
phase: 18-tenancy-install
fixed_at: 2026-05-18T00:00:00Z
review_path: .planning/phases/18-tenancy-install/18-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 18: Code Review Fix Report

**Fixed at:** 2026-05-18
**Source review:** .planning/phases/18-tenancy-install/18-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 4
- Fixed: 4
- Skipped: 0

## Fixed Issues

### WR-01 + WR-02: `buildMutatedSource()` — empty array and no-trailing-comma cases

**Files modified:** `src/Command/Install/BundlesPhpInstaller.php`, `tests/Unit/Command/Install/BundlesPhpInstallerTest.php`, `tests/Fixtures/BundlesPhpCorpus/empty-array/bundles.php`, `tests/Fixtures/BundlesPhpCorpus/.expected/empty-array/bundles.php`, `tests/Fixtures/BundlesPhpCorpus/no-trailing-comma/bundles.php`, `tests/Fixtures/BundlesPhpCorpus/.expected/no-trailing-comma/bundles.php`, `.php-cs-fixer.dist.php`
**Commit:** `e40d9a8`
**Applied fix:**

`buildMutatedSource()` was refactored from a two-branch if/else into three distinct cases:

- **Comma present** (`$prevChar === ','`): no change from before — `$prefix = ''`.
- **Empty array** (`$prevChar === '['`, WR-01): `$prefix = $lineEnding` so the new entry opens on its own line. Previously `$prefix = ''` jammed the entry on the same line as `[`.
- **No trailing comma** (`else`, WR-02): a comma is spliced directly at `$prevNonSpace + 1` (immediately after the last character of the previous entry), then `$endPos` is bumped by one byte and `$prefix = ''`. Previously the comma was prepended to the insertion point, landing it on column 0 of a new line.

Two new fixture pairs were added (`empty-array` and `no-trailing-comma`) with expected baselines and registered in `fixturesProvider`. The `testDetect` assertion for FrameworkBundle presence was made conditional to skip the empty-array fixture (intentionally has no entries). The BundlesPhpCorpus directory was added to the php-cs-fixer exclusion list to prevent it from adding `declare(strict_types=1)` and trailing commas to intentionally non-standard fixture files.

---

### WR-03: `InstallResult::isSuccessOutcome()` — dead code with contradictory docblock

**Files modified:** `src/Command/Install/InstallResult.php`
**Commit:** `5d8a74b`
**Applied fix:**

Removed the `isSuccessOutcome()` method entirely. A grep across `src/` and `tests/` confirmed zero callers. The docblock claimed "WROTE or ALREADY_REGISTERED" but the implementation also returned `true` for `REFUSED_NON_STANDARD`, directly contradicting the stated contract. Removal (preferred over alignment) eliminates the misleading API surface and the dead code simultaneously.

---

### WR-04: `TenancyInstallCommand::execute()` — switch without default may silently return null

**Files modified:** `src/Command/TenancyInstallCommand.php`
**Commit:** `57d39d3`
**Applied fix:**

Added a `default` branch to the switch that throws `\LogicException('Unhandled InstallStatus: '.$result->status->value)`. Without it, adding a new `InstallStatus` case without updating the switch would cause PHP to fall off the end of `execute()`, producing a `TypeError` from Symfony's `Command::run()` with an unhelpful "null returned" message. The `default: throw` makes the root cause (unhandled enum case) immediately visible.

The reviewer's preferred fix was `match` (which throws `UnhandledMatchError` natively), but extracting each multi-statement case body into private methods to satisfy `match`'s expression-only constraint would be a larger refactoring beyond the scope of this review fix. The `default: throw` achieves the same safety guarantee and is the reviewer's documented alternative.

## Skipped Issues

None — all four in-scope findings were fixed.

---

_Fixed: 2026-05-18_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
