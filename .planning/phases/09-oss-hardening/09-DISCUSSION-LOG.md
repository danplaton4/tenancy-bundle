# Phase 9: OSS Hardening - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-09
**Phase:** 09-oss-hardening
**Areas discussed:** README tone & structure, Flex recipe defaults, CI matrix scope, Packagist & composer.json

---

## README Tone & Structure

| Option | Description | Selected |
|--------|-------------|----------|
| Developer-pragmatic | Lead with the problem, show code in 30 seconds, let the code sell itself. Think: Flysystem, League packages. | ✓ |
| Bold product pitch | Lead with a strong claim, features list, badges. Think: Laravel Nova, Spatie packages. | |
| Minimal & technical | Install, configure, done. No philosophy, no comparison. Think: doctrine/dbal README. | |

**User's choice:** Developer-pragmatic
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — Symfony vs Laravel comparison | Shows this bundle is the Symfony equivalent of stancl/tenancy. Useful for devs evaluating or migrating. | ✓ |
| No — Symfony-only comparison | Compare only against RamyHakam and manual SQL filter implementations. | |
| Both, separate sections | Symfony ecosystem table + "Coming from Laravel?" callout. | |

**User's choice:** Yes — Symfony vs Laravel comparison
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| Install + annotate + works | 3 steps: composer require, add #[TenantAware], subdomain resolves. ~15 lines. | |
| Full working example | Include config, entity, controller, test trait. ~40 lines. | |
| Install only, link to docs | Just composer require + bundle registration. Link to docs for usage. | ✓ |

**User's choice:** Install only, link to docs
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| Badges (CI, Packagist, PHP version) | Standard OSS badges at the top. | ✓ |
| Architecture overview | Brief section explaining event-driven bootstrapper model. | ✓ |
| Contributing guide | CONTRIBUTING.md or README section with PR guidelines. | ✓ |
| You decide | Claude picks what's appropriate. | ✓ |

**User's choice:** All options selected (multi-select)
**Notes:** None

---

## Flex Recipe Defaults

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal skeleton | Top-level keys commented out with explanations. User uncomments what they need. | ✓ |
| Database-per-tenant preset | Pre-configured for most common case. Opinionated but faster. | |
| Commented reference config | Full configuration reference with every option documented inline. | |

**User's choice:** Minimal skeleton
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| symfony/recipes-contrib | Standard path for community bundles. Requires PR to contrib repo. | |
| In-repo recipe (manifest.json) | Ship recipe inside bundle repo. Less discoverable. | |
| You decide | Claude picks standard approach. | ✓ |

**User's choice:** You decide (Claude's discretion)
**Notes:** None

---

## CI Matrix Scope

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — add a no-Doctrine job | One extra CI job removing doctrine/* before tests. Validates optional dep guards. | ✓ |
| No — full deps only | All CI jobs run with full dev dependencies. | |
| No-Doctrine + no-Messenger | Two extra jobs for maximum coverage. | |

**User's choice:** Yes — add a no-Doctrine job
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — Codecov/Coveralls badge | Generate coverage report and upload. Adds badge to README. | ✓ |
| No — skip coverage for v1 | Keep CI simple, add later. | |
| You decide | Claude picks based on conventions. | |

**User's choice:** Yes — Codecov/Coveralls badge
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| Level 9 (max) | Strictest analysis. Matches project quality bar. | ✓ |
| Level 8 | High but avoids some generics strictness. | |
| Max (follows PHPStan's latest) | Uses --level=max. Future-proof but riskier. | |

**User's choice:** Level 9 (max)
**Notes:** None

---

## Packagist & composer.json

| Option | Description | Selected |
|--------|-------------|----------|
| Remove from suggest | Already a hard require since Phase 07. Redundant suggest entry. | ✓ |
| Move to require-dev + suggest | If tenancy:run considered dev/ops tool. | |
| Keep both | Redundant but harmless. | |

**User's choice:** Remove from suggest (symfony/process)
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| GitHub repo URLs | homepage, support.issues, support.source all GitHub. Standard for OSS. | ✓ |
| Custom domain | Separate docs site as homepage. | |
| You decide | Claude picks standard metadata. | |

**User's choice:** GitHub repo URLs
**Notes:** None

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — 1.0.x-dev alias | Standard Packagist practice. Lets users require ^1.0 before first tag. | ✓ |
| No — tag-only versioning | Only tagged releases get version numbers. | |
| You decide | Claude picks based on conventions. | |

**User's choice:** Yes — 1.0.x-dev alias
**Notes:** None

---

## Claude's Discretion

- Flex recipe repo strategy (in-repo vs contrib PR preparation)
- Additional README sections beyond specified ones
- php-cs-fixer ruleset choice
- Packagist keywords

## Deferred Ideas

None — discussion stayed within phase scope
