# Phase 10: Dependency Compatibility Audit - Context

**Gathered:** 2026-04-10
**Status:** Ready for planning

<domain>
## Phase Boundary

Audit and fix all dependency compatibility issues to ensure the bundle works reliably across PHP 8.2/8.3/8.4 × Symfony 7.x/8.x with all optional dependency combinations. Produce a formal audit report in `.planning/`, fix all issues found (PHP 8.4-only syntax, unguarded imports, deprecated APIs), and expand CI to cover all supported combos including edge cases.

</domain>

<decisions>
## Implementation Decisions

### Audit Scope
- **D-01:** Full audit report + code fixes — not just CI tweaks, but a formal report documenting all dependency interactions, compatibility findings, and fixes applied
- **D-02:** Audit covers ALL deps including v1.1 planned ones (Flysystem, Mailer) — but v1.1 deps are constraints-only (check version ranges compatible with Symfony 7+8, no code changes)
- **D-03:** Audit report lives in `.planning/` only — internal artifact, findings applied to code/CI, not shipped as a public COMPATIBILITY.md
- **D-04:** Automated PHP source scan: grep/PHPStan all `src/` files for syntax requiring PHP >8.2 (property hooks, asymmetric visibility, etc.) — flag and fix any issues
- **D-05:** Deprecation check: run test suite with deprecation notices enabled for Symfony 7.x and 8.x APIs, flag and fix proactively
- **D-06:** Comprehensive `class_exists`/`interface_exists` guard audit — trace every Doctrine and Messenger import path in `src/` and verify each has a guard. Don't trust the existing no-doctrine CI job alone.

### Symfony Version Range
- **D-07:** Symfony 6.4 LTS officially dropped — bundle supports Symfony 7+8 only. Clean up any 6.4 references in REQUIREMENTS.md and docs.
- **D-08:** PHP 8.2+ is the floor (Symfony 7 already requires this). No PHP 8.4-only syntax allowed in bundle source code.

### Claude's Discretion
- **Symfony constraint range**: Claude decides `^7.0||^8.0` (broad) vs `^7.4||^8.0` (narrower, forward-compat) based on actual API breaking changes discovered during audit
- **DoctrineBundle strategy**: Claude decides best approach for supporting DoctrineBundle 2.x and/or 3.x after researching actual compatibility constraints (current composer.json has `^2.13||^3.0`)
- **MigrationsBundle 4.x**: Claude decides whether to test 3.x only or both 3.x and 4.x based on current release status

### CI Matrix Expansion
- **D-09:** Add `--prefer-lowest` job — catches floor constraint violations. Standard OSS practice.
- **D-10:** Add no-messenger CI job — validates `interface_exists` guards for Messenger, mirrors existing no-doctrine pattern
- **D-11:** Symfony 8 + DoctrineBundle 3.x stays as a separate CI job (current approach) — clearer failure isolation

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Current Dependencies
- `composer.json` — Current package constraints, require/require-dev/suggest sections
- `.github/workflows/ci.yml` — Current CI matrix (PHP 8.2/8.3/8.4 × Symfony 7.4/8.0, no-doctrine, PHPStan, cs-fixer, coverage)

### Guard Points
- `src/TenancyBundle.php:112` — `class_exists(DependencyFactory::class)` guard for Doctrine Migrations
- `src/TenancyBundle.php:144` — `interface_exists(MessageBusInterface::class)` guard for Messenger
- `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php:28` — `interface_exists(MessageBusInterface::class)` guard

### Requirements & Project Context
- `.planning/REQUIREMENTS.md` — v1 and v1.1 requirements (need to update Symfony version references)
- `.planning/PROJECT.md` — Constraints section mentions PHP 8.2+, Symfony 6.4/7.x (needs update)
- `.planning/STATE.md` — Decision: "DoctrineBundle 3.x and MigrationsBundle 4.0 require PHP ^8.4"

### Prior Phase Decisions (relevant)
- Phase 06: `symfony/messenger` in require-dev + suggest, NOT require — optional integration
- Phase 07: `symfony/process` promoted to hard require — production code
- Phase 09 D-09: No-Doctrine CI job validates class_exists/interface_exists guards
- Phase 09 D-13: Remove `symfony/process` from suggest (already a hard require)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- CI workflow (`ci.yml`): Already has no-doctrine job pattern — extend for no-messenger
- Existing guard pattern: `class_exists()`/`interface_exists()` established across codebase — audit for completeness

### Established Patterns
- Optional Doctrine: all Doctrine integration guarded by `class_exists`/`interface_exists` checks at compile time (compiler passes) and runtime (bundle extension)
- Optional Messenger: same guard pattern, 2 known entry points
- `suggest` section in composer.json documents optional deps with version constraints

### Integration Points
- `composer.json` — Constraint updates
- `.github/workflows/ci.yml` — Matrix expansion, new jobs
- `src/` — Any PHP 8.4-only syntax fixes
- `phpstan.neon` — May need baseline updates after fixes
- `.planning/REQUIREMENTS.md` — Version reference cleanup (6.4 → 7.x/8.x)
- `.planning/PROJECT.md` — Constraint section update

</code_context>

<specifics>
## Specific Ideas

- User's core intent: "support both Symfony 7 and Symfony 8 with its dependencies — no hardcoded PHP 8.4 syntax, use latest available packages as dependencies"
- The audience is Symfony 7/8 developers — ease their adoption path
- v1.1 deps (Flysystem, Mailer) get constraints-only audit — no code, just version range verification

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 10-dependency-compatibility-audit*
*Context gathered: 2026-04-10*
