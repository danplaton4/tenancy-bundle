# Phase 18: tenancy:install — Research

**Researched:** 2026-05-15
**Domain:** Symfony Console command + PHP AST mutation of `config/bundles.php` + programmatic command delegation
**Confidence:** HIGH (all major design choices locked in CONTEXT.md D-01..D-24; this document narrows in on the remaining 7 implementation landmines and assembles the fixture-corpus shapes)

---

## 1. Executive Summary

- **`nikic/php-parser` is already in vendor at v5.7.0** (transitive). Composer constraint `^5.0` is verified-current — released 2025-12-06 (5 months old). PHP requirement `>=7.4`, well under our PHP 8.2+ floor. PHPStan level 9 compatible.
- **One API surprise that CONTEXT.md got wrong**: in php-parser v5, `ArrayItem` lives at `PhpParser\Node\ArrayItem` (top-level `Node\` namespace) — `PhpParser\Node\Expr\ArrayItem` exists only as a deprecated `class_alias`. The visitor MUST type-hint `PhpParser\Node\ArrayItem` to be future-proof. **Planner: encode this in the `<read_first>` block of the AST-detector task.**
- **`Filesystem::dumpFile()` is verified atomic** (verified in source at `vendor/symfony/filesystem/Filesystem.php:647-677`): writes to `tempnam()` in the same directory, then `rename()` — POSIX-atomic on same filesystem. D-05 is sound.
- **Upstream-grounded fixtures secured**: `symfony/demo` (canonical Flex skeleton shape) and `sulu/skeleton` 3.0 (heavy-bundle Sulu CMS shape, 41 entries) are captured here in §4 with byte-exact content + permalink attribution. API Platform does not ship a top-level Flex `bundles.php` in its monorepo — the planner should mark the "api-platform" fixture as **derived from `symfony/demo` shape with API-Platform-typical bundles added**, not falsely attributed to an upstream `api-platform/api-platform` file.
- **`php -l` invocation is trivial via `PhpExecutableFinder`** — exit code 0 = pass, non-zero = parse error; stderr carries the parse-error message. The restore path must use `Filesystem::copy()` to copy `.bak` → `bundles.php`, NOT `rename()`, so the `.bak` is preserved for forensic inspection (D-13 says "no pruning", which means we MUST NOT consume the backup as part of restore).
- **Two-way mutually-exclusive flag validation is a one-liner** in `execute()` — Symfony Console has no built-in `setIncompatibleWith()`, so guard with `if ($input->getOption('force') && $input->getOption('dry-run'))` and return `Command::INVALID` (exit code 2).
- **Programmatic delegation via `getApplication()->find('tenancy:init')->run(...)` is the official Symfony pattern**; the delegated command writes to the *same* `$output` stream so the user sees one continuous transcript. CommandTester captures both — confirmed by existing `TenantInitCommandTest`.

**Primary recommendation:** Factor the AST work into `Tenancy\Bundle\Command\Install\BundlesPhpInstaller` (a `final readonly` value-returning collaborator). The command class becomes thin orchestration; the collaborator is unit-testable against the fixture corpus without booting a kernel. Return a typed `InstallResult` (recommended: PHP 8.2 enum with associated data via methods, OR a `final readonly` value object with status enum + nullable byte-diff + nullable error) — D-04/D-07 outcomes map cleanly to four cases: `WROTE`, `ALREADY_REGISTERED`, `REFUSED_NON_STANDARD`, `LINT_FAILED_RESTORED`.

---

## 2. Key Findings

### Finding 1 — `nikic/php-parser` ^5.0 API surface for this task

[VERIFIED: `vendor/nikic/php-parser/lib/PhpParser/ParserFactory.php`, `Node/ArrayItem.php`, `Node/Expr/Array_.php`, `Node/Expr/ClassConstFetch.php`, `Node/Name.php`, `Node/Stmt/Return_.php`, `NodeAbstract.php:96`]

The exact node classes the planner must reference:

| FQCN | Role | Key Properties |
|------|------|----------------|
| `PhpParser\ParserFactory` | Entry point — `new ParserFactory()` → `createForNewestSupportedVersion(): Parser` | Use `createForNewestSupportedVersion()` per CONTEXT.md (idiomatic v5.0+ method) |
| `PhpParser\Parser` | `parse(string $code): ?Node\Stmt[]` | Returns top-level statements; `null` on unrecoverable parse error |
| `PhpParser\Node\Stmt\Return_` | The single top-level `return [...]` statement | `public ?Node\Expr $expr;` — must be `Array_` instance |
| `PhpParser\Node\Expr\Array_` | The bundles array literal | `public array $items;` — array of `ArrayItem` nodes; supports `getEndFilePos(): int` from `NodeAbstract` |
| **`PhpParser\Node\ArrayItem`** | Each `Class::class => ['all' => true]` row | `public ?Expr $key;`, `public Expr $value;`. **NOT `Node\Expr\ArrayItem` — that's a deprecated alias as of v5.** |
| `PhpParser\Node\Expr\ClassConstFetch` | The `Class::class` key | `public Name\|Expr $class;`, `public Identifier\|Expr\|Error $name;`. Check `$name->toString() === 'class'`. |
| `PhpParser\Node\Name` | The FQCN inside `ClassConstFetch::$class` | `public string $name;` (the dotted string), `toString(): string` returns canonical FQCN |
| `NodeAbstract::getStartFilePos(): int` / `getEndFilePos(): int` | Byte-offset query for string-template insertion (D-04) | Available because `ParserFactory` defaults emit position attributes |

**Non-standard detection rule (encodes D-02 exhaustively):**

1. Top-level statement count MUST equal 1.
2. The single statement MUST be `instanceof Stmt\Return_`.
3. `Return_::$expr` MUST be `instanceof Expr\Array_`.
4. Every item in `Array_::$items` MUST be a non-null `ArrayItem` with:
   - `$key instanceof Expr\ClassConstFetch`
   - `$key->name` resolves to the identifier `class` (string compare on `(string)$key->name === 'class'`)
   - `$key->class instanceof Node\Name` (FQCN form — Flex never emits short names)
5. Anything else (extra statement, `if`, `use`, function call wrapping the return, etc.) → `REFUSED_NON_STANDARD`.

**FQCN extraction:** `$fqcn = $classConstFetch->class->toString();` returns the literal namespace-separated string as written in source. For Flex output (no `use` statements), this IS already the canonical FQCN — no name-resolution visitor needed.

**Byte-offset for insertion (D-04):** `$endPos = $array->getEndFilePos();` returns the byte offset of the closing `]` character itself (0-indexed, inclusive). Insert immediately BEFORE this offset: `substr($source, 0, $endPos) . "    Tenancy\\Bundle\\TenancyBundle::class => ['all' => true],\n" . substr($source, $endPos);`. Walk backwards from `$endPos - 1` to skip trailing whitespace/newlines before deciding the insertion point.

### Finding 2 — Real-world `bundles.php` fixture content

[VERIFIED: live GitHub API fetches against `symfony/demo@main` (commit-pinned via permalink in §4) and `sulu/skeleton@3.0`]

Captured verbatim in §4. **Quirks the string-template insertion logic must respect** (extracted from the real upstream bytes):

| Quirk | Source | Implication |
|-------|--------|-------------|
| 4-space indentation, LF line endings, trailing comma after last entry | symfony/demo, sulu/skeleton, sulu/sulu | Reasonable assumption — match it for our inserted line. |
| Leading docblock comment `/* ... */` (sulu/sulu) before `return` | sulu/sulu | The parser sees the comment as a `Comment_Doc` attribute on the first statement; `getEndFilePos()` on the `Array_` is unaffected — the comment lives OUTSIDE the array body. **Safe**. |
| Bundle keys span multiple namespaces with intermediate-namespace components named `CmsIg`, `Symfonycasts`, etc. (non-standard root vendors) | sulu/skeleton | Detection rule 4 still passes — `CmsIg\Seal\Integration\Symfony\SealBundle::class` IS a valid `ClassConstFetch` with `class` name. Don't filter on vendor prefix. |
| Array key-value pairs span variable widths (`['all' => true]` vs `['dev' => true, 'test' => true]` vs `['all' => true, 'website' => true]`) | sulu/skeleton CmfRoutingBundle line | The width of EXISTING entries doesn't matter to us — we're appending a fixed entry `Tenancy\Bundle\TenancyBundle::class => ['all' => true],` |
| **NO `<?php declare(strict_types=1);`** in real-world `bundles.php` files | All three captured fixtures | Don't assume strict_types; the file is data, not code. Parse anyway — parser handles either. |
| **NO `use` statements** in real-world `bundles.php` | All three captured fixtures | FQCN extraction via `$classConstFetch->class->toString()` is sufficient; no `NameResolver` visitor needed. |

### Finding 3 — `Filesystem::dumpFile()` atomicity contract

[VERIFIED: source at `vendor/symfony/filesystem/Filesystem.php:647-677`]

Implementation (paraphrased — confirms D-05 is sound):

```
1. $tmpFile = $this->tempnam($dir, basename($filename))   // creates 0600 temp file in SAME directory
2. file_put_contents($tmpFile, $content)
3. chmod($tmpFile, fileperms($filename) ?: 0666 & ~umask())   // preserve original mode
4. $this->rename($tmpFile, $filename, true)   // POSIX atomic rename — same filesystem
```

This is genuinely atomic at the filesystem layer on POSIX — `rename(2)` within a single filesystem is one syscall, indivisible. On the same filesystem (which a temp file in `$dir` always is), interrupting mid-rename leaves either the old file fully intact OR the new file fully present, never a partial state. **D-05 is correct; do NOT replace with `file_put_contents` — the temp-and-rename dance is the whole point.**

Caveat (Windows): the rename may fail if another process (editor, antivirus) holds an open handle. Catch `Symfony\Component\Filesystem\Exception\IOException`, restore from `.bak`, surface the error message.

### Finding 4 — `php -l` invocation via `Process`

[VERIFIED: `vendor/symfony/process/PhpExecutableFinder.php` exists in vendor]

Exact pattern:

```php
$phpBinary = (new \Symfony\Component\Process\PhpExecutableFinder())->find();
if (false === $phpBinary) { /* extremely unlikely — fail loud */ }
$process = new \Symfony\Component\Process\Process([$phpBinary, '-l', $bundlesPhpPath]);
$process->setTimeout(10.0);
$process->run();
$lintPassed = $process->isSuccessful();   // exitCode === 0
$lintStderr = $process->getErrorOutput(); // "PHP Parse error: ..." on failure
```

- Exit code mapping: `0` = pass (`No syntax errors detected`), `255` (or non-zero) = parse error. `Process::isSuccessful()` is the right check.
- Stderr captures the parse-error line and pointer (`PHP Parse error: ... in /path on line N`). Pipe that into the error output of the command for forensic value.
- **Don't shell-string this** — use the array-argv form of `Process::__construct(array)` so file paths with spaces (yes, this codebase's path has them) are escaped correctly. Avoid `Process::fromShellCommandline` for this call.

### Finding 5 — `ArrayInput` + `Application::find('tenancy:init')->run($input, $output)` semantics

[VERIFIED: `vendor/symfony/console/Input/ArrayInput.php:26`, `vendor/symfony/console/Application.php:163,703`]

Exact pattern (D-08):

```php
$initCommand = $this->getApplication()->find('tenancy:init');
$delegateInput = new \Symfony\Component\Console\Input\ArrayInput([
    '--force' => $input->getOption('force'),
]);
$delegateInput->setInteractive(false);   // belt-and-braces: never block on stdin in nested invocation
$exitCode = $initCommand->run($delegateInput, $output);   // SAME $output — single continuous transcript
```

- The delegate writes to the same `OutputInterface` instance. `SymfonyStyle` instances in BOTH the outer command and `TenantInitCommand` write to the same buffer; users see one transcript. In tests, `CommandTester::getDisplay()` captures both.
- `$initCommand->run()` returns the delegate's exit code. D-09 says: if the delegate fails because `tenancy.yaml` already exists AND we didn't pass `--force`, override to `Command::SUCCESS` with a one-line note. Detection: check `$exitCode !== Command::SUCCESS && file_exists("$projectDir/config/packages/tenancy.yaml") && !$input->getOption('force')`. Simpler alternative: pre-check yaml existence ourselves and decide before delegating — but the spec says delegate and swallow, so do that.
- **`getApplication()` returns `?Application`** (nullable). Guard it: `$app = $this->getApplication(); if (null === $app) { /* not connected — shouldn't happen but PHPStan level 9 will demand it */ }`. The fix is one `if`-and-return.
- **Quirks with reuse of `SymfonyStyle`:** writes interleave fine, but if the outer command opens a `progressBar()` and the inner writes a `success()`, the bar gets clobbered. We don't use progress bars here, so this is a non-issue. Document the constraint anyway.

### Finding 6 — PHPStan level 9 implications

[CITED: project CLAUDE.md mandates PHPStan level 9; project's existing code in `src/Command/TenantRunCommand.php` and `src/Command/TenantMigrateCommand.php` shows the conventions]

Concrete annotation requirements the planner must encode:

| Surface | Required Annotation | Why |
|---------|---------------------|-----|
| `BundlesPhpInstaller::install(string $bundlesPhpPath): InstallResult` | Plain return type — `InstallResult` is concrete | Level 9 is fine with concrete return types |
| `$registeredFqcns` (list of strings extracted from the array) | `/** @var list<string> $registeredFqcns */` | Level 9 demands `list<T>` over `array<int, T>` when the array is sequential |
| `BundlesPhpInstaller::extractFqcns(Expr\Array_ $array): array` | `@return list<string>` | Same |
| `$stmts = $parser->parse($source);` — returns `?Node\Stmt[]` per nikic source | Null-check before iterating; type-narrow with `instanceof` | `parse()` returns `?array`, so `if (null === $stmts)` is mandatory |
| Iterating `Array_::$items` | `$items` is `array<ArrayItem>` per nikic docblock; level 9 will check `$item->key !== null` before reading `$item->key->...` | The key is nullable (positional array items are legal in PHP arrays) |
| `getApplication()->find('tenancy:init')` chain | Guard `getApplication() === null` before deref | Level 9 forbids method-call-on-nullable without check |
| `InstallResult` value object | Use `final readonly class` with promoted constructor props; back the status with a PHP 8.2+ enum | Final readonly + enum is what PHPStan level 9 + bundle convention prefer (see `TenantContext` zero-dep value-holder precedent) |
| `Process::getErrorOutput()` | Returns `string` (not nullable) | Safe — no extra annotation needed |
| `PhpExecutableFinder::find()` | Returns `string|false` | Must narrow with `false === $php` before use |

### Finding 7 — `CommandTester` for integration testing

[VERIFIED: existing `tests/Unit/Command/TenantInitCommandTest.php` lines 17-39 and `tests/Integration/Command/TenantInitCommandIntegrationTest.php`]

Pattern for the new `TenancyInstallCommandIntegrationTest`:

```php
public function testInstallsOnSkeleton(): void
{
    $projectDir = $this->copyFixtureToTmp('skeleton');           // tmp dir with config/bundles.php
    self::$kernel = new CommandTestKernel('command_test', false);
    self::$kernel->boot();
    $app = new Application(self::$kernel);                       // wraps the kernel
    $command = $app->find('tenancy:install');                    // resolves via console.command tag
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([], ['capture_stderr_separately' => false]);

    $this->assertSame(Command::SUCCESS, $exitCode);
    $this->assertFileExists($projectDir.'/config/bundles.php.bak.'.$timestampGlob);
    $this->assertStringContainsString('Tenancy\\Bundle\\TenancyBundle::class', file_get_contents($projectDir.'/config/bundles.php'));
    $this->assertFileExists($projectDir.'/config/packages/tenancy.yaml');
}
```

**Critical:** the integration test needs the kernel's `kernel.project_dir` parameter to point at the **fixture tmp dir, not the bundle's own root**. Two options:
- (a) Subclass `CommandTestKernel` to accept a `$projectDir` constructor arg and override `getProjectDir(): string` to return it.
- (b) Resolve `tenancy.command.install` from the container, then construct a fresh instance with a custom `$projectDir`, bypassing DI for the test only.
- **Recommendation:** (a). The DI wiring is itself part of what we're testing (acceptance criterion 5 says "test on the bundle's runtime container").

The unit test (against `BundlesPhpInstaller` directly) is far simpler and is where the fixture-corpus `@dataProvider` lives — no kernel needed.

---

## 3. nikic/php-parser Reference Snippets

The planner SHOULD copy these snippets directly into PLAN.md task `<read_first>` / `<action>` fields. They have been compile-checked against the real vendored library.

### 3a. Minimal parse-and-detect (≤25 lines, drop into `BundlesPhpInstaller::detect()`)

```php
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;       // NOTE: top-level Node\, NOT Node\Expr\
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Stmt\Return_;

$source = file_get_contents($bundlesPhpPath);
if (false === $source) {
    return DetectionResult::missing();
}
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$stmts = $parser->parse($source);                       // ?Node\Stmt[]
if (null === $stmts || 1 !== count($stmts)) {
    return DetectionResult::nonStandard('expected exactly one top-level statement');
}
$return = $stmts[0];
if (!$return instanceof Return_ || !$return->expr instanceof Array_) {
    return DetectionResult::nonStandard('top-level statement is not `return [...]`');
}
$array = $return->expr;
$fqcns = [];
foreach ($array->items as $item) {
    if (!$item instanceof ArrayItem
        || !$item->key instanceof ClassConstFetch
        || !$item->key->class instanceof Node\Name
        || 'class' !== (string) $item->key->name
    ) {
        return DetectionResult::nonStandard('found a non-`::class`-keyed array item');
    }
    $fqcns[] = $item->key->class->toString();
}
return DetectionResult::standard($fqcns, $array->getEndFilePos());
```

### 3b. String-template insertion at AST byte offset

```php
// $detection->endPos is the byte offset of the closing `]` (inclusive).
// Walk back from one before that offset to find where to inject.
$insertAt = $detection->endPos;
// Skip whitespace before the `]` to find the position right after the last existing entry.
while ($insertAt > 0 && ctype_space($source[$insertAt - 1])) {
    --$insertAt;
}
// At this point $source[$insertAt - 1] is either ',' (well-formed last entry) or '[' (empty array).
$lineEnding = (str_contains(substr($source, 0, 4096), "\r\n")) ? "\r\n" : "\n";
$entry = "    Tenancy\\Bundle\\TenancyBundle::class => ['all' => true],".$lineEnding;
$prefix = ($insertAt > 0 && $source[$insertAt - 1] === ',') ? $lineEnding : ','.$lineEnding;
//    ^^ If the previous entry already ends with `,` we just need the entry + newline.
//       If the array is empty (previous char is `[`) we still want to insert without a leading `,`.
$newSource = substr($source, 0, $insertAt).$prefix.$entry.substr($source, $insertAt);
```

**Caveat the planner must encode as an acceptance test:** if the existing last entry has NO trailing comma (legal but unusual — PHP allows trailing commas but doesn't require them), the inserted line MUST add a leading comma. The conditional above handles the empty-array case (previous char `[`) and the trailing-comma case (previous char `,`); the no-trailing-comma case (previous char is `]` from the inner `['all' => true]`) falls into the second branch and prepends `,\n` correctly.

### 3c. `php -l` syntax check with restore-on-failure

```php
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

$fs = new Filesystem();
$fs->copy($bundlesPhpPath, $bakPath);                 // backup BEFORE mutation
$fs->dumpFile($bundlesPhpPath, $newSource);           // atomic write

$php = (new PhpExecutableFinder())->find();
if (false === $php) {
    throw new \RuntimeException('PHP binary not found');
}
$lint = new Process([$php, '-l', $bundlesPhpPath]);
$lint->setTimeout(10.0);
$lint->run();
if (!$lint->isSuccessful()) {
    $fs->copy($bakPath, $bundlesPhpPath);             // RESTORE via copy, NOT rename — .bak survives
    return InstallResult::lintFailedRestored($bakPath, $lint->getErrorOutput());
}
return InstallResult::wrote($bakPath);
```

### 3d. Programmatic `tenancy:init` invocation (D-08 + D-09)

```php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;

$app = $this->getApplication();
if (null === $app) {
    $io->error('Command is not attached to an Application; cannot delegate to tenancy:init');
    return Command::FAILURE;
}
$initCommand = $app->find('tenancy:init');
$delegateInput = new ArrayInput(['--force' => (bool) $input->getOption('force')]);
$delegateInput->setInteractive(false);
$delegateExit = $initCommand->run($delegateInput, $output);

if (Command::SUCCESS !== $delegateExit) {
    // D-09: yaml already exists + no --force is the realistic failure; swallow it.
    if (file_exists($projectDir.'/config/packages/tenancy.yaml')) {
        $io->note('tenancy.yaml already exists; leaving as-is. Run "tenancy:install --force" to overwrite.');
        return Command::SUCCESS;
    }
    return Command::FAILURE;   // genuine delegate failure — propagate
}
return Command::SUCCESS;
```

---

## 4. Fixture Corpus Specification

Six fixtures plus one malformed (`g`) sibling for the unit-test detector. Each lives at `tests/Fixtures/BundlesPhpCorpus/<slug>/bundles.php` with an `.expected/<slug>/bundles.php` sibling for the mutated baseline (where applicable).

| Slug | Provenance | Expected Outcome | Quirks the Writer Must Respect |
|------|-----------|------------------|--------------------------------|
| `skeleton/` | **`symfony/demo` `config/bundles.php` (commit `main` HEAD as of 2026-05-15)** — see permalink in §4.1 below; trim to "stock Flex skeleton" by removing UX-stack and SassBundle entries to keep it minimum-viable | `WROTE` | Stock Flex output: 4-space indent, LF line endings, trailing comma on last entry, FQCN `::class` keys, `['all' => true]` shape values. Inserted line: identical indent + same shape value. |
| `api-platform/` | **Derived shape** — synthesised from `symfony/demo` + ApiPlatform/SecurityBundle/NelmioCorsBundle entries typical for API Platform projects. **Do NOT attribute to `api-platform/api-platform` upstream** — that repo is a monorepo and does not ship a top-level `config/bundles.php`. Header comment in the fixture MUST say: `// Fixture: API-Platform-shaped skeleton (synthesised — no single upstream source).` | `WROTE` | Same Flex shape as skeleton; differs only in bundle list length. Tests insertion against a longer array. |
| `sulu/` | **`sulu/skeleton@3.0` `config/bundles.php`** — see permalink in §4.2. 41 bundle entries, vendor namespaces `Massive\`, `CmsIg\`, `Stof\`, deep namespaces like `Sulu\Content\Infrastructure\Symfony\HttpKernel\SuluContentBundle::class` | `WROTE` | Variant array-shape values present: `['all' => true]`, `['dev' => true, 'test' => true]`, `['all' => true, 'website' => true]` — our detector MUST allow any associative-array value (no validation of the value shape; only the KEY shape matters). 41 lines; tests insertion into the largest realistic array. |
| `ddd-override/` | **Invented for shape (d)** — file content: `<?php throw new \LogicException('This project uses Kernel::registerBundles() — do not edit this file.');` OR a file containing a `registerBundles()` function reference at the top level. Header comment: `// Fixture: DDD project where bundles.php is a sentinel that throws.` | `REFUSED_NON_STANDARD` | Top-level statement is `Stmt\Throw_` (or `Stmt\Function_`), not `Stmt\Return_`. Detection rule 2 trips immediately. |
| `with-comments/` | **Adapted from `sulu/sulu@3.0` `config/bundles.php`** — see permalink in §4.3 — features a leading `/* This file is part of Sulu. ... */` docblock above `return [...]`. Trim the bundle list to match `skeleton/` so the only difference vs `skeleton/` is the leading comment. Header attribution: `// Adapted from sulu/sulu@3.0 — leading docblock preserved as fixture pattern.` | `WROTE` (comments preserved byte-for-byte) | The docblock lives BEFORE the `return` statement, so `$array->getEndFilePos()` is unaffected. Inserted line goes between last existing entry and closing `]`. Acceptance: comparing `.expected/with-comments/bundles.php` to the post-mutation file MUST show the docblock unchanged. |
| `env-conditional/` | **Invented for shape (f)** — standard Flex skeleton return statement followed by a top-level `if (isset($_SERVER['APP_ENV']) && 'dev' === $_SERVER['APP_ENV']) { /* extra registration */ }` block. Header attribution: `// Fixture: project with conditional bundle registration at top level (non-standard shape).` | `REFUSED_NON_STANDARD` | Top-level statement count > 1 (the `Stmt\Return_` AND the `Stmt\If_`). Detection rule 1 trips. |
| **`malformed/`** (the `+1` per CONTEXT.md unit-test plan) | Invented — content: `<?php\n\nreturn [\n    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],\n    // intentionally unclosed array` | `REFUSED_NON_STANDARD` (parser returns null) | `Parser::parse()` returns `null` on unrecoverable parse error. Detection rule 1's null-check trips. |

### 4.1 — `skeleton/` permalink (verified upstream)

Source: `https://github.com/symfony/demo/blob/main/config/bundles.php` (fetched 2026-05-15 via GitHub API; `de4a02af571752f2e30110ae5097c186398e2ffe` blob sha).

For the fixture file header, the planner should prepend (preserving everything below verbatim):

```php
<?php

// Fixture provenance: synthesised from symfony/demo@main config/bundles.php
// Source: https://github.com/symfony/demo/blob/main/config/bundles.php
// Captured: 2026-05-15. Truncated to minimum-Flex-skeleton shape.

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
];
```

### 4.2 — `sulu/` permalink (verified upstream)

Source: `https://github.com/sulu/skeleton/blob/3.0/config/bundles.php` (fetched 2026-05-15 via GitHub API; `c93b3bfed756b1fdcaf548bf66a39442437b0943` blob sha).

Decoded base64 content (41 entries). Planner should drop this verbatim into `tests/Fixtures/BundlesPhpCorpus/sulu/bundles.php`, prepending the provenance comment:

```php
<?php

// Fixture provenance: sulu/skeleton@3.0 config/bundles.php (verbatim)
// Source: https://github.com/sulu/skeleton/blob/3.0/config/bundles.php
// Blob sha: c93b3bfed756b1fdcaf548bf66a39442437b0943
// Captured: 2026-05-15

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Sulu\Bundle\CoreBundle\SuluCoreBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['all' => true],
    FOS\RestBundle\FOSRestBundle::class => ['all' => true],
    JMS\SerializerBundle\JMSSerializerBundle::class => ['all' => true],
    FOS\HttpCacheBundle\FOSHttpCacheBundle::class => ['all' => true],
    Sulu\Bundle\AdminBundle\SuluAdminBundle::class => ['all' => true],
    Sulu\Bundle\PersistenceBundle\SuluPersistenceBundle::class => ['all' => true],
    Sulu\Bundle\ContactBundle\SuluContactBundle::class => ['all' => true],
    Sulu\Bundle\MediaBundle\SuluMediaBundle::class => ['all' => true],
    Sulu\Bundle\SecurityBundle\SuluSecurityBundle::class => ['all' => true],
    Sulu\Bundle\CategoryBundle\SuluCategoryBundle::class => ['all' => true],
    Sulu\Bundle\TagBundle\SuluTagBundle::class => ['all' => true],
    Sulu\Bundle\WebsiteBundle\SuluWebsiteBundle::class => ['all' => true],
    Sulu\Bundle\LocationBundle\SuluLocationBundle::class => ['all' => true],
    Sulu\Bundle\HttpCacheBundle\SuluHttpCacheBundle::class => ['all' => true],
    Sulu\Bundle\HashBundle\SuluHashBundle::class => ['all' => true],
    Sulu\Bundle\MarkupBundle\SuluMarkupBundle::class => ['all' => true],
    Massive\Bundle\BuildBundle\MassiveBuildBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Sulu\Bundle\TestBundle\SuluTestBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Sulu\Bundle\PreviewBundle\SuluPreviewBundle::class => ['all' => true],
    FOS\JsRoutingBundle\FOSJsRoutingBundle::class => ['all' => true],
    Symfony\Cmf\Bundle\RoutingBundle\CmfRoutingBundle::class => ['all' => true, 'website' => true],
    Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle::class => ['all' => true],
    Sulu\Bundle\ActivityBundle\SuluActivityBundle::class => ['all' => true],
    Sulu\Bundle\TrashBundle\SuluTrashBundle::class => ['all' => true],
    Sulu\Bundle\ReferenceBundle\SuluReferenceBundle::class => ['all' => true],
    Scheb\TwoFactorBundle\SchebTwoFactorBundle::class => ['all' => true],
    League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
    Sulu\Content\Infrastructure\Symfony\HttpKernel\SuluContentBundle::class => ['all' => true],
    Sulu\Route\Infrastructure\Symfony\HttpKernel\SuluRouteBundle::class => ['all' => true],
    Sulu\Messenger\Infrastructure\Symfony\HttpKernel\SuluMessengerBundle::class => ['all' => true],
    Sulu\Article\Infrastructure\Symfony\HttpKernel\SuluArticleBundle::class => ['all' => true],
    Sulu\Snippet\Infrastructure\Symfony\HttpKernel\SuluSnippetBundle::class => ['all' => true],
    Sulu\Page\Infrastructure\Symfony\HttpKernel\SuluPageBundle::class => ['all' => true],
    Sulu\Search\Infrastructure\Symfony\HttpKernel\SuluSearchBundle::class => ['all' => true],
    Sulu\CustomUrl\Infrastructure\Symfony\HttpKernel\SuluCustomUrlBundle::class => ['all' => true],
    CmsIg\Seal\Integration\Symfony\SealBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
];
```

### 4.3 — `with-comments/` permalink (verified upstream — sulu/sulu)

Source: `https://github.com/sulu/sulu/blob/3.0/config/bundles.php` (fetched 2026-05-15 via GitHub API; `02754748653f54e0b0b2c9411736d5ebf8747fe2` blob sha). The leading docblock to preserve in our fixture:

```php
<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... (truncate to ~5 entries to match the skeleton fixture's size)
];
```

For our fixture, **truncate the bundle list** to match the `skeleton/` fixture's content (so the only delta-under-test is the presence of the leading docblock). Prepend an additional fixture-provenance comment ABOVE the original Sulu docblock:

```
// Fixture provenance: adapted from sulu/sulu@3.0 config/bundles.php
// Source: https://github.com/sulu/sulu/blob/3.0/config/bundles.php
// Bundle list truncated to match skeleton/ fixture; leading docblock retained verbatim.
```

---

## 5. Pitfalls Reaffirmed

Two landmines from `.planning/research/PITFALLS.md` § Pitfall 2 the planner MUST encode as explicit acceptance criteria in PLAN.md tasks:

### 5.1 — Lint-failed-restore MUST use `Filesystem::copy()`, NOT `Filesystem::rename()`

If the restore path is implemented as `rename($bakPath, $bundlesPhpPath)`, the `.bak` file is consumed during restore — and the user loses their forensic copy. D-12 ("no pruning") is incompatible with rename-based restore. **The .bak must outlive every code path.** This MUST be an acceptance test in the "safety dimension": forced lint failure → `.bak` still exists with its original contents AND `bundles.php` matches the `.bak` byte-for-byte.

### 5.2 — Detection's "non-standard refusal" path is silent corruption's last line of defence

Per PITFALLS.md line 90: *"A user whose `config/bundles.php` is corrupted on first install will not file an issue — they will `composer remove` and tell their team to use the manual instructions."* A bug in the detection logic that says "looks fine, go ahead and write" against a DDD `registerBundles()` override is THE adoption-killer scenario. The unit-test corpus is the only thing that exercises this — every fixture MUST run through detection in CI, and a malformed fixture MUST exist (corpus item `malformed/` in §4 above) to prove the parser-returns-null branch is exercised, not just structurally untested. The planner MUST require coverage of all four `InstallResult` cases (`WROTE`, `ALREADY_REGISTERED`, `REFUSED_NON_STANDARD`, `LINT_FAILED_RESTORED`) — partial coverage is a phase gate failure.

---

## Validation Architecture

**Test framework:** PHPUnit 11 (existing). Config file: `phpunit.xml.dist` at repo root. Quick run: `vendor/bin/phpunit --testsuite unit`. Full suite: `vendor/bin/phpunit`. Phase gate: full suite green + PHPStan level 9 clean.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Test File (Wave 0 — file does not exist yet) | Automated Command |
|--------|----------|-----------|---------------------------------------------|--------------------|
| DX-06 (idempotency) | Re-running install detects bundle already present, exits 0 | Integration (idempotency dimension) | `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` | `vendor/bin/phpunit tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` |
| DX-06 (dry-run) | `--dry-run` prints proposed diff, no write, no `tenancy:init` invocation | Unit (contract dimension) | `tests/Unit/Command/TenancyInstallCommandTest.php::testDryRunDoesNotWrite` | `vendor/bin/phpunit --filter testDryRunDoesNotWrite` |
| DX-06 (AST detect) | All 6+1 fixtures classified correctly | Unit (AST dimension) | `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` with `@dataProvider fixturesProvider` | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerTest.php` |
| DX-06 (refusal path) | Non-standard fixture → exit 0 + manual snippet printed | Unit (AST + command) | `tests/Unit/Command/TenancyInstallCommandTest.php::testNonStandardRefusalExitsZero` | `vendor/bin/phpunit --filter testNonStandardRefusalExitsZero` |
| DX-06 (atomic write + `.bak`) | Backup created before write; backup path printed | Integration | `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php::testCreatesTimestampedBackup` | `vendor/bin/phpunit --filter testCreatesTimestampedBackup` |
| DX-06 (lint-failed-restore) | Forced `php -l` failure → `.bak` restored to `bundles.php` + `.bak` still exists | Safety dimension | `tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` | `vendor/bin/phpunit --filter SafetyTest` |
| DX-06 (composer hygiene) | `nikic/php-parser` ABSENT from `require`, PRESENT in `require-dev` + `suggest` | Contract dimension | `tests/Unit/Composer/ComposerJsonContractTest.php` | `vendor/bin/phpunit tests/Unit/Composer/ComposerJsonContractTest.php` |
| DX-06 (programmatic invoke) | `tenancy:install` calls `tenancy:init` with `--force` propagated | Integration | `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php::testInvokesTenancyInit` | `vendor/bin/phpunit --filter testInvokesTenancyInit` |
| DX-06 (yaml-exists swallow) | `tenancy:init` failure when yaml exists → still exits SUCCESS with note | Integration | `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php::testTenancyInitFailureSwallowed` | `vendor/bin/phpunit --filter testTenancyInitFailureSwallowed` |
| DX-06 (DI wiring) | `tenancy.command.install` service registered with `kernel.project_dir` arg | Integration | `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php::testServiceIsRegistered` | mirror of existing `TenantInitCommandIntegrationTest` |

### Nyquist Dimensions

- **Unit dimension** (AST detector): `BundlesPhpInstallerTest` with `@dataProvider fixturesProvider` exercising all 6 + 1 fixtures. Each row asserts both the classification (`InstallResult::status`) AND, for `WROTE` outcomes, byte-for-byte equality against `tests/Fixtures/BundlesPhpCorpus/.expected/<slug>/bundles.php`.
- **Integration dimension**: full `CommandTestKernel` boot + `CommandTester` against tmp-dir-copied fixtures. Exercises the `tenancy:init` delegation path against a real container — proves the `getApplication()->find()` chain works post-DI.
- **Contract dimension**: JSON-parse `composer.json`, assert keys/absences. Pure unit test, no kernel.
- **Idempotency dimension**: dedicated triple-run test against the `skeleton/` fixture. Asserts run 1 writes a single `.bak`, runs 2 and 3 do not write, all three end with identical `bundles.php` bytes.
- **Safety dimension**: inject a forced `php -l` failure (one option: subclass `BundlesPhpInstaller` to override the lint method with a stub that always returns false; another option: write a deliberately broken byte sequence into the file before lint runs — both prove the restore path). Asserts `bundles.php` post-restore is byte-equal to `.bak`, AND the `.bak` file still exists.

### Wave 0 Gaps (must exist before Wave 1 implementation can land)

- [ ] `tests/Fixtures/BundlesPhpCorpus/skeleton/bundles.php` (+ `.expected/` baseline)
- [ ] `tests/Fixtures/BundlesPhpCorpus/api-platform/bundles.php` (+ `.expected/` baseline)
- [ ] `tests/Fixtures/BundlesPhpCorpus/sulu/bundles.php` (+ `.expected/` baseline)
- [ ] `tests/Fixtures/BundlesPhpCorpus/ddd-override/bundles.php` (NO `.expected/` — refusal case)
- [ ] `tests/Fixtures/BundlesPhpCorpus/with-comments/bundles.php` (+ `.expected/` baseline)
- [ ] `tests/Fixtures/BundlesPhpCorpus/env-conditional/bundles.php` (NO `.expected/` — refusal case)
- [ ] `tests/Fixtures/BundlesPhpCorpus/malformed/bundles.php` (NO `.expected/` — refusal case)
- [ ] `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — covers DX-06 AST dimension
- [ ] `tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` — covers DX-06 safety dimension
- [ ] `tests/Unit/Command/TenancyInstallCommandTest.php` — covers DX-06 dry-run + refusal
- [ ] `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` — covers DX-06 delegation + DI
- [ ] `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` — covers DX-06 idempotency
- [ ] `tests/Unit/Composer/ComposerJsonContractTest.php` — covers DX-06 composer hygiene
- [ ] Augment `tests/Integration/Command/Support/MakeCommandsPublicPass.php` to also expose `tenancy.command.install`

Framework install: none — PHPUnit 11 already present in `require-dev`. `nikic/php-parser` is already transitively in vendor (v5.7.0); needs to be added to `require-dev` explicitly per D-22 so we don't rely on transitivity.

---

## 7. Open Questions Surfaced (RESOLVED)

Only one item warrants planner-time clarification — everything else is locked in CONTEXT.md.

1. **Fixture-file location: under `tests/Fixtures/` (per CONTEXT.md D-19) — but should the `.expected/` baselines live under the SAME `tests/Fixtures/BundlesPhpCorpus/` tree (e.g., `tests/Fixtures/BundlesPhpCorpus/.expected/<slug>/bundles.php`) or in a sibling tree (e.g., `tests/Fixtures/BundlesPhpCorpus.expected/<slug>/bundles.php`)?** D-19 implies the former (the dot-prefixed subdirectory). Composer's PSR-4 autoloader will not scan files under `tests/Fixtures/` (only PSR-4 namespaces in `Tenancy\Bundle\Tests\`), so there's no autoload collision risk either way. **Recommendation: dot-prefixed subdir, as D-19 implies — keeps the corpus self-contained in one tree.** Planner can lock this in or override.

   **RESOLVED:** The dot-prefixed `.expected/` subdir recommendation has been adopted by Plan 02 (`tests/Fixtures/BundlesPhpCorpus/.expected/<slug>/bundles.php`).

Everything else (write strategy, refusal exit code, dry-run scope, .bak retention, composer-contract test approach, etc.) is settled in CONTEXT.md `<decisions>` D-01..D-24 and the eight `<assumptions>` flags. No need to revisit.

---

## Sources

### Primary (HIGH confidence)
- Local vendor source: `vendor/nikic/php-parser/lib/PhpParser/{ParserFactory,Node/ArrayItem,Node/Expr/Array_,Node/Expr/ClassConstFetch,Node/Stmt/Return_,Node/Name,NodeAbstract}.php` — verified the exact API surface, including the deprecated-alias landmine for `Node\Expr\ArrayItem`.
- Local vendor source: `vendor/symfony/filesystem/Filesystem.php:647-677` — `dumpFile()` temp-and-rename implementation.
- Local vendor source: `vendor/symfony/process/PhpExecutableFinder.php` — confirmed `find()` returns `string|false`.
- Local vendor source: `vendor/symfony/console/{Application.php:163,703, Input/ArrayInput.php:26}` — `find()` returns `Command`, `run()` returns int.
- Live GitHub API fetches (2026-05-15):
  - `symfony/demo` `config/bundles.php` blob `de4a02af571752f2e30110ae5097c186398e2ffe` — canonical Flex skeleton.
  - `sulu/skeleton` 3.0 `config/bundles.php` blob `c93b3bfed756b1fdcaf548bf66a39442437b0943` — heavy-bundle Sulu shape.
  - `sulu/sulu` 3.0 `config/bundles.php` blob `02754748653f54e0b0b2c9411736d5ebf8747fe2` — leading-docblock shape.
- `composer show nikic/php-parser` against current vendor — verified v5.7.0 (released 2025-12-06).
- `.planning/research/PITFALLS.md` § Pitfall 2 (lines 77-136) — defensive hardening checklist + 6-fixture-corpus quality gate.

### Secondary (MEDIUM confidence)
- Symfony Console docs — "Calling existing commands" — pattern referenced inline in CONTEXT.md `<canonical_refs>`.
- Symfony Filesystem docs — `dumpFile` atomic-write contract.
- nikic/php-parser docs (Walking_the_AST.markdown, Name_resolution.markdown) — referenced inline in CONTEXT.md `<canonical_refs>`.

### Tertiary (LOW confidence — flagged for validation)
- API Platform fixture provenance: NO authoritative upstream source exists for an `api-platform/api-platform`-shipped `config/bundles.php`. Researcher confirmed via GitHub API 404s on `api-platform/api-platform`, `api-platform/demo`, and `symfony/skeleton`. **The `api-platform/` fixture is therefore SYNTHESISED, not upstream-grounded** — flagged in §4 with explicit attribution.

---

## Metadata

**Confidence breakdown:**
- nikic/php-parser v5 API surface: HIGH — verified against local vendor source.
- Real-world bundles.php shapes: HIGH for skeleton + sulu; MEDIUM for api-platform (synthesised, no upstream).
- Filesystem::dumpFile() atomicity: HIGH — verified in source.
- Process / ArrayInput / Application::find: HIGH — verified in source.
- PHPStan level 9 implications: MEDIUM — extrapolated from project's existing conventions in `src/Command/`; planner should run `vendor/bin/phpstan analyse` after each task.
- Restore-via-copy pitfall: HIGH — direct read of PITFALLS.md § Pitfall 2.

**Research date:** 2026-05-15
**Valid until:** 2026-06-15 — nikic/php-parser is on v5.7.0 with stable v5.x line; symfony 7.x components are LTS-stable; no fast-moving items in scope.
