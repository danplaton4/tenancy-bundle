# Phase 18: tenancy:install — Discussion Log

**Mode:** Autonomous (per user instruction — work without stopping for clarifying questions).
**Date:** 2026-05-15
**Operator:** Claude (Opus 4.7, 1M context)

This log records the gray-area resolution flow for Phase 18 context gathering. It is **for human reference only** (audits, retrospectives) and is NOT consumed by downstream agents — they read `18-CONTEXT.md`.

---

## Prior Context Loaded

- `.planning/PROJECT.md` — bundle vision, OSS posture, Flex-recipe non-goal.
- `.planning/REQUIREMENTS.md` — DX-06 acceptance criteria, DEC-INST-01, DEC-INST-02 (both LOCKED).
- `.planning/ROADMAP.md` Phase 18 entry — goal, success criteria 1–5, research-needed note (fixture corpus).
- `.planning/STATE.md` — confirms Phase 17 shipped, Phase 18 next focus.
- `.planning/research/SUMMARY.md` — "second-highest-risk decision is bundles.php mutation"; recommended synthesis: detect via nikic, write via Flex string-template.
- `.planning/research/FEATURES.md` — DX-06 complexity S, idempotency contract, why-not-composer-require rationale.
- `.planning/research/ARCHITECTURE.md` § 1 (DX-06) — flow steps, file-mutation options (PhpToken vs nikic; superseded by ratified DEC-INST-02).
- `.planning/research/PITFALLS.md` § Pitfall 2 — defensive hardening checklist: refuse-by-default, atomic write, `.bak`, `php -l`, ≥6 fixture corpus.
- `.planning/phases/17-origin-header-resolver/17-CONTEXT.md` — autonomous-pattern reference (file shape, assumptions section style).
- `.planning/phases/12-developer-onboarding-tenancy-init-scaffolding-command-that-c/12-01-PLAN.md` — `TenantInitCommand` baseline (projectDir injection, command-test kernel pattern).

## Codebase Scout

- `src/Command/TenantInitCommand.php` — direct sibling shape template.
- `src/Command/{TenantRunCommand,TenantMigrateCommand}.php` — supporting command-idiom references.
- `config/services.php` — `tenancy.command.init` registration to mirror.
- `src/TenancyBundle.php:186` — `class_exists()` optional-dep guard pattern.
- `tests/Integration/Command/Support/{CommandTestKernel,MakeCommandsPublicPass}.php` — integration-test scaffolding to reuse.
- `tests/Integration/Command/TenantInitCommandIntegrationTest.php` — closest test analog.
- `composer.json` — confirmed no AST library currently in any block; `symfony/process` and `symfony/filesystem` (transitive) are available in `require`.

## Gray-Area Identification

Locked-by-upstream (NOT discussed — captured into CONTEXT.md without re-litigation):
- Programmatic invocation of `tenancy:init` with `--force` forwarding (DEC-INST-01).
- `nikic/php-parser` as the detection library, declared in `require-dev` only, lazy-loaded (DEC-INST-02).
- Refuse-to-mutate non-standard `bundles.php` shapes; print manual snippet; exit 0 (DEC-INST-02).
- Atomic write via `Filesystem::dumpFile()`, timestamped `.bak`, `php -l` post-mutation, automatic restore (REQUIREMENTS DX-06 acceptance).
- ≥6 fixture corpus (ROADMAP success criterion 4).
- nikic absent from `require` (acceptance criterion 5).
- Idempotency on re-run; `--dry-run` flag (ROADMAP success criteria 2, 3).

Remaining gray areas (resolved autonomously with reasonable defaults):

| Area | Options considered | Resolution |
|---|---|---|
| Write strategy | (a) PhpParser pretty-printer rewrite of the whole file; (b) string-template insertion at AST-located byte offset; (c) `PhpToken::tokenize()` + string insertion | **(b)** — combines nikic's detection rigor with Flex's proven string-template safety; preserves user formatting/comments byte-for-byte outside the inserted line. Documented as D-04 with assumption flag #1. |
| Non-standard detection algorithm | (a) Heuristic regex for `registerBundles()`; (b) AST top-level shape check (exactly one Return_ of Array_; all keys ClassConstFetch on `::class`); (c) byte-pattern matching | **(b)** — semantically rigorous, no false positives on commented-out / multi-env entries that still parse to standard shape. D-02. |
| `tenancy:init` failure handling | (a) bubble the FAILURE up; (b) override "yaml exists" failure to SUCCESS since bundle IS registered; (c) prompt user interactively | **(b)** — funnel-preserving; the failure case is benign (user already configured the bundle). D-09 with assumption flag #2. |
| `--dry-run` semantics | (a) preview both mutation AND tenancy:init effects; (b) preview only bundles.php mutation, skip delegate | **(b)** — `tenancy:init` has no dry-run; building one is Phase 12 scope creep. D-10 with assumption flag #3. |
| `.bak` retention | (a) keep all; (b) keep last 3; (c) keep last N configurable | **(a)** — pruning logic is non-trivial; cost of leftovers is negligible; print `.gitignore` tip in success output. Defer (b) to backlog. D-12/D-13 with assumption flag #4. |
| `--force` + `--dry-run` combination | (a) silently ignore one; (b) reject at input validation | **(b)** — semantically meaningless combination; explicit rejection is clearer. D-14 with assumption flag #5. |
| Fixture corpus shape | (a) exact 6 hand-picked shapes with `.expected/` baselines; (b) (a) + property-based fuzzing | **(a)** — bounded deliverable; fuzzing referenced in PITFALLS.md is a "nice to have" that doesn't appear in DX-06 acceptance criteria. D-19/D-20 with assumption flag #6. |
| nikic version | (a) `^5.0` latest; (b) `^4.x` for transitive compatibility; (c) `^4.0 \|\| ^5.0` widest | **(a)** — latest stable major; researcher to sanity-check transitive constraints during `/gsd-plan-phase`. D-22 with assumption flag #7. |
| Runtime-isolation test | (a) composer.json contract test only; (b) kernel-boot test asserting PhpParser is absent | **(a)** — kernel-boot test is fragile (dev autoload makes PhpParser available regardless); JSON contract test is the honest acceptance proof. D-23 with assumption flag #8. |
| Collaborator factoring | (a) inline AST work in `TenancyInstallCommand`; (b) factor out `BundlesPhpInstaller` collaborator | **(b)** — AST logic is ≥120 LoC; far cleaner to unit-test in isolation. Documented under "Claude's Discretion". |
| Return-type modeling | (a) tuple (bool, string); (b) typed `InstallResult` enum/object | **(b)** — preferred for clarity and exhaustive case handling. Documented under "Claude's Discretion". |

## Deferred Ideas Captured

- Backup retention pruning (keep-last-3) — future requirement candidate.
- `composer require` orchestration — permanently rejected per FEATURES.md.
- Auto-generating `App\Entity\Tenant` from a stub — out of bundle's domain ownership.
- `tenancy:install --check` mode — useful for CI gates; not in DX-06 acceptance.
- AST-pretty-print full rewrite — alternative write strategy if D-04 needs to flip.
- Public roadmap docs entry / install-page rewrite — Phase 22 DOC-19 owns.
- `scripts/docs-lint.sh` install-path rule — Phase 22 DOC-19 owns.

## Operator Notes

- The autonomous run intentionally mirrors the Phase 17 pattern: every non-locked decision is recorded in `<decisions>` with an explicit `D-NN` ID, and the eight that materially affect the deliverable are flagged in `<assumptions>` for user redirection before `/gsd-plan-phase 18` runs.
- The single most important resolution is **D-04 (string-template write at AST offset, not pretty-printer)**. This is the decision most likely to need a flip if the user has stronger feelings about formatting preservation vs. AST-faithful rewrite. Researcher should not re-litigate this without an explicit user redirect.
- Acceptance criterion 5 ("nikic absent from `require`, verified by a test on the bundle's runtime container") needs gentle reinterpretation — see D-23 / assumption flag #8.

---

*Discussion completed: 2026-05-15*
*Next: `/gsd-plan-phase 18` — researcher reads CONTEXT.md, planner produces PLAN.md(s).*
