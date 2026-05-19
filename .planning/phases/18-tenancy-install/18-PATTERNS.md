# Phase 18: tenancy:install — Pattern Map

**Mapped:** 2026-05-15
**Files analyzed:** 11 file targets (8 NEW, 3 EDIT) + 7 fixture files (corpus)
**Analogs found:** 9 / 11 with strong in-repo analogs; 2 are `NEW-CATEGORY` (no analog — propose canonical Symfony 7.x shape)

---

## File Classification

| Target File | Role | Data Flow | Closest Analog | Match Quality |
|-------------|------|-----------|----------------|---------------|
| `src/Command/TenancyInstallCommand.php` | command (console) | request-response (CLI) | `src/Command/TenantInitCommand.php` | **exact** — sibling command |
| `src/Command/Install/BundlesPhpInstaller.php` | service (collaborator) | transform (file → AST → file) | `src/Resolver/HostResolver.php` | role-match (final + private readonly DI) |
| `src/Command/Install/InstallResult.php` | value object / enum | (n/a — pure data) | none in repo | **NEW-CATEGORY** (no enum precedent) |
| `config/services.php` (edit) | DI config | (n/a — wiring) | `tenancy.command.init` block, lines 117-121 | **exact** — copy verbatim |
| `composer.json` (edit) | manifest | (n/a — wiring) | existing `require-dev` / `suggest` blocks, lines 31-49 | **exact** — copy formatting |
| `tests/Unit/Command/TenancyInstallCommandTest.php` | test (unit) | request-response (CommandTester) | `tests/Unit/Command/TenantInitCommandTest.php` | **exact** |
| `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | test (unit, dataProvider) | transform | (no `@dataProvider` precedent in repo) | **NEW-CATEGORY** (propose canonical PHPUnit 11 shape) |
| `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` | test (integration) | request-response (kernel boot) | `tests/Integration/Command/TenantInitCommandIntegrationTest.php` | **exact** |
| `tests/Unit/Composer/ComposerJsonContractTest.php` | test (contract) | file-I/O (JSON read + assert) | (no composer-contract test in repo) | **NEW-CATEGORY** (propose canonical shape) |
| `tests/Fixtures/BundlesPhpCorpus/{slug}/bundles.php` (×7) | fixture (data) | (n/a — static data) | (no `tests/Fixtures/` directory exists yet) | **NEW-CATEGORY** (propose layout) |
| `CHANGELOG.md` (edit) | docs | (n/a) | `## [Unreleased]` block + `## [0.2.1]` / `## [0.2.0]` blocks | **exact** |
| `tests/Integration/Command/Support/MakeCommandsPublicPass.php` (edit) | test support | (n/a — wiring) | self — current file lines 18-22 | **exact** (add one string to `$ids` array) |

---

## Pattern Assignments

### 1. `src/Command/TenancyInstallCommand.php` (command, request-response)

**Analog:** `src/Command/TenantInitCommand.php` (full file, 204 lines)

**File header + namespace + AsCommand attribute** (`src/Command/TenantInitCommand.php:1-15`):

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenancy:init', description: 'Initialize tenancy configuration')]
class TenantInitCommand extends Command
```

**Constructor signature — projectDir readonly injection** (`src/Command/TenantInitCommand.php:17-21`):

```php
public function __construct(
    protected readonly string $projectDir,
) {
    parent::__construct();
}
```

**configure() shape — option addition** (`src/Command/TenantInitCommand.php:23-26`):

```php
protected function configure(): void
{
    $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing configuration file');
}
```

**execute() body — SymfonyStyle + exit-code idiom** (`src/Command/TenantInitCommand.php:28-44, 64, 80`):

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new SymfonyStyle($input, $output);
    $io->title('Tenancy Bundle — Configuration Initializer');
    // ... guards return Command::FAILURE on bad state
    if (file_exists($targetPath) && !$input->getOption('force')) {
        $io->warning('Configuration file already exists: config/packages/tenancy.yaml');
        return Command::FAILURE;
    }
    // ... happy path
    $io->success('Created config/packages/tenancy.yaml');
    return Command::SUCCESS;
}
```

**Divergences the new file requires:**
- Class is **NOT** `final` (analog mirrors a `class` not `final class`; the codebase convention for commands is non-final, evidenced by `TenantInitCommand`, `TenantRunCommand`, `TenantMigrateCommand`). **Keep `class TenancyInstallCommand extends Command`** — do NOT make it `final` (would diverge from the bundle family).
- Adds a second option `--dry-run` (same `InputOption::VALUE_NONE` shape).
- Mutually-exclusive flag guard at top of `execute()` returning `Command::INVALID` (exit code 2) per RESEARCH.md §1.
- Injects an additional collaborator (`BundlesPhpInstaller`) — constructor becomes `(protected readonly string $projectDir, private readonly BundlesPhpInstaller $installer)`.
- Programmatic delegation block per RESEARCH.md §3d (`$this->getApplication()->find('tenancy:init')->run(...)`).

---

### 2. `src/Command/Install/BundlesPhpInstaller.php` (service, transform)

**Analog:** `src/Resolver/HostResolver.php` (full file, 68 lines) — best in-repo example of `final` + `private readonly` constructor injection for a pure collaborator (NOT a command, NOT a bootstrapper). `src/Bootstrapper/DoctrineBootstrapper.php` and `src/Bootstrapper/DatabaseSwitchBootstrapper.php` follow the same final-readonly DI shape but implement an interface; `BundlesPhpInstaller` will not implement any interface so `HostResolver` is the closer shape.

**Final + private readonly DI style** (`src/Resolver/HostResolver.php:1-18`):

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class HostResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ?string $appDomain = null,
    ) {
    }
```

**Single public entry method with early-return guards** (`src/Resolver/HostResolver.php:20-37`):

```php
public function resolve(Request $request): ?TenantInterface
{
    if (null === $this->appDomain) {
        return null;
    }

    $slug = $this->extractSlug($request->getHost(), $this->appDomain);
    if (null === $slug) {
        return null;
    }

    try {
        return $this->tenantProvider->findBySlug($slug);
    } catch (TenantNotFoundException) {
        return null;
    }
}
```

**Divergences the new file requires:**
- Namespace `Tenancy\Bundle\Command\Install` (new sub-namespace under `Command/`).
- Constructor injects `Symfony\Component\Filesystem\Filesystem` and a `php` binary path (or a `PhpExecutableFinder` callable) for test-mockability (per CONTEXT.md D-17 "Constructor injection preferred").
- Public entry `install(string $bundlesPhpPath, bool $dryRun = false): InstallResult` returns a concrete typed value (not nullable).
- Internal private helpers: `detect()`, `extractFqcns()`, `insertEntry()`, `lintAndRestore()` — each ≤30 LoC.
- Must guard `class_exists(\PhpParser\ParserFactory::class)` at the top of `install()` and return `InstallResult::devDependencyMissing()` if false — mirrors the `class_exists(\Doctrine\Migrations\DependencyFactory::class)` optional-dep guard pattern documented in CONTEXT.md `<code_context>` Established Patterns.

---

### 3. `src/Command/Install/InstallResult.php` (value object / enum)

**Analog:** **NEW-CATEGORY** — `grep -rln "^enum " src/ tests/` returns zero hits; the codebase has no PHP enum precedent. `src/Context/TenantContext.php` is the closest "value holder" but it is mutable state, not a typed result.

**Canonical Symfony 7.x / PHP 8.2+ shape recommended:**

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

enum InstallStatus: string
{
    case WROTE = 'wrote';
    case ALREADY_REGISTERED = 'already_registered';
    case REFUSED_NON_STANDARD = 'refused_non_standard';
    case LINT_FAILED_RESTORED = 'lint_failed_restored';
    case DEV_DEPENDENCY_MISSING = 'dev_dependency_missing';
}

final readonly class InstallResult
{
    public function __construct(
        public InstallStatus $status,
        public ?string $backupPath = null,
        public ?string $diff = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function wrote(string $backupPath): self
    {
        return new self(InstallStatus::WROTE, backupPath: $backupPath);
    }

    public static function alreadyRegistered(): self
    {
        return new self(InstallStatus::ALREADY_REGISTERED);
    }

    public static function refusedNonStandard(string $reason): self
    {
        return new self(InstallStatus::REFUSED_NON_STANDARD, errorMessage: $reason);
    }

    public static function lintFailedRestored(string $backupPath, string $lintError): self
    {
        return new self(InstallStatus::LINT_FAILED_RESTORED, backupPath: $backupPath, errorMessage: $lintError);
    }
}
```

**Rationale for split (enum + final readonly DTO):** the four outcomes carry different ancillary data (`backupPath`, `diff`, `errorMessage`). A pure enum cannot hold per-case payload cleanly; a `final readonly class` with static named constructors is the idiomatic PHP 8.2 shape and aligns with the bundle's `final readonly` DI convention (see `src/DBAL/TenantDriverMiddleware.php`, `src/Cache/TenantAwareCacheAdapter.php`). PHPStan level 9 friendly.

**Divergence note:** if planner prefers a discriminated-union approach via sealed classes, that is also valid — but the bundle has no precedent for sealed hierarchies. **Recommendation: enum + DTO as above** — minimum new concepts.

---

### 4. `config/services.php` — add one `set()` block

**Analog:** `config/services.php` lines 117-121 (the existing `tenancy.command.init` registration — verbatim template):

```php
$services->set('tenancy.command.init', TenantInitCommand::class)
    ->args([
        param('kernel.project_dir'),
    ])
    ->tag('console.command');
```

**Required addition (per CONTEXT.md D-17, locked):**

```php
$services->set('tenancy.command.install', TenancyInstallCommand::class)
    ->args([
        param('kernel.project_dir'),
        service('tenancy.command.install.bundles_php_installer'),
    ])
    ->tag('console.command');

$services->set('tenancy.command.install.bundles_php_installer', BundlesPhpInstaller::class);
```

**Imports to add at top of `config/services.php`** (mirror existing import block at lines 9-23):

```php
use Tenancy\Bundle\Command\TenancyInstallCommand;
use Tenancy\Bundle\Command\Install\BundlesPhpInstaller;
```

**Divergence:** The new service has TWO constructor args (projectDir + installer collaborator) instead of one. Mirrors the `tenancy.command.run` shape at lines 110-115 of the same file, which also takes two `args()`. No alias is created (matches `tenancy.command.init`).

---

### 5. `composer.json` — `require-dev` + `suggest` additions

**Analog:** `composer.json` lines 31-49 — existing dev-deps and suggest blocks.

**`require-dev` block layout** (`composer.json:31-42`):

```json
"require-dev": {
    "doctrine/dbal": "^4.4",
    "doctrine/doctrine-bundle": "^2.13||^3.0",
    "doctrine/migrations": "^3.9",
    "doctrine/orm": "^3.3",
    "friendsofphp/php-cs-fixer": "^3.0",
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^11.0",
    "symfony/framework-bundle": "^7.4||^8.0",
    "symfony/messenger": "^7.4||^8.0",
    "symfony/phpunit-bridge": "^7.4||^8.0"
},
```

**Conventions observed (must match exactly):**
- 4-space indentation
- Keys alphabetically sorted (`"config": { "sort-packages": true }` at line 66 enforces this on `composer update`)
- Trailing comma on the last entry **NOT** present (standard JSON — no trailing commas; verify the new entry is not the last alphabetically, or omit the comma if it becomes the new last)
- Quoted version constraints, no surrounding whitespace inside quotes

**`suggest` block layout** (`composer.json:43-49`):

```json
"suggest": {
    "doctrine/dbal": "Required for database drivers (^4.4)",
    "doctrine/doctrine-bundle": "Required for Doctrine integration (^2.13||^3.0)",
    "doctrine/orm": "Required for Tenant entity (^3.3)",
    "doctrine/migrations": "Required for tenancy:migrate command (^3.9)",
    "symfony/messenger": "Required for tenant context preservation across async message processing (^7.4||^8.0)"
},
```

**Required additions per D-22:**
- `require-dev`: insert `"nikic/php-parser": "^5.0"` between `"friendsofphp/php-cs-fixer"` and `"phpstan/phpstan"` (alphabetical order: `n` falls between `f` and `p`).
- `suggest`: append `"nikic/php-parser": "Required to run bin/console tenancy:install (one-shot installer; not needed at runtime)"`.
- **NO** change to `require`.

---

### 6. `tests/Unit/Command/TenancyInstallCommandTest.php` (test, unit)

**Analog:** `tests/Unit/Command/TenantInitCommandTest.php` (168 lines, exact shape template).

**Class declaration + tmp-dir helper** (`tests/Unit/Command/TenantInitCommandTest.php:5-13, 139-145`):

```php
namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\TenantInitCommand;

final class TenantInitCommandTest extends TestCase
{
    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/tenancy_init_test_'.uniqid('', true);
        mkdir($dir, 0755, true);
        return $dir;
    }
```

**setUp/tearDown idiom (per-test, try/finally)** (`tests/Unit/Command/TenantInitCommandTest.php:14-38`):

```php
public function testCreatesConfigFile(): void
{
    $projectDir = $this->createTempDir();

    try {
        $command = new TenantInitCommand($projectDir);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        // ... file existence + content assertions
        $this->assertStringContainsString('Created config/packages/tenancy.yaml', $tester->getDisplay());
    } finally {
        $this->cleanUp($projectDir);
    }
}
```

**Recursive cleanUp helper** (`tests/Unit/Command/TenantInitCommandTest.php:147-167`):

```php
private function cleanUp(string $dir): void
{
    if (!is_dir($dir)) { return; }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
}
```

**Divergences:**
- New test mocks `BundlesPhpInstaller` (the collaborator) using `$this->createMock(BundlesPhpInstaller::class)` — analog has no mocks. The mocked collaborator returns canned `InstallResult` objects per test case. This is required because the unit test must NOT exercise nikic AST parsing (that lives in `BundlesPhpInstallerTest`).
- Tests exercise the new flags: `testDryRunSkipsTenancyInit`, `testForceAndDryRunMutuallyExclusiveReturnsInvalid`, `testRefusedNonStandardExitsSuccess`.
- `tmp_dir` slug should be `tenancy_install_test_` (mirrors `tenancy_init_test_`).

---

### 7. `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` (test, AST + dataProvider)

**Analog:** **NEW-CATEGORY** — no `@dataProvider`-using test exists in the repo (confirmed via `grep -rln "dataProvider\|DataProvider" tests/` → empty). All existing unit tests use one method per case. The pattern must be introduced here.

**Canonical PHPUnit 11 shape recommended** (skeleton, ≤20 lines):

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Command\Install\BundlesPhpInstaller;
use Tenancy\Bundle\Command\Install\InstallStatus;

final class BundlesPhpInstallerTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/../../../Fixtures/BundlesPhpCorpus';

    #[DataProvider('fixturesProvider')]
    public function testDetectAndInstall(string $slug, InstallStatus $expectedStatus, ?string $expectedFile): void
    {
        $tmpPath = $this->copyFixtureToTmp($slug);
        $installer = new BundlesPhpInstaller(/* deps */);
        $result = $installer->install($tmpPath);
        self::assertSame($expectedStatus, $result->status);
        if ($expectedFile !== null) {
            self::assertStringEqualsFile($expectedFile, file_get_contents($tmpPath));
        }
    }

    public static function fixturesProvider(): iterable
    {
        $base = __DIR__.'/../../../Fixtures/BundlesPhpCorpus';
        yield 'skeleton' => ['skeleton', InstallStatus::WROTE, $base.'/.expected/skeleton/bundles.php'];
        yield 'sulu' => ['sulu', InstallStatus::WROTE, $base.'/.expected/sulu/bundles.php'];
        yield 'ddd-override' => ['ddd-override', InstallStatus::REFUSED_NON_STANDARD, null];
        yield 'env-conditional' => ['env-conditional', InstallStatus::REFUSED_NON_STANDARD, null];
        yield 'malformed' => ['malformed', InstallStatus::REFUSED_NON_STANDARD, null];
        // ... etc
    }
}
```

**Conventions to encode (since no analog enforces them):**
- **Use PHPUnit 11 attributes** (`#[DataProvider('methodName')]`), NOT the legacy `@dataProvider` docblock — the project is on PHPUnit 11 (verified `phpunit/phpunit: ^11.0` in `composer.json:38`), and PHPUnit 11 deprecates the docblock form.
- **Provider returns `iterable` with string keys** (`yield 'slug' => [...]`) so failure output names the row.
- **Fixture path resolution via `__DIR__`-relative constant** at class top — clean, no magic.
- **Byte-for-byte equality:** `self::assertStringEqualsFile($expectedFile, file_get_contents($tmpPath))` — note this is the PHPUnit-recommended assertion (it formats the diff nicely on failure, unlike `assertSame(file_get_contents(...), ...)`).
- **`final class`** — mirrors `final class TenantInitCommandTest` (`tests/Unit/Command/TenantInitCommandTest.php:12`).

**Companion safety test** (`tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php`, separate file per RESEARCH.md "Safety dimension"): exercises forced-lint-failure → `.bak` restore. Uses the same fixture corpus but injects a stubbed lint callable that always returns false. Same shape as above.

---

### 8. `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` (test, integration)

**Analog:** `tests/Integration/Command/TenantInitCommandIntegrationTest.php` (70 lines, full file).

**Class declaration + setUpBeforeClass/tearDownAfterClass kernel lifecycle** (`tests/Integration/Command/TenantInitCommandIntegrationTest.php:20-35`):

```php
final class TenantInitCommandIntegrationTest extends TestCase
{
    private static CommandTestKernel $kernel;
    private static ContainerInterface $container;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new CommandTestKernel('command_test', false);
        self::$kernel->boot();
        self::$container = self::$kernel->getContainer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }
```

**Service-ID resolution (NOT direct instantiation)** (`tests/Integration/Command/TenantInitCommandIntegrationTest.php:37-54`):

```php
public function testInitCommandIsRegistered(): void
{
    self::assertTrue(
        self::$container->has('tenancy.command.init'),
        'Container must have tenancy.command.init service'
    );
}

public function testInitCommandIsInstanceOfCommand(): void
{
    $command = self::$container->get('tenancy.command.init');
    self::assertInstanceOf(TenantInitCommand::class, $command);
}
```

**Reflection-based projectDir verification** (`tests/Integration/Command/TenantInitCommandIntegrationTest.php:56-69`):

```php
public function testInitCommandReceivesProjectDir(): void
{
    $command = self::$container->get('tenancy.command.init');
    $reflection = new \ReflectionProperty(TenantInitCommand::class, 'projectDir');
    $projectDir = $reflection->getValue($command);
    self::assertSame(self::$kernel->getProjectDir(), $projectDir);
}
```

**Divergences:**
- New test must also exercise the **full execution path** against a tmp-dir-copied fixture (per RESEARCH.md §1 Finding 7) — the analog only asserts DI wiring. The new test wraps the kernel-resolved command in `new Application($kernel)` → `find('tenancy:install')` → `new CommandTester(...)`, then runs against tmp fixtures.
- **Critical wiring difference per RESEARCH.md §1 Finding 7:** the kernel's `kernel.project_dir` must point at the **fixture tmp dir**, not the bundle root. Two options:
  - (a) Subclass `CommandTestKernel` (preferred — keeps DI wiring under test).
  - (b) Replace the service definition in a per-test compiler pass.
  - **Recommendation:** (a). Create `tests/Integration/Command/Support/InstallCommandTestKernel.php` that accepts a tmp `$projectDir` constructor arg and overrides `getProjectDir(): string`.
- **MUST update `MakeCommandsPublicPass`** to include `'tenancy.command.install'` in the `$ids` array (`tests/Integration/Command/Support/MakeCommandsPublicPass.php:18-22`) — this is the only edit to that file:

  ```php
  $ids = [
      'tenancy.command.migrate',
      'tenancy.command.run',
      'tenancy.command.init',
      'tenancy.command.install',  // <-- added
  ];
  ```
- Idempotency test (`tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php`) is a separate file per RESEARCH.md "Wave 0 Gaps"; same shape, three-run sequence assertions.

---

### 9. `tests/Unit/Composer/ComposerJsonContractTest.php` (test, contract)

**Analog:** **NEW-CATEGORY** — `ls tests/Unit/Composer` returns no such directory; the repo has no precedent for asserting on its own manifest. Propose canonical PHPUnit-only shape (no infrastructure):

**Recommended skeleton (≤20 lines, fully self-contained):**

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Composer;

use PHPUnit\Framework\TestCase;

final class ComposerJsonContractTest extends TestCase
{
    /** @return array{require: array<string,string>, require-dev: array<string,string>, suggest: array<string,string>} */
    private function manifest(): array
    {
        $path = __DIR__.'/../../../composer.json';
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testNikicPhpParserIsAbsentFromRuntimeRequire(): void
    {
        $manifest = $this->manifest();
        self::assertArrayNotHasKey('nikic/php-parser', $manifest['require'] ?? []);
    }

    public function testNikicPhpParserIsPresentInRequireDev(): void
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('nikic/php-parser', $manifest['require-dev'] ?? []);
        self::assertMatchesRegularExpression('/^\^5\./', $manifest['require-dev']['nikic/php-parser']);
    }

    public function testNikicPhpParserIsSuggestedWithRationale(): void
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('nikic/php-parser', $manifest['suggest'] ?? []);
        self::assertNotEmpty($manifest['suggest']['nikic/php-parser']);
    }
}
```

**Conventions encoded:**
- Path resolution: 3 levels up from `tests/Unit/Composer/` → repo root.
- `JSON_THROW_ON_ERROR` — fail loud, not silent `null`.
- No PHPStan-disable comments needed; the `@return` shape annotation satisfies level 9.
- One assertion per test method (PHPUnit-idiomatic; matches existing test file style — see `TenantInitCommandIntegrationTest` lines 37-69 which uses one assertion per test).
- `final class` — matches bundle convention for test classes.

---

### 10. `tests/Fixtures/BundlesPhpCorpus/{slug}/bundles.php` (×7) — fixture data

**Analog:** **NEW-CATEGORY** — `find . -type d -name Fixtures` returns no results; `tests/` contains only `Integration/`, `Support/`, `Unit/`, and `bootstrap.php`. There is no existing `tests/Fixtures/` directory and therefore no precedent for layout, `.gitignore`, or loading idiom.

**Recommended canonical Symfony 7.x test-fixture layout:**

```
tests/
└── Fixtures/
    └── BundlesPhpCorpus/
        ├── skeleton/
        │   └── bundles.php
        ├── api-platform/
        │   └── bundles.php
        ├── sulu/
        │   └── bundles.php
        ├── ddd-override/
        │   └── bundles.php
        ├── with-comments/
        │   └── bundles.php
        ├── env-conditional/
        │   └── bundles.php
        ├── malformed/
        │   └── bundles.php
        └── .expected/
            ├── skeleton/
            │   └── bundles.php
            ├── api-platform/
            │   └── bundles.php
            ├── sulu/
            │   └── bundles.php
            └── with-comments/
                └── bundles.php
```

**Loading conventions:**
- Path constant at test class top: `private const FIXTURES_DIR = __DIR__.'/../../../Fixtures/BundlesPhpCorpus';` (depth depends on the test's location — for `tests/Unit/Command/Install/` it's three `..`s).
- **NO** `.gitignore` inside `tests/Fixtures/` — these are committed test data; the dot-prefixed `.expected/` subdir is regular content (`.git` ignores nothing prefixed with `.` by default outside top-level `.gitignore` rules; verified by checking the repo's `.gitignore` — fixtures will be committed).
- **PSR-4 autoload exclusion not needed:** `composer.json:55-57` registers `Tenancy\Bundle\Tests\` → `tests/`. `tests/Fixtures/BundlesPhpCorpus/` does not contain PHP classes (no `namespace` line in the fixture files — they are `return [...]` data files). Composer's PSR-4 autoloader walks namespaces, not directories, so it will not load these files. No exclude rule needed.
- Each fixture file MUST begin with a provenance comment per RESEARCH.md §4.1–4.3 (e.g., `// Fixture provenance: symfony/demo@main config/bundles.php`).

**Fixture content sources (verbatim from RESEARCH.md §4):**
- `skeleton/bundles.php` — RESEARCH.md §4.1 verbatim block
- `sulu/bundles.php` — RESEARCH.md §4.2 verbatim block (41 entries)
- `with-comments/bundles.php` — RESEARCH.md §4.3 docblock + truncated bundle list
- `api-platform/bundles.php` — synthesised per RESEARCH.md §4 row (must include `// Fixture: API-Platform-shaped skeleton (synthesised — no single upstream source).` attribution)
- `ddd-override/bundles.php`, `env-conditional/bundles.php`, `malformed/bundles.php` — invented per RESEARCH.md §4

---

### 11. `CHANGELOG.md` — add Phase 18 entry under `[Unreleased]`

**Analog:** `CHANGELOG.md` lines 8-25 — existing `## [Unreleased]` block with Phase 17 OriginHeaderResolver entry; and the v0.2.x entries at lines 27-60 as the Keep-a-Changelog reference shape.

**Existing `[Unreleased]` block** (`CHANGELOG.md:8-25`):

```markdown
## [Unreleased]

### Added

- **`OriginHeaderResolver`** — SPA-friendly tenant resolver that reads the browser-set
  `Origin` HTTP header, matches it against a configurable allow-list under
  `tenancy.origin.allow_list`, and resolves the tenant. Registered in the resolver chain
  at priority 25 (above `HeaderResolver` 20, below `HostResolver` 30). Opt-in via
  `tenancy.resolvers: ['…', 'origin']`. ...
- **`OriginHeaderResolverConfigPass`** — compile-time guard that rejects empty
  allow-lists, unparseable origin URLs, mid-string wildcards, ...
```

**Conventions to mirror exactly:**
- `## [Unreleased]` header (already present — APPEND to existing section, do not create a new one).
- `### Added` subheading (already present — APPEND under it; or add `### Added` if your entry is in a fresh `[Unreleased]` block after a release cut).
- **Bullet format:** `- **\`name\`** — sentence-cased description that wraps at ~80 columns.`
- Inline-code backticks around symbol names, file paths, command names.
- Cite the requirement ID (`DX-06`) at the end of the entry per the v0.2.0 entry style (`Closes #6`, `Closes #7, #8` at lines 49, 53).
- Update the compare link at the bottom (`CHANGELOG.md:166-167`): existing `[Unreleased]: https://github.com/danplaton4/tenancy-bundle/compare/v0.2.0...HEAD` stays as-is until v0.3.0 is cut.

**Required addition (under existing `## [Unreleased]` → `### Added`):**

```markdown
- **`tenancy:install`** — one-command setup that wires the bundle into a fresh
  Symfony app with zero manual file edits. Detects `config/bundles.php` via
  `nikic/php-parser` (declared `require-dev` only), refuses to mutate non-standard
  shapes (DDD `registerBundles()` override, env-conditional load), takes a
  timestamped `.bak` before atomic write, runs `php -l` post-mutation with
  automatic restore on lint failure, and programmatically invokes `tenancy:init`.
  Supports `--dry-run` and `--force` flags. See REQUIREMENTS.md DX-06.
```

---

## Shared Patterns

### Optional-dep guard

**Source:** `src/TenancyBundle.php:186` (per CONTEXT.md `<code_context>`) — pattern: `class_exists(\Doctrine\Migrations\DependencyFactory::class)` short-circuit.

**Apply to:** `BundlesPhpInstaller::install()` — first line guards `class_exists(\PhpParser\ParserFactory::class)`. If false → return `InstallResult` with a `DEV_DEPENDENCY_MISSING` status; the command prints an instructional message naming the package.

```php
if (!class_exists(\PhpParser\ParserFactory::class)) {
    return InstallResult::devDependencyMissing();
}
```

### `declare(strict_types=1);` after `<?php`

**Source:** Every PHP file in `src/` and `tests/` (verified by reading `src/Command/TenantInitCommand.php:1-3`, `src/Resolver/HostResolver.php:1-3`).

**Apply to:** All new PHP files in this phase (commands, collaborator, value object, all test files). NOT applicable to fixture `bundles.php` files (those are data files; real-world `bundles.php` files do NOT declare strict_types per RESEARCH.md §2 Finding 2 quirks table — fixtures must omit this line to remain shape-realistic).

### `final` class convention

**Source:** `src/Resolver/HostResolver.php:12`, `src/DBAL/TenantDriverMiddleware.php`, all 20+ services listed in the codebase grep.

**Apply to:** `BundlesPhpInstaller` (final), `InstallResult` (final readonly), ALL test classes (`final class XxxTest extends TestCase`).

**Do NOT apply to:** `TenancyInstallCommand` — Symfony commands in this bundle are not final (mirrors `TenantInitCommand`, `TenantRunCommand`, `TenantMigrateCommand`). Final commands break Symfony's `getApplication()` test override pattern in some configurations and the bundle has chosen non-final.

### Error / failure exit-code mapping

**Source:** `src/Command/TenantInitCommand.php:40, 55, 61, 80` — `Command::FAILURE` on writes failing, `Command::SUCCESS` on happy path.

**Apply to:** `TenancyInstallCommand::execute()` per CONTEXT.md `<code_context>` Established Patterns last bullet:
- `Command::SUCCESS` — all-good, already-registered, non-standard refused, yaml-already-exists-swallowed (D-09).
- `Command::FAILURE` — lint-failed-restored, dev-dep-missing.
- `Command::INVALID` (exit 2) — `--force` AND `--dry-run` combination (per RESEARCH.md §1 Finding 1, encoded by D-14).

### `SymfonyStyle` UX vocabulary

**Source:** `src/Command/TenantInitCommand.php:30, 32, 37, 44, 64, 67, 144, 156` — `$io->title()`, `$io->warning()`, `$io->note()`, `$io->success()`, `$io->text()`, `$io->section()`, `$io->listing()`.

**Apply to:** `TenancyInstallCommand` — same SymfonyStyle calls. Use `$io->note()` (NOT `$io->warning()`) for the ".gitignore tip" output per CONTEXT.md `<specifics>` ("not as a warning — it's not a problem, just a note").

### Tmp-dir + try/finally cleanup in unit tests

**Source:** `tests/Unit/Command/TenantInitCommandTest.php:14-38, 139-167`.

**Apply to:** All new unit tests that touch the filesystem (`TenancyInstallCommandTest`, `BundlesPhpInstallerTest`, `BundlesPhpInstallerSafetyTest`). Slug naming: `tenancy_install_test_` for the install command tests; `bundles_php_corpus_test_` for the installer collaborator tests.

---

## No Analog Found

Files with no close in-repo match. Planner should use the canonical-shape proposals above (sourced from RESEARCH.md §1 + the official Symfony 7.x / PHP 8.2 idioms) rather than inventing further alternatives:

| File | Role | Why No Analog | Resolution |
|------|------|---------------|------------|
| `src/Command/Install/InstallResult.php` | enum + value object | Bundle has zero PHP enums; closest "value holder" (`TenantContext`) is mutable state, not a typed result | Use `enum InstallStatus: string` + `final readonly class InstallResult` with static named constructors (proposed above) |
| `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | dataProvider unit test | Zero `@dataProvider` / `#[DataProvider]` usage in existing tests | Use PHPUnit 11 `#[DataProvider]` attribute with `yield`-based provider (proposed above) — required by PHPUnit 11 deprecation of docblock form |
| `tests/Unit/Composer/ComposerJsonContractTest.php` | manifest contract | No existing test asserts on `composer.json` | Use plain `json_decode(file_get_contents(__DIR__.'/../../../composer.json'), true, 512, JSON_THROW_ON_ERROR)` then `assertArrayHasKey` / `assertArrayNotHasKey` (proposed above) |
| `tests/Fixtures/BundlesPhpCorpus/**` | test data | No `tests/Fixtures/` directory exists | Layout: per-slug subdirs under `BundlesPhpCorpus/`, dot-prefixed `.expected/` sibling for baselines, `__DIR__`-relative path constants in tests (proposed above) |

---

## Metadata

**Analog search scope:**
- `src/Command/` — direct command analogs
- `src/Resolver/`, `src/Bootstrapper/`, `src/DBAL/`, `src/Cache/` — final-readonly collaborator pattern
- `src/Context/` — value-holder precedent (none viable)
- `tests/Unit/Command/`, `tests/Integration/Command/`, `tests/Integration/Command/Support/` — test shapes
- `tests/Unit/Composer/` — confirmed non-existent
- `tests/Fixtures/` — confirmed non-existent
- `config/services.php` — DI wiring
- `composer.json` — manifest
- `CHANGELOG.md` — release-note style

**Files scanned via Read tool:** `src/Command/TenantInitCommand.php`, `src/Resolver/HostResolver.php`, `config/services.php`, `composer.json`, `tests/Unit/Command/TenantInitCommandTest.php`, `tests/Integration/Command/TenantInitCommandIntegrationTest.php`, `tests/Integration/Command/Support/MakeCommandsPublicPass.php`, `tests/Integration/Command/Support/CommandTestKernel.php`, `.planning/phases/18-tenancy-install/18-CONTEXT.md`, `.planning/phases/18-tenancy-install/18-RESEARCH.md`, `CLAUDE.md`, `CHANGELOG.md` (first 60 lines).

**Pattern extraction date:** 2026-05-15.

## PATTERN MAPPING COMPLETE
