---
phase: 18-tenancy-install
reviewed: 2026-05-18T00:00:00Z
depth: standard
files_reviewed: 29
files_reviewed_list:
  - CHANGELOG.md
  - composer.json
  - config/services.php
  - src/Command/Install/BundlesPhpInstaller.php
  - src/Command/Install/BundlesPhpInstallerInterface.php
  - src/Command/Install/DetectionResult.php
  - src/Command/Install/InstallResult.php
  - src/Command/Install/InstallStatus.php
  - src/Command/TenancyInstallCommand.php
  - tests/Fixtures/BundlesPhpCorpus/.expected/api-platform/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/.expected/skeleton/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/.expected/sulu/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/.expected/with-comments/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/api-platform/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/ddd-override/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/env-conditional/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/malformed/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/skeleton/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/sulu/bundles.php
  - tests/Fixtures/BundlesPhpCorpus/with-comments/bundles.php
  - tests/Integration/Command/Support/InstallCommandTestKernel.php
  - tests/Integration/Command/Support/MakeCommandsPublicPass.php
  - tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php
  - tests/Integration/Command/TenancyInstallCommandIntegrationTest.php
  - tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php
  - tests/Unit/Command/Install/BundlesPhpInstallerTest.php
  - tests/Unit/Command/TenancyInstallCommandTest.php
  - tests/Unit/Composer/ComposerJsonContractTest.php
  - tests/bootstrap.php
findings:
  critical: 0
  warning: 4
  info: 3
  total: 7
status: issues_found
---

# Phase 18: Code Review Report

**Reviewed:** 2026-05-18
**Depth:** standard
**Files Reviewed:** 29
**Status:** issues_found

## Summary

Phase 18 delivers the `tenancy:install` console command: an AST-driven `config/bundles.php`
mutator backed by `nikic/php-parser`, plus delegation to `tenancy:init`. The architecture is
sound — the detect/write separation is clean, the backup/lint/restore safety chain is correctly
implemented (Filesystem::copy, not rename, so the .bak survives every path), and the DI
registration is correct. The fixture corpus covers the important refusal cases.

Four warnings were found, all in `BundlesPhpInstaller::buildMutatedSource()`. Two relate to
untested edge-case inputs (empty array, no-trailing-comma last entry) that produce syntactically
valid but malformatted output. These are provable bugs against the documented intent of the
method. The other two are a dead method with a wrong docblock and a fragile switch-without-default.
Three informational items cover dead test state, a design smell in the status enum, and a
cosmetic trailing-newline note.

---

## Warnings

### WR-01: `buildMutatedSource()` — empty array produces missing newline after opening bracket

**File:** `src/Command/Install/BundlesPhpInstaller.php:210`

**Issue:** When `bundles.php` contains an empty array (`return [];`), `getEndFilePos()` returns
the index of `]`, and the walk-backwards loop finds `[` as `$prevChar`. The `prefix` is then
set to `''` (no comma, no newline). The mutation becomes:

```
return [    Tenancy\Bundle\TenancyBundle::class => ['all' => true],
];
```

The new entry is jammed immediately after `[` on the same line with no newline separator. The
output is syntactically valid PHP (`php -l` passes), so the lint guard does **not** catch this,
and a permanently malformatted `bundles.php` is committed to the project. There is no fixture
for this case in the corpus, so no test exercises or catches the bug.

**Fix:** When `$prevChar === '['`, the prefix should be `$lineEnding` (a newline to open the
array body), not `''`. Update the branching logic:

```php
if (',' === $prevChar) {
    $prefix = '';
} elseif ('[' === $prevChar) {
    // Empty array: need a newline to start the body, no comma
    $prefix = $lineEnding;
} else {
    // Previous entry has no trailing comma; supply one
    $prefix = ','.$lineEnding;
}
```

Add a corresponding fixture `tests/Fixtures/BundlesPhpCorpus/empty-array/bundles.php` with a
matching `.expected/` baseline to gate this case in CI.

---

### WR-02: `buildMutatedSource()` — no-trailing-comma last entry places comma on a bare line

**File:** `src/Command/Install/BundlesPhpInstaller.php:208-218`

**Issue:** When the last array entry has no trailing comma (e.g., `A::class => ['all' => true]`
without a `,`), the backwards walk stops at `]` (the closing bracket of the value array), which
is not `,` or `[`. The prefix becomes `','.$lineEnding`, which is inserted **before the
new entry**. The result is:

```php
return [
    A::class => ['all' => true]
,
    Tenancy\Bundle\TenancyBundle::class => ['all' => true],
];
```

The comma lands on column 0 of a new line — syntactically valid PHP, but not well-formed and
clearly wrong. Again `php -l` passes, so the lint guard does not catch this, and the
malformatted file is committed. There is no fixture for this case in the corpus.

The method comment explicitly acknowledges this case: *"(e.g., previous entry has no trailing
comma), prepend `,`"* — confirming the intent is to handle it, but the string-offset approach
cannot insert a comma at the correct position (end of the previous line) by prepending to the
insertion point.

**Fix:** Compute the position to insert the comma separately from the position to insert the
new entry. Specifically, find `$prevNonSpace` (already computed) and insert the comma there,
then build the new-entry line at `$endPos`:

```php
// If the last non-space char before ']' is not a comma or '[', we need to
// append a comma right after that char — not at the insertion point.
if (',' !== $prevChar && '[' !== $prevChar) {
    // Insert comma immediately after $prevNonSpace, then fall through to insert entry
    $commaInserted = substr($source, 0, $prevNonSpace + 1)
        .','
        .substr($source, $prevNonSpace + 1);
    // Recompute endPos since we added one byte
    $source = $commaInserted;
    $endPos += 1;
}
$prefix = ('[' === $prevChar) ? $lineEnding : '';
return substr($source, 0, $endPos).$prefix.$entry.$lineEnding.substr($source, $endPos).$lineEnding;
```

Add a fixture `tests/Fixtures/BundlesPhpCorpus/no-trailing-comma/bundles.php` to gate this in CI.

---

### WR-03: `InstallResult::isSuccessOutcome()` — docblock contradicts implementation; method is dead code

**File:** `src/Command/Install/InstallResult.php:59-67`

**Issue:** The docblock states *"was this a write outcome (WROTE or ALREADY_REGISTERED)?"* but
the implementation also returns `true` for `REFUSED_NON_STANDARD`:

```php
public function isSuccessOutcome(): bool
{
    return InstallStatus::WROTE === $this->status
        || InstallStatus::ALREADY_REGISTERED === $this->status
        || InstallStatus::REFUSED_NON_STANDARD === $this->status;  // contradicts docblock
}
```

`REFUSED_NON_STANDARD` is a non-mutating refusal — including it under "success outcome" directly
contradicts the docblock's stated intent. Additionally, `grep` across the entire codebase
(`src/` and `tests/`) finds **zero callers** of this method. It is dead code that cannot be
tested and carries a misleading contract.

**Fix:** Either remove the method entirely (preferred, since it has no callers), or align the
implementation with the docblock by removing `REFUSED_NON_STANDARD`:

```php
public function isSuccessOutcome(): bool
{
    return InstallStatus::WROTE === $this->status
        || InstallStatus::ALREADY_REGISTERED === $this->status;
}
```

---

### WR-04: `TenancyInstallCommand::execute()` — switch without default may silently return `null`

**File:** `src/Command/TenancyInstallCommand.php:69-121`

**Issue:** `execute()` is typed `: int` and the `switch` covers all five current `InstallStatus`
cases. There is no `default` branch and no `return` statement after the `switch` block. If a
new `InstallStatus` case is added in the future without updating this switch, PHP falls off the
end of `execute()` without returning. With `strict_types=1` and a `: int` return type, this
produces a `TypeError` when Symfony's `Command::run()` attempts to use the return value. The
error message ("Return value must be of type int, null returned") is unhelpful and the root
cause (unhandled enum case) is invisible.

Using `match` instead of `switch` would cause PHP to throw an `UnhandledMatchError` pointing
directly at the unmatched value. Alternatively, a `default` that throws is an improvement over
silent fall-through.

**Fix:** Replace `switch` with `match` (idiomatic PHP 8.1+ for exhaustive enum dispatch), which
throws `UnhandledMatchError` on unhandled cases:

```php
return match ($result->status) {
    InstallStatus::DEV_DEPENDENCY_MISSING => $this->handleDevDependencyMissing($io),
    InstallStatus::REFUSED_NON_STANDARD   => $this->handleRefused($result, $io),
    InstallStatus::LINT_FAILED_RESTORED   => $this->handleLintFailed($result, $io),
    InstallStatus::WROTE                  => $this->handleWrote($result, $input, $output, $io),
    InstallStatus::ALREADY_REGISTERED     => $this->handleAlreadyRegistered($input, $output, $io),
};
```

Or, at minimum, add a `default` at the bottom of the switch:

```php
default:
    throw new \LogicException('Unhandled InstallStatus: '.$result->status->value);
```

---

## Info

### IN-01: `BundlesPhpInstallerStub::$expectNeverCalled` is set but never read — dead state

**File:** `tests/Unit/Command/TenancyInstallCommandTest.php:37,224`

**Issue:** `$installer->expectNeverCalled = true` is set in
`testForceAndDryRunMutuallyExclusiveReturnsInvalid()` but the `install()` method of the stub
never reads this field. The actual assertion (`self::assertFalse($installer->wasCalled)`) is
correct and sufficient. The `$expectNeverCalled` field is dead state — it communicates
developer intent but is never enforced by the stub itself.

**Fix:** Remove the `$expectNeverCalled` field and its assignment, or enforce it inside
`install()`:

```php
public function install(string $bundlesPhpPath, bool $dryRun = false): InstallResult
{
    if ($this->expectNeverCalled) {
        throw new \LogicException('install() was called but expectNeverCalled is true');
    }
    $this->wasCalled = true;
    $this->receivedDryRun = $dryRun;
    return $this->result;
}
```

---

### IN-02: `InstallResult::dryRun()` reuses `WROTE` status — disambiguation relies on null-diff check

**File:** `src/Command/Install/InstallResult.php:33-36`

**Issue:** `InstallResult::dryRun()` constructs a result with `InstallStatus::WROTE` and
`$diff` set. `TenancyInstallCommand::execute()` then distinguishes dry-run from a real write
by checking `null !== $result->diff` inside the `WROTE` case. This dual-purpose use of a single
status case is fragile: both real-write and dry-run results share the same `case` branch, and
the behavioral split is hidden inside a null-check on an ancillary field. A dedicated
`InstallStatus::DRY_RUN` case would make the dispatch explicit and would allow PHPStan to
enforce the status/field correspondence at compile time.

**Fix:** Add `case DRY_RUN = 'dry_run'` to `InstallStatus`, update `InstallResult::dryRun()`
to use it, and add a separate `case InstallStatus::DRY_RUN:` branch in `execute()`. This also
removes the ambiguity in `isSuccessOutcome()` (WR-03).

---

### IN-03: `buildMutatedSource()` appends a trailing newline unconditionally, producing a double newline when source already ends with `\n`

**File:** `src/Command/Install/BundlesPhpInstaller.php:218`

**Issue:** The final line of `buildMutatedSource()` is:

```php
return substr($source, 0, $endPos).$prefix.$entry.$lineEnding.substr($source, $endPos).$lineEnding;
```

`substr($source, $endPos)` is `];\n` (for a source that already ends in `\n`). Appending
`$lineEnding` again produces `];\n\n` — a double trailing newline. The expected baseline
fixtures in `.expected/` match this (they also end with `\n\n`), which confirms this is
intentional, but it deviates from the Symfony PHP CS Fixer convention of a single trailing
newline. If `php-cs-fixer` is run post-install, it will strip the extra newline, making the
file differ from the baseline. No current test exercises the CS-fixer-then-re-detect flow.

**Fix:** Only append the trailing `$lineEnding` if `$source` does not already end with one:

```php
$tail = substr($source, $endPos);
$trailing = str_ends_with($tail, $lineEnding) ? '' : $lineEnding;
return substr($source, 0, $endPos).$prefix.$entry.$lineEnding.$tail.$trailing;
```

Update the `.expected/` baselines (remove the blank final line from each) and rerun the
idempotency test to confirm CS-fixer-clean output.

---

_Reviewed: 2026-05-18_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
