# Phase 9: OSS Hardening - Context

**Gathered:** 2026-04-09
**Status:** Ready for planning

<domain>
## Phase Boundary

Make the bundle Packagist-ready: polish composer.json for public release, write a compelling README, prepare a Symfony Flex recipe for zero-config install, and set up GitHub Actions CI matrix enforcing quality across all supported PHP and Symfony versions.

</domain>

<decisions>
## Implementation Decisions

### README Tone & Structure
- **D-01:** Developer-pragmatic tone — lead with the problem ("Symfony has no stancl/tenancy"), show code, let it sell itself. Think League/Flysystem style.
- **D-02:** Quick-start is install-only: `composer require` + bundle registration, then link to docs/documentation for actual usage. Keep README concise.
- **D-03:** Comparison table includes stancl/tenancy (Laravel) — positions this as the Symfony equivalent, useful for devs evaluating frameworks or migrating.
- **D-04:** Include badges (CI status, Packagist latest stable, PHP version, license) at the top.
- **D-05:** Include an architecture overview section — brief explanation of the event-driven bootstrapper model and how tenant context flows through the kernel.
- **D-06:** Include a contributing guide (CONTRIBUTING.md or README section) with PR guidelines, coding standards, test expectations.

### Flex Recipe
- **D-07:** Default `tenancy.yaml` stub is a minimal skeleton — top-level keys commented out with explanations (driver, strict_mode, resolvers, database). User uncomments what they need.
- **D-08:** Flex recipe location: Claude's discretion — standard approach for OSS Symfony bundles (symfony/recipes-contrib PR is the canonical path, but in-repo recipe structure should be prepared regardless).

### CI Matrix
- **D-09:** Add a no-Doctrine CI job that removes doctrine/* before running tests — validates class_exists/interface_exists guards work correctly.
- **D-10:** Include code coverage reporting with Codecov/Coveralls badge in README.
- **D-11:** PHPStan level 9 (fixed, not --level=max) — matches project quality bar.
- **D-12:** Matrix: PHP 8.2/8.3/8.4 x Symfony 6.4/7.4 with PHPStan and php-cs-fixer checks.

### Packagist & composer.json
- **D-13:** Remove `symfony/process` from `suggest` — it's already a hard `require` since Phase 07, so the suggest entry is redundant and misleading.
- **D-14:** Package metadata uses GitHub repo URLs: homepage, support.issues, support.source all pointing to the GitHub repository.
- **D-15:** Add `branch-alias` for dev-master → `1.0.x-dev` so users can `require ^1.0` before the first tag is released.

### Claude's Discretion
- Flex recipe repo strategy (in-repo vs contrib PR) — Claude picks the standard approach
- Additional README sections beyond those specified (changelog link, roadmap teaser, etc.)
- php-cs-fixer ruleset choice (Symfony, PSR-12, or custom)
- Keywords in composer.json for Packagist discoverability

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements
- `.planning/REQUIREMENTS.md` — OSS-01 through OSS-04 define the four deliverables
- `.planning/ROADMAP.md` §Phase 9 — success criteria and plan breakdown (09-01 through 09-04)

### Project Context
- `.planning/PROJECT.md` — Core value proposition, constraints (PHP 8.2+, Symfony 6.4/7.x), key decisions
- `.planning/STATE.md` — All prior phases 1-8 complete, accumulated decisions

### Existing Code
- `composer.json` — Current package definition (needs cleanup per D-13, D-14, D-15)
- `config/services.php` — Bundle's DI service definitions
- `src/TenancyBundle.php` — Bundle class (Flex recipe must auto-register this)

### Prior Phase Decisions (relevant to OSS hardening)
- Phase 06 decision: `symfony/messenger` in require-dev + suggest, NOT require — optional integration pattern to replicate for Doctrine
- Phase 07 decision: `symfony/process` promoted to hard require — tenancy:run is production code
- Pre-phase decision: DoctrineBundle 3.x requires PHP ^8.4 — keep as suggested/optional dep

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `composer.json`: Already has correct `name`, `type: symfony-bundle`, `license: MIT`, PSR-4 autoload, and `extra.symfony.bundles` — needs metadata additions, not a rewrite
- `config/services.php`: Bundle DI config already functional — Flex recipe just needs to reference the bundle class

### Established Patterns
- Optional dependency guards: `class_exists()` / `interface_exists()` used throughout (Messenger, Doctrine) — CI no-Doctrine job validates these
- 40 source files, 68 test files — strong test coverage is a selling point for README

### Integration Points
- `.github/workflows/` — New directory for CI config
- `phpstan.neon` — New file for PHPStan config (none exists)
- `.php-cs-fixer.php` or `.php-cs-fixer.dist.php` — New file for coding standards (none exists)
- `README.md` — New file
- `CONTRIBUTING.md` — New file (or section in README)

</code_context>

<specifics>
## Specific Ideas

- README comparison table should include stancl/tenancy (Laravel) alongside RamyHakam/manual Symfony implementations — cross-framework positioning is intentional
- Architecture overview should explain the event-driven bootstrapper model specifically
- The "zero-leak guarantee" and "strict mode ON by default" messaging should feature prominently

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 09-oss-hardening*
*Context gathered: 2026-04-09*
