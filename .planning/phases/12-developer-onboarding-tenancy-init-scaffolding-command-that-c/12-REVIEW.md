---
phase: 12-developer-onboarding-tenancy-init-scaffolding-command-that-c
reviewed: 2026-04-13T00:00:00Z
depth: standard
files_reviewed: 6
files_reviewed_list:
  - src/Command/TenantInitCommand.php
  - tests/Unit/Command/TenantInitCommandTest.php
  - tests/Integration/Command/TenantInitCommandIntegrationTest.php
  - config/services.php
  - tests/Integration/Command/Support/MakeCommandsPublicPass.php
  - tests/bootstrap.php
findings:
  critical: 0
  warning: 2
  info: 2
  total: 4
status: issues_found
---

# Phase 12: Code Review Report

**Reviewed:** 2026-04-13T00:00:00Z
**Depth:** standard
**Files Reviewed:** 6
**Status:** issues_found

## Summary

This changeset adds a `tenancy:init` scaffolding command that generates a starter `tenancy.yaml` configuration file. The implementation is clean and well-structured: proper use of `SymfonyStyle`, defensive `--force` flag, Doctrine auto-detection, and solid test coverage across both unit and integration suites. The `tests/bootstrap.php` change for git worktree support is reasonable.

Two warnings relate to missing error handling for filesystem operations (`mkdir` and `file_put_contents`) in the command, which could fail silently on permission or disk errors. Two informational items flag minor inconsistencies.

## Warnings

### WR-01: Unchecked mkdir return value

**File:** `src/Command/TenantInitCommand.php:53`
**Issue:** `mkdir($dir, 0755, true)` can return `false` if the directory cannot be created (permissions, disk full, read-only filesystem). The command proceeds to `file_put_contents` on the next line, which will then also fail, producing a confusing PHP warning rather than a clear user-facing error message.
**Fix:**
```php
$dir = \dirname($targetPath);
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    $io->error('Could not create directory: '.$dir);

    return Command::FAILURE;
}
```

### WR-02: Unchecked file_put_contents return value

**File:** `src/Command/TenantInitCommand.php:56`
**Issue:** `file_put_contents($targetPath, $yamlContent)` returns `false` on failure (disk full, permission denied). The command reports success even if the write failed, which would mislead the developer into thinking the file was created.
**Fix:**
```php
if (file_put_contents($targetPath, $yamlContent) === false) {
    $io->error('Could not write configuration file: '.$targetPath);

    return Command::FAILURE;
}
```

## Info

### IN-01: Inconsistent leading backslash on Doctrine FQCN

**File:** `src/Command/TenantInitCommand.php:47`
**Issue:** The command uses `\Doctrine\ORM\EntityManagerInterface::class` (with leading backslash), while `config/services.php:85` uses `Doctrine\ORM\EntityManagerInterface::class` (without). Both work identically in practice since `::class` always resolves to the fully-qualified name, but the inconsistency within the same codebase is a minor style issue. The `config/services.php` style (no leading backslash) matches the existing `use` import conventions elsewhere in the file.
**Fix:** Either style is correct. For consistency, pick one and apply it everywhere. Since `config/services.php` already uses the no-backslash form and was normalized in this diff, consider removing the leading backslash in the command:
```php
$doctrineDetected = interface_exists(Doctrine\ORM\EntityManagerInterface::class);
```
Or add a `use` import at the top of `TenantInitCommand.php` and reference the short name.

### IN-02: Generated YAML recommends database_per_tenant when Doctrine is absent

**File:** `src/Command/TenantInitCommand.php:66-69`
**Issue:** When Doctrine ORM is not detected, the output says "recommended driver: shared_db" but `shared_db` also requires Doctrine ORM (it uses the Doctrine SQL filter `TenantAwareFilter`). The messaging could be clearer -- both drivers require Doctrine, but `shared_db` uses a single database with a SQL filter while `database_per_tenant` uses separate databases. The recommendation to "install doctrine/orm to use database_per_tenant mode" is technically correct but incomplete since `shared_db` mode also needs Doctrine.
**Fix:** Consider rewording to clarify that Doctrine is needed for either driver, or simply state that neither database driver is available without Doctrine:
```php
$io->text([
    'Doctrine ORM not detected — database isolation drivers require doctrine/orm.',
    'Install doctrine/orm to use database_per_tenant or shared_db mode.',
]);
```

---

_Reviewed: 2026-04-13T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
