# Phase 19 Deferred Items

## Cross-worktree autoload collision in full-suite phpunit run

**Discovered during:** Plan 19-06 execution
**Scope:** Pre-existing infrastructure issue — NOT caused by this plan's changes (verified by `git stash` + re-run).

**Symptom:** Running `vendor/bin/phpunit` from inside a worktree fails partway through with:
```
Cannot redeclare class Tenancy\Bundle\Entity\Tenant
(previously declared in /.../worktrees/.../src/Entity/Tenant.php:13)
in /.../symfony-multitenancy/src/Entity/Tenant.php on line 13
```

The worktree's autoloader (registered in `tests/bootstrap.php` via `prepend: true`) loads
`Tenant.php` from the worktree's `src/`, but a later integration test boots a kernel whose
Doctrine mapping or autoload cache resolves `Tenant.php` from the parent repo's `src/` path,
triggering the redeclare fatal error.

**Workaround:** Tests run cleanly when invoked per-suite (`--testsuite unit`,
`tests/Integration/Profiler`, etc.) — only the full-suite run interleaves both paths.

**Action:** Out of scope for plan 19-06 (which adds a single integration test). Track for
a future infrastructure-tuning pass — likely needs either:
  - A Doctrine mapping cache reset between kernel boots, OR
  - Removing the autoload prepend in tests/bootstrap.php and relying on composer autoload
    pointing only at the worktree's PSR-4 roots.
