# Phase 18: tenancy:install — Context

**Gathered:** 2026-05-15
**Status:** Ready for planning
**Source:** Heavy upstream lock-in (REQUIREMENTS.md DX-06 + DEC-INST-01 + DEC-INST-02 + research/SUMMARY.md + research/FEATURES.md + research/ARCHITECTURE.md + research/PITFALLS.md § 2). Discussion ran autonomously per user instruction; residual gray areas resolved with reasonable defaults — user can redirect any decision before planning.

<domain>
## Phase Boundary

Deliver `tenancy:install` — a one-command setup that turns `composer require danplaton4/tenancy-bundle` into a working install with **zero manual file edits**. The command:

1. Detects `config/bundles.php` and parses it with `nikic/php-parser` (loaded lazily, declared in `require-dev` only).
2. If `Tenancy\Bundle\TenancyBundle::class` is already registered → exits 0 with an informational message (idempotent).
3. If `bundles.php` matches the standard Symfony Flex shape → atomically inserts the registration entry, takes a timestamped `.bak`, runs `php -l` on the result, restores from `.bak` on any syntax failure.
4. If `bundles.php` has a non-standard shape (DDD `registerBundles()` override, env-conditional load, AST contains statements beyond a single `return [...]`) OR is missing entirely → **refuses to mutate**, prints a clean manual snippet, exits **0** (not a failure).
5. Programmatically invokes `tenancy:init` (forwarding `--force` from its own `--force` flag) — single console invocation for the user.
6. Prints copy-paste next-step block.

The acceptance contract is: a fresh user runs `composer require danplaton4/tenancy-bundle && bin/console tenancy:install` and the bundle is registered, configured, and ready. **No manual `config/bundles.php` editing on the supported install path.**

**In scope:**
- New command class `Tenancy\Bundle\Command\TenancyInstallCommand` (sibling of `TenantInitCommand`), `tenancy:install` console name, autowired with `kernel.project_dir`.
- `nikic/php-parser` added to `require-dev` only (root `composer.json`); a runtime-container test asserts the PhpParser classes are NOT autoloadable from the bundle's production wiring (or: composer.json `require` does not contain the package).
- Lazy AST loading — all `PhpParser\*` references inside `TenancyInstallCommand` are guarded by `class_exists(\PhpParser\ParserFactory::class)`; absent → exit with a clear "dev dependency missing; reinstall with `composer require --dev nikic/php-parser`" message (the bundle's runtime never executes this path because end-users always have the dev dep available transitively or install it explicitly).
- `--dry-run` flag — prints the proposed unified diff to stdout, writes nothing.
- `--force` flag — forwarded to the delegated `tenancy:init` invocation; does NOT bypass the non-standard-shape refusal (security-by-default).
- Atomic write via `Symfony\Component\Filesystem\Filesystem::dumpFile()`; timestamped `.bak` sidecar (`config/bundles.php.bak.YYYYMMDD-HHMMSS`); `php -l` syntax check post-mutation; automatic restore on lint failure with a non-zero exit.
- Fixture corpus at `tests/Fixtures/BundlesPhpCorpus/` containing **≥6** distinct `bundles.php` shapes: (a) clean Symfony skeleton, (b) API Platform skeleton, (c) Sulu / CMS, (d) DDD with `registerBundles()` override, (e) Symfony skeleton with line comments + docblock, (f) project with env-conditional registration. Each fixture is exercised by both the dry-run path AND the live-write path.
- Idempotency test — running `tenancy:install` three times in succession on the same fixture produces identical bytes after the first successful run (no duplicate entry, no `.bak` cascade beyond the first).
- Unit tests for the AST detector against all 6 fixtures + (g) malformed/syntax-invalid `bundles.php`.
- Integration test booting `CommandTestKernel` and invoking `tenancy:install` via `CommandTester` against a tmp project root with a fresh fixture; asserts both `bundles.php` mutation and `tenancy.yaml` creation.
- DI registration in `config/services.php` — `tenancy.command.install` tagged `console.command`.
- `composer.json` `suggest` block updated to mention `nikic/php-parser` as required only when running `tenancy:install`.

**Out of scope:**
- Rewriting the existing `TenantInitCommand` — it stays as-is. `tenancy:install` delegates, not replaces.
- Symfony Flex recipe (explicit project non-goal per `PROJECT.md`).
- `composer require` orchestration from inside the command (Composer manipulation in a Process is a maintenance trap; users run `composer require` themselves as documented step 0).
- Auto-generating a `Tenant` entity in the user's `App\Entity\` namespace (user-owned domain code; documented as a manual next step).
- Migrating `bundles.php` between Flex and non-Flex shapes — when non-standard, we always refuse, never "fix" the user's file.
- Mutating `config/services.php` or `config/packages/doctrine.yaml` (out of band; `tenancy:init` and docs cover these).
- Docs page updates beyond a single new "Install" section — Phase 22 (DOC-19) handles the cross-page docs refresh; this phase only adds whatever is required to support the new install path inline.
- `.bak` retention pruning beyond best-effort — see D-13.

**Release target:** v0.3.0 (Phase 18 is the second feature phase of v0.3, after Phase 17 OriginHeaderResolver).

</domain>

<decisions>
## Implementation Decisions

### AST library and detection strategy

- **D-01 (AST library):** `nikic/php-parser` (latest stable, currently `^5.0`). Locked by DEC-INST-02. Declared in **root `composer.json` `require-dev` only**. Lazy-load guard inside the command: `if (!class_exists(\PhpParser\ParserFactory::class))` → exit with an instructional message naming the package + the install command (`composer require --dev nikic/php-parser`).
- **D-02 (Detection algorithm):** Use the PhpParser node visitor pattern to:
  1. Parse `config/bundles.php` into an AST.
  2. Assert the file's top-level body is **exactly one** `Return_` statement returning an `Array_` literal — any other top-level node (an `if`, a `Function_`, a `use`, a `class` declaration, multiple `Return_`s, etc.) means **non-standard → refuse**.
  3. Walk the array's `ArrayItem` nodes; for each item, the **key** must be a `ClassConstFetch` against the `::class` constant (e.g. `Symfony\Bundle\FrameworkBundle\FrameworkBundle::class`). Any string-key entry, non-class-const key, computed key, or unkeyed value → **non-standard → refuse**.
  4. Search the keys for `Tenancy\Bundle\TenancyBundle::class` (FQCN comparison after resolving the parsed class name to its canonical form — handles both fully-qualified and short forms via `use` statements at the file top, though Flex-generated `bundles.php` files never have `use` statements). If found → already-registered → exit 0 informational.
- **D-03 (Non-standard refusal output):** When refusal triggers, print exactly:
  ```
  ⚠ config/bundles.php has a non-standard shape (custom Kernel::registerBundles(), env-conditional load, or unrecognised statements).
  ⚠ Skipping automatic registration. Add this line manually inside your bundles array:

      Tenancy\Bundle\TenancyBundle::class => ['all' => true],

  Then run: bin/console tenancy:init
  ```
  Exit code **0** — refusal is a clean, documented outcome, not a tool failure. The user got a copy-pasteable snippet; the install funnel is preserved.

### File mutation strategy

- **D-04 (Write technique):** **String-template insertion** — NOT nikic's pretty-printer. Rationale: pretty-printing rewrites the entire file in PhpParser's `Standard` format, which mangles user formatting (trailing-comma style, indentation, line endings, comments). Instead:
  1. Locate the byte offset of the closing `]` of the top-level array via the AST node's `getEndFilePos()`.
  2. Walk backwards from that offset to find the last non-whitespace character preceding `]`; insert the new line `\n    Tenancy\Bundle\TenancyBundle::class => ['all' => true],\n` directly above the closing `]`, preserving the file's existing indentation if detectable (default to 4-space if the array has zero existing items).
  3. Detect line endings from the file's first 4KB (`\r\n` vs `\n`) and use the same in the inserted line.
- **D-05 (Atomic write):** `Symfony\Component\Filesystem\Filesystem::dumpFile($path, $newContents)`. This already does temp-file + atomic rename. **Take the `.bak` BEFORE calling `dumpFile()`.**
- **D-06 (Backup naming):** `config/bundles.php.bak.YYYYMMDD-HHMMSS` (UTC, deterministic). Single line in command output reveals the backup path. No retention policy enforced by the command — see D-13.
- **D-07 (Syntax-check + restore):** After `dumpFile()`, shell out to `php -l` (via `Symfony\Component\Process\Process`) against the rewritten file. If the lint fails:
  1. Copy `.bak.{timestamp}` back to `config/bundles.php` (also via `Filesystem::copy()` for atomicity).
  2. Print an error referencing the backup path that was kept (for the user's forensic comfort).
  3. Exit **non-zero** (Command::FAILURE) — this is the "we tried, it failed, your file is restored" path.

### Programmatic delegation to tenancy:init

- **D-08 (Invocation):** After successful `bundles.php` mutation (or detection of an already-registered bundle), call:
  ```php
  $initCommand = $this->getApplication()->find('tenancy:init');
  $exitCode = $initCommand->run(
      new ArrayInput(['--force' => $input->getOption('force')]),
      $output,
  );
  ```
  Run synchronously, same output stream — user sees one continuous transcript.
- **D-09 (tenancy:init failure handling):** If `tenancy:init` returns non-zero (the only realistic case: `tenancy.yaml` exists and `--force` was not passed → `Command::FAILURE`):
  - Print a one-line note: `⚠ tenancy.yaml already exists; leaving as-is. Run "tenancy:install --force" to overwrite.`
  - Override `tenancy:init`'s failure exit and return **Command::SUCCESS** — the bundle IS registered, the config file IS present; install funnel succeeded. Failing because the user already configured the bundle is a regression of UX, not of safety.
- **D-10 (`--dry-run` propagation):** `tenancy:install --dry-run` does NOT invoke `tenancy:init` at all. Dry-run is bundle-registration-only preview. Print: "Dry-run: skipping tenancy:init invocation. Run without --dry-run to scaffold tenancy.yaml."

### Dry-run output

- **D-11 (Format):** Unified-diff-style output (zero-context, just the inserted line) prefixed with the target file path. Example:
  ```
  --- config/bundles.php (current)
  +++ config/bundles.php (proposed)
  @@ insertion before closing ']' @@
  +    Tenancy\Bundle\TenancyBundle::class => ['all' => true],
  ```
  No actual write occurs. No `.bak` is created. The detection branch still runs — so `tenancy:install --dry-run` on a non-standard `bundles.php` prints the manual-snippet message, exit 0. Dry-run on an already-registered file prints "already registered, would skip", exit 0.

### Backup retention

- **D-12 (No active pruning in this phase):** The command creates `.bak` files but does NOT prune older ones. Rationale: pruning logic is a non-trivial sub-feature (need to enumerate `.bak.*` siblings, parse timestamps, decide a retention count, handle clock-skew). The chance a user runs `tenancy:install` more than 2-3 times in their entire project lifetime is extremely low; the cost of leftover `.bak` files is ~5KB each. **`.gitignore` recommendation** — print a one-line tip in the success output: `Tip: add "config/bundles.php.bak.*" to your .gitignore`.
- **D-13 (Deferred to backlog):** Backup retention (keep-last-3, prune older) is captured under "Deferred Ideas" if a user ever asks. Not in v0.3 scope.

### Command surface

- **D-14 (Flags):** Exactly two flags on `tenancy:install`:
  - `--force` — boolean; forwarded to `tenancy:init` to permit overwrite of existing `tenancy.yaml`. Does **not** override the non-standard-shape refusal.
  - `--dry-run` — boolean; prints the proposed diff, skips both mutation and `tenancy:init`. Mutually exclusive with `--force` (passing both → command errors at input validation with a clear message).
- **D-15 (Project dir):** Resolved at construction time via `%kernel.project_dir%` (mirrors `TenantInitCommand`'s pattern). Not a CLI flag — no `--project-dir` override (avoids "I ran this in the wrong dir" footguns; users can `cd` first).
- **D-16 (Verbosity):** Default output uses `SymfonyStyle` (`note`, `success`, `warning`, `text`). `-v`/`-vv` are inherited from Symfony Console; no command-specific verbose mode is added. The backup path is always printed in default verbosity (the user needs it for trust).

### DI registration

- **D-17 (Service definition in `config/services.php`):**
  ```php
  $services->set('tenancy.command.install', TenancyInstallCommand::class)
      ->args([param('kernel.project_dir')])
      ->tag('console.command');
  ```
  No alias to the FQCN; the command class is not a public-typed API surface (mirrors `tenancy.command.init`).
- **D-18 (No compiler pass required):** This command has zero contract-pass needs. The only "guard" — `nikic/php-parser` not being installed in `require` — is a composer-level concern, verified by a dedicated test on `composer.json` rather than a runtime CompilerPass.

### Fixture corpus

- **D-19 (Corpus structure):** Six `bundles.php` fixtures live under `tests/Fixtures/BundlesPhpCorpus/`:
  | Slug | Description | Expected outcome |
  |---|---|---|
  | `skeleton/bundles.php` | Stock Symfony 7.x flex-generated | mutate → success |
  | `api-platform/bundles.php` | API Platform skeleton (extra bundles) | mutate → success |
  | `sulu/bundles.php` | Sulu / CMS skeleton (many bundles, mixed env keys) | mutate → success |
  | `ddd-override/bundles.php` | DDD layout: file is `<?php throw new \LogicException('use Kernel::registerBundles()');` OR a file containing a `registerBundles()` reference | refuse → exit 0 with snippet |
  | `with-comments/bundles.php` | Standard array but with leading docblock + line comments | mutate → success, comments preserved |
  | `env-conditional/bundles.php` | Standard array shape, but with an `if (...) { $bundles[X] = [...]; }` block after the array OR top-level `if/else`-conditional entries | refuse → exit 0 (top-level non-`Return_` statement) |
- **D-20 (Corpus exercise):** Two test classes own the corpus:
  - `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — pure-AST unit tests, one `@dataProvider` per outcome (mutate/refuse/already-registered), each asserting both detection + mutation results byte-for-byte against an expected-output sibling file under `tests/Fixtures/BundlesPhpCorpus/.expected/`.
  - `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` — full kernel boot + `CommandTester`, copies each fixture to a tmp dir, runs the command, asserts exit code + final file content + `.bak` existence + `tenancy.yaml` creation. Reuses `CommandTestKernel` (Phase 12).
- **D-21 (Idempotency test):** Single dedicated test runs the command three times consecutively against the `skeleton/` fixture in a tmp dir. Asserts:
  - Run 1: writes; creates first `.bak`; `bundles.php` now contains the entry.
  - Run 2: detects already-registered; exits 0; no new write; `.bak` count = 1.
  - Run 3: same as run 2.

### Composer dependency hygiene

- **D-22 (`composer.json` additions):**
  - Root `require-dev`: add `"nikic/php-parser": "^5.0"`.
  - Root `suggest`: add `"nikic/php-parser": "Required to run bin/console tenancy:install (one-shot installer; not needed at runtime)"`.
  - **No** change to root `require`.
- **D-23 (Runtime-isolation test):** New test `tests/Unit/Composer/ComposerJsonContractTest.php`:
  - Asserts `nikic/php-parser` is absent from the `require` map.
  - Asserts it IS present in `require-dev`.
  - Asserts it IS present in `suggest` with a non-empty rationale string.

### Documentation (minimal inline only — Phase 22 owns the full refresh)

- **D-24 (Phase 18 doc deliverable):** A short user-facing message printed by the command on success suffices for Phase 18 — the install command IS its own primary documentation. Phase 22 (DOC-19) rewrites `docs/user-guide/installation.md` to lead with `tenancy:install`. This phase touches docs ONLY for the next-step snippet inside the command output:
  ```
  ✓ Registered Tenancy\Bundle\TenancyBundle in config/bundles.php
  ✓ Backup saved at config/bundles.php.bak.20260515-143022
  ✓ Created config/packages/tenancy.yaml

  Next steps:
   1. Open config/packages/tenancy.yaml and uncomment the keys you need.
   2. Implement Tenancy\Bundle\TenantInterface on your Tenant entity.
   3. Run: bin/console tenancy:migrate (or doctrine:schema:update for dev).

  Full reference: https://github.com/danplaton4/tenancy-bundle
  ```

### Claude's Discretion

- Internal collaborator shape — whether `TenancyInstallCommand` factors out a `BundlesPhpInstaller` collaborator (testable in pure isolation) or keeps AST work inline. Recommendation: **factor out** — the AST logic is easily ≥120 LoC and is far cleaner to unit-test without instantiating a Command. Naming the collaborator `BundlesPhpInstaller` (subpackage `Tenancy\Bundle\Command\Install\`) is convention-aligned.
- Exact error-message wording for the lint-failure restore path, the non-standard-shape refusal, and the `nikic/php-parser` absent path. The semantic shape is locked (D-03/D-07); the human-readable strings are flexible.
- Whether to emit a single combined unified diff in `--dry-run` or two separate sections (one for the bundles.php mutation, one stating "tenancy:init would create config/packages/tenancy.yaml"). Either reads cleanly.
- Whether the `BundlesPhpInstaller` collaborator returns a typed result object (`InstallResult` enum-like: `WROTE` / `ALREADY_REGISTERED` / `REFUSED_NON_STANDARD` / `LINT_FAILED_RESTORED`) or a pair of (boolean, message) tuples. Typed result preferred for clarity.
- Whether to inject `Filesystem` and `Process` factory via constructor (testability) or instantiate inline (simpler). Constructor injection preferred; aligns with existing `TenantInitCommand` `projectDir` injection style.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-internal specs (LOCKED requirements)
- `.planning/REQUIREMENTS.md` § `DX-06` (lines 13–19) — Full acceptance criteria for `tenancy:install`. **MUST read.**
- `.planning/REQUIREMENTS.md` § `Architectural Decisions (Ratified)` rows **DEC-INST-01** (programmatic invocation of `tenancy:init`, forwards `--force`) and **DEC-INST-02** (nikic/php-parser detection + refuse-on-nonstandard). Both LOCKED.
- `.planning/ROADMAP.md` § Phase 18 — Phase goal + success criteria 1–5 + research-needed note (fixture corpus assembly).
- `.planning/PROJECT.md` — Bundle vision, conventions, OSS posture (specifically: "Symfony Flex recipe" is an explicit non-goal; `tenancy:install` is the supported onboarding path).

### Project-internal research
- `.planning/research/SUMMARY.md` § Executive Summary — "second-highest-risk decision is `bundles.php` mutation"; synthesizes "detect via nikic, write via Flex string-template" approach.
- `.planning/research/FEATURES.md` § "1. `tenancy:install` (DX-06) — Complexity: **S**" (~line 80) — Behavior spec, idempotency contract, anti-Flex-recipe rationale, why-not-composer-require, why-no-Tenant-entity-generation.
- `.planning/research/ARCHITECTURE.md` § "1. `tenancy:install` (DX-06)" (~line 59) — Step-by-step command flow, file-mutation strategy options (this CONTEXT.md supersedes the `PhpToken::tokenize` recommendation there with `nikic/php-parser` per ratified DEC-INST-02), service definition snippet, net-new files list.
- `.planning/research/PITFALLS.md` § "Pitfall 2: `tenancy:install` corrupts a user's `config/bundles.php`" (~line 77) — **Critical read.** Defensive hardening: refuse-by-default, atomic write, `.bak`, `php -l` post-mutation, automatic restore, **≥6 fixture corpus**.

### Existing bundle source (the code that changes and analogs to mirror)
- `src/Command/TenantInitCommand.php` — **Direct sibling to mirror.** Same `projectDir` injection, same `SymfonyStyle` UX, same exit-code conventions (`Command::SUCCESS` / `Command::FAILURE`). The new `TenancyInstallCommand` must feel like a member of the same family.
- `src/Command/TenantMigrateCommand.php`, `src/Command/TenantRunCommand.php` — Secondary reference for command idioms.
- `config/services.php` — Add `tenancy.command.install` registration alongside `tenancy.command.init` (line ~XX in current file).
- `src/TenancyBundle.php` — No changes expected (no new compiler pass, no new config node).
- `composer.json` — Add `nikic/php-parser` to `require-dev` and `suggest`. Do NOT touch `require`.

### Existing test infrastructure
- `tests/Integration/Command/Support/CommandTestKernel.php` — Reuse for the integration test boot.
- `tests/Integration/Command/Support/MakeCommandsPublicPass.php` — Compiler pass that makes commands publicly fetchable in tests; reuse.
- `tests/Integration/Command/TenantInitCommandIntegrationTest.php` — Closest test analog; copy the shape (tmp `projectDir`, `CommandTester`, assertions on file presence + contents).
- `tests/Unit/Command/TenantInitCommandTest.php` — Unit-test shape for the command itself (without container).

### Prior-phase context
- `.planning/phases/12-developer-onboarding-tenancy-init-scaffolding-command-that-c/12-01-PLAN.md` — Original `tenancy:init` plan; explains the `projectDir` injection rationale, the `Filesystem::dumpFile` vs `file_put_contents` choice (here we DO use `Filesystem::dumpFile()` because we need atomic write), and the `CommandTestKernel` pattern.
- `.planning/phases/17-origin-header-resolver/17-CONTEXT.md` — Reference shape for this CONTEXT.md (mirrors the autonomous-discussion + assumptions-flagged pattern).

### Upstream references
- [Symfony Flex `BundlesConfigurator`](https://github.com/symfony/flex/blob/2.x/src/Configurator/BundlesConfigurator.php) — Reference implementation of the same problem (Flex inserts bundle entries on package install). Our string-template insertion mimics its placement strategy.
- [nikic/php-parser docs — Walking the AST](https://github.com/nikic/PHP-Parser/blob/master/doc/component/Walking_the_AST.markdown) — Visitor pattern reference; we need `NodeVisitor` to find the top-level array and walk its `ArrayItem`s.
- [nikic/php-parser docs — Name resolution](https://github.com/nikic/PHP-Parser/blob/master/doc/component/Name_resolution.markdown) — Confirms FQCN comparison in `ClassConstFetch` nodes; Flex-generated `bundles.php` files have no `use` statements so the parsed name is already fully qualified.
- [Symfony Console — Calling existing commands](https://symfony.com/doc/current/console/calling_commands.html) — Pattern for `$this->getApplication()->find('tenancy:init')->run(...)` invocation.
- [Symfony Filesystem — `dumpFile`](https://symfony.com/doc/current/components/filesystem.html#dumpfile) — Atomic-write contract.

### Documentation (output target)
- Phase 18 ships ONLY the command's stdout next-step block (D-24). The user-guide install-page rewrite belongs to Phase 22 (DOC-19).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`TenantInitCommand`** — direct shape template: `final` not used (mirror), `protected readonly string $projectDir` injected, `SymfonyStyle` for UX, `configure() / execute()` Symfony Console pattern, `Command::SUCCESS` / `Command::FAILURE` return codes. The new install command should be a recognisable sibling.
- **`tenancy.command.init` registration in `config/services.php`** — exact template (`set(class)->args([param('kernel.project_dir')])->tag('console.command')`). Copy verbatim with the new class.
- **`CommandTestKernel` + `MakeCommandsPublicPass`** — already-built integration test scaffolding from Phase 12; the new test file plugs straight in.
- **`Filesystem::dumpFile()`** — already used elsewhere in the project (verified by grep); brings atomic-write for free. Use this, NOT `file_put_contents`.
- **`Process`** — `symfony/process` is in `require`; use it to shell out to `php -l` for the syntax check post-mutation.

### Established Patterns
- **Optional-dep guard pattern** — `class_exists(\Vendor\Class::class)` short-circuit on missing class (e.g., `class_exists(\Doctrine\Migrations\DependencyFactory::class)` in `TenancyBundle.php:186`). Mirror: `class_exists(\PhpParser\ParserFactory::class)` for the lazy-load guard on `nikic/php-parser`.
- **`projectDir` injection style** — protected readonly via constructor, resolved from `%kernel.project_dir%` parameter. `TenantInitCommand` is the precedent; follow exactly.
- **Final readonly DI for collaborators** — when factoring out `BundlesPhpInstaller`, follow the bundle's `final` + `private readonly` convention (mirrors all resolvers and bootstrappers).
- **Doctrine-optional guards** — N/A this phase; the install command has zero Doctrine touch points. Bundle-registration is a static-file operation; `tenancy:init` (delegated) already handles its own Doctrine-detection logic.
- **Symfony Console exit-code shape** — return `Command::SUCCESS` on the "all good" path, on the "already registered" idempotent path, on the "non-standard refused" path. Return `Command::FAILURE` only on the genuine-error paths: lint-failed-restored, dev-dep-missing.

### Integration Points
- `config/services.php` — single new `set()` block for `tenancy.command.install`.
- `composer.json` — `require-dev` + `suggest` additions only (no `require` change).
- `tests/Fixtures/BundlesPhpCorpus/` — new directory with 6 fixture subdirs + `.expected/` siblings for mutation-result baselines.
- `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — new test file targeting the AST collaborator.
- `tests/Unit/Command/TenancyInstallCommandTest.php` — new test file targeting the command itself (mocking the collaborator).
- `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` — new full-boot test.
- `tests/Unit/Composer/ComposerJsonContractTest.php` — new test verifying composer manifest hygiene.

### Files expected to change (informational — planner finalizes)
1. **New:** `src/Command/TenancyInstallCommand.php`
2. **New:** `src/Command/Install/BundlesPhpInstaller.php`
3. **New:** `src/Command/Install/InstallResult.php` (enum or value object)
4. **Edit:** `config/services.php` (one new service block)
5. **Edit:** `composer.json` (`require-dev` + `suggest` additions)
6. **New:** `tests/Fixtures/BundlesPhpCorpus/{skeleton,api-platform,sulu,ddd-override,with-comments,env-conditional}/bundles.php` + `.expected/*/bundles.php` baselines
7. **New:** `tests/Unit/Command/TenancyInstallCommandTest.php`
8. **New:** `tests/Unit/Command/Install/BundlesPhpInstallerTest.php`
9. **New:** `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php`
10. **New:** `tests/Unit/Composer/ComposerJsonContractTest.php`
11. **Edit:** `CHANGELOG.md` (v0.3.0 unreleased section — DX-06 entry)

</code_context>

<specifics>
## Specific Ideas

- The command's success transcript should read like a colleague did the work for you: terse, copy-pasteable, no marketing tone. Mirror Symfony's own `make:user` / `doctrine:database:create` output rhythm.
- For the non-standard refusal path, the wording matters — it's the user's first contact with the bundle's failure mode. Lead with `⚠` not `✗`. Frame as "your project is non-standard, here's the manual snippet" — NOT "the command failed". This is critical: the install funnel doesn't lose the user if it gives them the literal line to paste.
- The fixture corpus should include at least one fixture that exercises a real-world Sulu or API-Platform `bundles.php` taken **verbatim** (with attribution comment at the top of the fixture file pointing at the upstream repo). Researchers tend to invent plausible fixtures that miss the actual quirks of real projects. We need at least two grounded-in-reality fixtures.
- The unified-diff dry-run output should be parseable enough that a reader can copy the `+` line directly into their `bundles.php`. Don't over-decorate with ANSI colors when stdout is not a TTY.
- Backup filename `.bak.YYYYMMDD-HHMMSS` (not `.bak` alone) so multiple runs don't clobber the original-original. If the user runs the command 3 times by mistake, they keep all three snapshots.
- The "Tip: add to .gitignore" hint should appear ONCE per successful write, near the end of the output. Not as a `warning` (it's not a problem), just a `note`.
- Acceptance criterion 5 ("nikic absent from `require`") is testable two ways: (a) a JSON contract test on `composer.json`, (b) a runtime kernel boot that asserts `class_exists(\PhpParser\ParserFactory::class)` returns false. (b) is fragile because dev autoload loads everything in CI. **(a) is the right test.**

</specifics>

<deferred>
## Deferred Ideas

- **Backup retention pruning** (keep-last-3-`.bak`s, delete older). Real user demand unknown; cost of leftover files is trivial; pruning logic is non-trivial. Add to backlog if a real user asks. See D-12/D-13.
- **`composer require` orchestration from inside the command.** Flex exists for this; manipulating composer state from a Process is a maintenance trap. Permanently rejected per FEATURES.md research.
- **Auto-generating `App\Entity\Tenant` from a stub.** User-owned domain code. Would collide with `make:entity` workflows. v0.4+ if demand surfaces.
- **A `tenancy:install --check` mode** that only reports the current state (registered/not, config present/not) without writing. Useful for CI gates but not in DX-06 acceptance. Future requirement candidate.
- **`tenancy:install` recognising and migrating non-standard `bundles.php` shapes.** Permanently rejected — heuristic rewrites of unknown formats are the #1 source of bug reports per PITFALLS.md research.
- **A `--write-recipe` mode that emits a Flex-style recipe for the bundle.** Project explicitly rejects Flex recipe; would only make sense if that policy reverses.
- **Mutating `config/packages/doctrine.yaml`** with the sample DBAL config from `TenantInitCommand`. The sample is currently printed to stdout; an "apply it" option might appear desirable but raises the same blast-radius concerns as `bundles.php` mutation. Defer until v0.4 telemetry shows the manual copy-paste is actually a funnel-killer.
- **Public `ROADMAP.md` page docs entry pointing at `tenancy:install`.** Phase 22 (DOC-19) owns the cross-page docs refresh, including the new install-page rewrite.
- **`scripts/docs-lint.sh` rule rejecting "edit `bundles.php`" references outside UPGRADE / Migration sections.** Acceptance criterion 6 of DOC-19 — owned by Phase 22, not this phase.

</deferred>

<assumptions>
## Assumptions to Flag for User

These are decisions I made autonomously per the no-pause instruction. Flag any to redirect before `/gsd-plan-phase 18` runs:

1. **D-04 (Write strategy = string-template, NOT PhpParser pretty-printer)** — The ratified DEC-INST-02 specifies nikic for **detection**, leaving the write path open. ARCHITECTURE.md proposed `PhpToken::tokenize()` + string insertion; SUMMARY.md proposed nikic-detect + string-template write; PITFALLS.md leaned toward AST-faithful rewrite. I chose **string-template insertion at the AST-identified byte offset** — combines nikic's detection rigor with Flex's proven string-template safety, and avoids the pretty-printer mangling user formatting. If you'd prefer full AST-pretty-print rewrite (loses some formatting but is bulletproof on weird whitespace), flip this.
2. **D-09 (tenancy:init failure overridden to SUCCESS when "yaml already exists")** — When `tenancy:init` returns FAILURE because `tenancy.yaml` already exists and `--force` was not passed, this command swallows that failure and returns SUCCESS (bundle IS registered; install funnel succeeded). Alternative: bubble the FAILURE up. I picked the funnel-preserving path; flip if you want strict pass-through of the delegate's exit code.
3. **D-10 (`--dry-run` skips `tenancy:init` entirely)** — Dry-run is bundle-registration-preview only. Alternative: also preview what `tenancy:init` would do (would require `tenancy:init` itself to support `--dry-run`, which is Phase 12 scope-creep). I picked the conservative-scope path.
4. **D-12/D-13 (no `.bak` retention pruning in this phase)** — Pruning deferred to backlog. If you want last-3-retention in v0.3 specifically, flip this and add to in-scope.
5. **D-14 (`--force` and `--dry-run` mutually exclusive)** — Combining them is semantically meaningless (you can't dry-run a force-overwrite if dry-run already skips the delegate). I chose to reject the combination at input validation. Alternative: silently ignore one. I prefer the error.
6. **D-19/D-20 (fixture corpus shape: 6 distinct fixtures, each with an `.expected/` baseline)** — Acceptance criterion 4 just says "≥6 fixtures". I picked exact 6 distinct shapes and the byte-for-byte `.expected/` baseline pattern. If you'd prefer property-based / generative fuzzing in addition (as PITFALLS.md mentions in passing), flag it — I treated it as out-of-scope for Phase 18 to keep the deliverable bounded.
7. **D-22 (nikic/php-parser version constraint = `^5.0`)** — Latest stable major. If the project has a transitive constraint elsewhere that pins to `^4.x`, flip the version. Researcher should sanity-check during `/gsd-plan-phase`.
8. **D-23 (composer-contract test as the runtime-isolation acceptance proof)** — Acceptance criterion 5 says "verified by a test on the bundle's runtime container". A literal "runtime container" boot test is fragile (dev autoload loads PhpParser into the test runtime regardless). The semantically-correct test is the composer.json contract test. If you want both, flag and I'll add the kernel-boot variant — it'll pass trivially when dev-autoload is in effect, which is the honest "this would not be present in a production install" check most users mentally apply.

If any of these need to flip, say so before planning starts. Otherwise the planner takes them as locked.

</assumptions>

---

*Phase: 18-tenancy-install*
*Context gathered: 2026-05-15 — autonomous discussion per user instruction; gray areas resolved with documented defaults*
