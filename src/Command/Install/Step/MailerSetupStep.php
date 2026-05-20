<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install\Step;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tenancy\Bundle\Command\Install\InstallResult;
use Tenancy\Bundle\Mailer\TenantMailerConfigTrait;

/**
 * tenancy:install --with-mailer sub-step (Plan 20-08).
 *
 * Implements all three D-09 sub-actions:
 *   1. Locates the user's Tenant entity (path passed in by the command — resolved
 *      from the `tenancy.tenant_entity_class` config) and AST-inserts
 *      `use TenantMailerConfigTrait;` as the first statement in the class body.
 *      On non-standard layouts (≠ exactly one class per file, parser failure)
 *      → refuses with a manual snippet (DEC-INST-02 pattern, mirroring
 *      {@see \Tenancy\Bundle\Command\Install\BundlesPhpInstaller}).
 *   2. Scaffolds a Doctrine migration file adding the three nullable columns
 *      (mailer_dsn / mailer_from / mailer_reply_to). When doctrine/migrations
 *      is absent, prints the raw ALTER TABLE SQL via $io and returns ok.
 *   3. Appends a commented-out `mailer:` defaults block to
 *      `config/packages/tenancy.yaml`. The block is visible-but-inert
 *      (lines start with `#`). Idempotent: if the file already contains a
 *      `mailer:` key (commented or active), no-op. If the file is missing,
 *      prints the snippet for manual addition.
 *
 * Threats mitigated (from Plan 20-08 §<threat_model>):
 *   T-20-08-01 (Tampering — broken entity from bad AST insert): atomic write
 *     via Filesystem::dumpFile() + timestamped .bak + post-mutation `php -l`.
 *     On lint failure: restore .bak.
 *   T-20-08-02 (Tampering — non-standard entity corrupted): AST walk detects
 *     non-standard shapes and returns `refusedNonStandard`.
 *   T-20-08-05 (Tampering — duplicate `mailer:` block on re-run): multi-line
 *     regex scan for `^[ \t]*#?[ \t]*mailer[ \t]*:` before append.
 */
final class MailerSetupStep
{
    /**
     * The commented-out defaults block appended to config/packages/tenancy.yaml.
     *
     * Lines begin with `#` so the defaults are visible but inert (D-09 sub-action 3).
     * Defaults: strategy=x_transport, transport_cache_size=32, sanitize_exceptions=true.
     */
    public const TENANCY_YAML_MAILER_BLOCK = <<<'YAML'

# Per-tenant Mailer defaults (BOOT-04 / Phase 20). Uncomment + tune to enable.
# mailer:
#     strategy: x_transport
#     transport_cache_size: 32
#     sanitize_exceptions: true
YAML;

    /** @var \Closure(string, string): array{passed: bool, error: string} */
    private \Closure $lintRunner;

    /**
     * @param (\Closure(string, string): array{passed: bool, error: string})|null $lintRunner optional; tests inject a forced-failure runner
     */
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $lintRunner = null,
    ) {
        $this->lintRunner = $lintRunner ?? self::defaultLintRunner();
    }

    /**
     * Run all three sub-actions in order. The entity-mutation status is the
     * "primary" return; migration scaffold + yaml-append are informational and
     * surface their status via $io (their own InstallResult is discarded so
     * the caller can decide on entity-result alone).
     */
    public function run(
        SymfonyStyle $io,
        string $tenantEntityPath,
        string $migrationsDir,
        string $tenancyYamlPath,
        bool $dryRun = false,
    ): InstallResult {
        if (!class_exists(ParserFactory::class)) {
            $io->note('nikic/php-parser not installed — printing manual instructions:');
            $io->listing([
                'Add `use \\Tenancy\\Bundle\\Mailer\\TenantMailerConfigTrait;` to your Tenant entity class body.',
                'Run `bin/console doctrine:migrations:diff` to generate the column migration.',
                'Append the following to config/packages/tenancy.yaml under your tenancy: root:'.self::TENANCY_YAML_MAILER_BLOCK,
            ]);

            return InstallResult::devDependencyMissing();
        }

        // 1. Entity AST insert — load-bearing return value.
        $entityResult = $this->updateEntity($tenantEntityPath, $dryRun, $io);

        // 2. Migration scaffold — informational; status surfaced via $io.
        $this->scaffoldMigration($migrationsDir, $dryRun, $io);

        // 3. tenancy.yaml mailer-defaults append — informational; surfaced via $io.
        $this->updateTenancyYaml($tenancyYamlPath, $dryRun, $io);

        return $entityResult;
    }

    /**
     * AST-insert `use \Tenancy\Bundle\Mailer\TenantMailerConfigTrait;` as the
     * first statement in the user's Tenant entity class body.
     *
     * Refusal cases (all return REFUSED_NON_STANDARD with a manual snippet):
     *   - Entity file does not exist
     *   - Parser throws (unrecoverable parse error)
     *   - ≠ exactly one Class_ node in the AST (zero, two+, or only interfaces)
     *
     * Already-installed case: any existing TraitUse whose trait FQCN ends in
     * `TenantMailerConfigTrait` short-circuits with ALREADY_REGISTERED.
     */
    private function updateEntity(string $path, bool $dryRun, SymfonyStyle $io): InstallResult
    {
        if (!is_file($path)) {
            $snippet = $this->manualEntitySnippet();
            $io->warning(sprintf('Tenant entity not found at "%s" — printing manual snippet:', $path));
            $io->writeln($snippet);

            return InstallResult::refusedNonStandard('Tenant entity not found at '.$path);
        }

        $code = (string) file_get_contents($path);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (\Throwable $e) {
            return InstallResult::refusedNonStandard('Could not parse '.$path.': '.$e->getMessage());
        }
        if (null === $ast) {
            return InstallResult::refusedNonStandard('Parser returned null for '.$path);
        }

        $finder = new NodeFinder();
        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($ast, Node\Stmt\Class_::class);
        if (1 !== count($classes)) {
            $reason = sprintf('Expected exactly one class in %s; got %d.', $path, count($classes));
            $io->warning($reason);
            $io->writeln($this->manualEntitySnippet());

            return InstallResult::refusedNonStandard($reason);
        }
        $class = $classes[0];

        // Already-installed detection — walk class statements for any TraitUse
        // referencing TenantMailerConfigTrait (short-name match handles both
        // `use TenantMailerConfigTrait` and `use \Tenancy\Bundle\Mailer\TenantMailerConfigTrait`).
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\TraitUse) {
                foreach ($stmt->traits as $traitName) {
                    if (str_ends_with($traitName->toString(), 'TenantMailerConfigTrait')) {
                        $io->note(sprintf('%s already uses TenantMailerConfigTrait — leaving entity unchanged.', $path));

                        return InstallResult::alreadyRegistered();
                    }
                }
            }
        }

        // Insert TraitUse as the FIRST statement in the class body.
        $useStmt = new Node\Stmt\TraitUse([new Node\Name\FullyQualified(TenantMailerConfigTrait::class)]);
        array_unshift($class->stmts, $useStmt);

        $printer = new Standard();
        $newCode = $printer->prettyPrintFile($ast);

        if ($dryRun) {
            $io->section('--with-mailer (dry-run): proposed Tenant entity mutation');
            $io->text(sprintf('Would insert `use \\%s;` as the first statement in the class body of %s', TenantMailerConfigTrait::class, $path));

            return InstallResult::dryRun(sprintf('would insert TenantMailerConfigTrait into %s', $path));
        }

        // Atomic write — copy original to timestamped .bak first.
        $bakPath = $path.'.bak.'.gmdate('Ymd-His');
        $this->filesystem->copy($path, $bakPath);
        $this->filesystem->dumpFile($path, $newCode);

        // Post-mutation lint check.
        $php = (new PhpExecutableFinder())->find();
        if (false === $php) {
            $this->filesystem->copy($bakPath, $path, true);

            return InstallResult::lintFailedRestored($bakPath, 'PHP binary not found by PhpExecutableFinder');
        }

        $lint = ($this->lintRunner)($php, $path);
        if (!$lint['passed']) {
            $this->filesystem->copy($bakPath, $path, true);

            return InstallResult::lintFailedRestored($bakPath, $lint['error']);
        }

        $io->success(sprintf('Inserted `use TenantMailerConfigTrait;` into %s (backup: %s)', $path, $bakPath));

        return InstallResult::wrote($bakPath);
    }

    /**
     * Write a Doctrine migration class that adds the 3 mailer columns to the
     * tenants table. When doctrine/migrations is not installed, prints the raw
     * ALTER TABLE statements via $io (graceful degradation per D-09 / Phase 18
     * "refusal-is-success" pattern).
     *
     * Generated class name: VersionYYYYMMDDHHMMSS_AddTenantMailerColumns
     * Generated namespace : DoctrineMigrations (the Symfony Flex convention).
     */
    private function scaffoldMigration(string $migrationsDir, bool $dryRun, SymfonyStyle $io): InstallResult
    {
        $up = 'ALTER TABLE tenancy_tenants '
            .'ADD COLUMN mailer_dsn VARCHAR(255) DEFAULT NULL, '
            .'ADD COLUMN mailer_from VARCHAR(255) DEFAULT NULL, '
            .'ADD COLUMN mailer_reply_to VARCHAR(255) DEFAULT NULL;';
        $down = 'ALTER TABLE tenancy_tenants '
            .'DROP COLUMN mailer_dsn, DROP COLUMN mailer_from, DROP COLUMN mailer_reply_to;';

        if (!class_exists(\Doctrine\Migrations\AbstractMigration::class)) {
            $io->note('doctrine/migrations not installed — apply this SQL manually:');
            $io->writeln('  '.$up);
            $io->writeln('-- to revert:');
            $io->writeln('  '.$down);

            return InstallResult::refusedNonStandard('printed SQL snippet — doctrine/migrations absent');
        }

        $timestamp = gmdate('YmdHis');
        $className = 'Version'.$timestamp.'_AddTenantMailerColumns';
        $migPath = rtrim($migrationsDir, '/').'/'.$className.'.php';

        $migContent = $this->buildMigrationSource($className, $up, $down);

        if ($dryRun) {
            $io->section('--with-mailer (dry-run): proposed migration file');
            $io->text('Would write: '.$migPath);

            return InstallResult::dryRun('would write '.$migPath);
        }

        if (!is_dir($migrationsDir)) {
            $this->filesystem->mkdir($migrationsDir, 0755);
        }
        $this->filesystem->dumpFile($migPath, $migContent);
        $io->success('Wrote migration: '.$migPath);

        return InstallResult::wrote($migPath);
    }

    /**
     * D-09 sub-action 3 — append commented-out mailer defaults to
     * config/packages/tenancy.yaml.
     *
     * Behaviour:
     *   - File missing  → print snippet to $io, return ok (manual). DO NOT error.
     *   - File present, no `mailer:` key (commented or active) → append block.
     *   - File present, `mailer:` key already present → no-op + $io note (idempotent).
     *
     * Idempotency: regex match `^[ \t]*#?[ \t]*mailer[ \t]*:` catches commented
     * AND uncommented forms. We do NOT parse the YAML (symfony/yaml) because
     * (a) we want to preserve user comments / formatting / order verbatim, and
     * (b) round-tripping through Yaml::parse + Yaml::dump rewrites the file.
     */
    private function updateTenancyYaml(string $path, bool $dryRun, SymfonyStyle $io): InstallResult
    {
        if (!is_file($path)) {
            $io->note('config/packages/tenancy.yaml not found at "'.$path.'". Add the following block manually under your tenancy: root config:');
            $io->writeln(self::TENANCY_YAML_MAILER_BLOCK);

            return InstallResult::refusedNonStandard('printed yaml snippet — tenancy.yaml absent at '.$path);
        }

        $contents = (string) file_get_contents($path);

        // Multi-line regex: catches both commented (`# mailer:`, `#mailer:`) and
        // active (`    mailer:`, `mailer:`) variants at the start of any line.
        if (1 === preg_match('/^[ \t]*#?[ \t]*mailer[ \t]*:/m', $contents)) {
            $io->note('config/packages/tenancy.yaml already contains a `mailer:` section — leaving unchanged.');

            return InstallResult::alreadyRegistered();
        }

        if ($dryRun) {
            $io->section('--with-mailer (dry-run): would append to '.$path);
            $io->writeln(self::TENANCY_YAML_MAILER_BLOCK);

            return InstallResult::dryRun('would append mailer block to '.$path);
        }

        $this->filesystem->appendToFile($path, self::TENANCY_YAML_MAILER_BLOCK."\n");
        $io->success('Appended commented-out `mailer:` defaults to '.$path);

        return InstallResult::wrote($path);
    }

    private function buildMigrationSource(string $className, string $up, string $down): string
    {
        $upEscaped = addslashes($up);
        $downEscaped = addslashes($down);

        return <<<PHP
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\\DBAL\\Schema\\Schema;
use Doctrine\\Migrations\\AbstractMigration;

final class {$className} extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant mailer config columns (BOOT-04 / Phase 20)';
    }

    public function up(Schema \$schema): void
    {
        \$this->addSql('{$upEscaped}');
    }

    public function down(Schema \$schema): void
    {
        \$this->addSql('{$downEscaped}');
    }
}

PHP;
    }

    private function manualEntitySnippet(): string
    {
        return "Add this line inside your Tenant entity class body (first statement):\n"
            .'    use \\Tenancy\\Bundle\\Mailer\\TenantMailerConfigTrait;';
    }

    /**
     * @return \Closure(string, string): array{passed: bool, error: string}
     */
    private static function defaultLintRunner(): \Closure
    {
        return static function (string $php, string $path): array {
            $process = new Process([$php, '-l', $path]);
            $process->setTimeout(10.0);
            $process->run();

            return [
                'passed' => $process->isSuccessful(),
                'error' => $process->getErrorOutput() ?: $process->getOutput(),
            ];
        };
    }
}
