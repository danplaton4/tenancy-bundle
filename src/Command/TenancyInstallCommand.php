<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Command\Install\BundlesPhpInstallerInterface;
use Tenancy\Bundle\Command\Install\InstallStatus;
use Tenancy\Bundle\Command\Install\Step\MailerSetupStep;

/**
 * One-command bundle setup: registers TenancyBundle in `config/bundles.php`
 * (via the {@see BundlesPhpInstaller} AST-driven detector + writer) and then
 * programmatically delegates to `tenancy:init` to scaffold `config/packages/tenancy.yaml`.
 *
 * Flags:
 *   --force    Forwarded to tenancy:init to permit overwrite of an existing tenancy.yaml.
 *              Does NOT bypass the non-standard-shape refusal (security-by-default per D-14).
 *   --dry-run  Print the proposed bundles.php mutation; do not write anything; do not invoke tenancy:init.
 *              Mutually exclusive with --force (exit code 2).
 *
 * Decisions implemented: D-08 (programmatic delegation), D-09 (yaml-exists swallow → SUCCESS),
 * D-10 (dry-run skips tenancy:init), D-14 (mutual-exclusion), D-16 (SymfonyStyle vocabulary),
 * D-17 (DI registration in config/services.php), D-24 (success transcript).
 */
#[AsCommand(
    name: 'tenancy:install',
    description: 'Register TenancyBundle in config/bundles.php and run tenancy:init (one-command setup).'
)]
class TenancyInstallCommand extends Command
{
    public function __construct(
        protected readonly string $projectDir,
        private readonly BundlesPhpInstallerInterface $installer,
        private readonly ?MailerSetupStep $mailerSetupStep = null,
        private readonly ?string $tenantEntityClass = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing tenancy.yaml when delegating to tenancy:init')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the proposed bundles.php mutation; do not write; do not invoke tenancy:init')
            ->addOption(
                'with-mailer',
                null,
                InputOption::VALUE_NONE,
                'Scaffold the Doctrine migration adding the 3 mailer columns, insert `use TenantMailerConfigTrait;` into the Tenant entity, AND append commented-out mailer defaults to config/packages/tenancy.yaml (Phase 20 / BOOT-04, D-09).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Tenancy Bundle — Installer');

        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($force && $dryRun) {
            $io->error('--force and --dry-run are mutually exclusive. --dry-run already implies "no write"; --force has no effect.');

            return Command::INVALID;
        }

        $bundlesPhpPath = $this->projectDir.'/config/bundles.php';
        $result = $this->installer->install($bundlesPhpPath, $dryRun);

        switch ($result->status) {
            case InstallStatus::DEV_DEPENDENCY_MISSING:
                $io->error([
                    'nikic/php-parser is required to run tenancy:install.',
                    'Install it with: composer require --dev nikic/php-parser',
                ]);

                return Command::FAILURE;

            case InstallStatus::REFUSED_NON_STANDARD:
                $io->warning('config/bundles.php has a non-standard shape; skipping automatic registration.');
                if (null !== $result->errorMessage) {
                    $io->text('Detected: '.$result->errorMessage);
                }
                $io->section('Add this line manually inside your bundles array:');
                $io->writeln("    Tenancy\\Bundle\\TenancyBundle::class => ['all' => true],");
                $io->newLine();
                $io->text('Then run: bin/console tenancy:init');

                return Command::SUCCESS;

            case InstallStatus::LINT_FAILED_RESTORED:
                $io->error([
                    'php -l failed on the mutated config/bundles.php.',
                    'Your original file has been restored from: '.($result->backupPath ?? '(no backup path returned)'),
                    'Lint error:',
                    $result->errorMessage ?? '(no error output)',
                ]);

                return Command::FAILURE;

            case InstallStatus::WROTE:
                if (null !== $result->diff) {
                    // Dry-run path
                    $io->section('Proposed mutation (dry-run — nothing written):');
                    $io->writeln($result->diff);
                    $io->note('Dry-run: skipping tenancy:init invocation. Run without --dry-run to scaffold tenancy.yaml.');

                    return Command::SUCCESS;
                }
                $io->success('Registered Tenancy\\Bundle\\TenancyBundle in config/bundles.php');
                if (null !== $result->backupPath) {
                    $io->text('Backup saved at: '.$result->backupPath);
                }
                $io->note('Tip: add "config/bundles.php.bak.*" to your .gitignore');

                return $this->delegateToTenancyInit($input, $output, $io);

            case InstallStatus::ALREADY_REGISTERED:
                $io->note('Tenancy\\Bundle\\TenancyBundle is already registered in config/bundles.php — no changes made.');

                return $this->delegateToTenancyInit($input, $output, $io);

            default:
                throw new \LogicException('Unhandled InstallStatus: '.$result->status->value);
        }
    }

    private function delegateToTenancyInit(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $app = $this->getApplication();
        if (null === $app) {
            $io->error('TenancyInstallCommand is not attached to an Application; cannot delegate to tenancy:init.');

            return Command::FAILURE;
        }

        $force = (bool) $input->getOption('force');
        $delegateInput = new ArrayInput(['--force' => $force]);
        $delegateInput->setInteractive(false);

        $delegateExit = $app->find('tenancy:init')->run($delegateInput, $output);

        if (Command::SUCCESS !== $delegateExit) {
            // D-09: tenancy:init typically fails when tenancy.yaml exists and --force was not passed.
            // The bundle IS registered; the config file IS present (just not overwritten).
            // Treat the install funnel as successful and surface a one-line note.
            if (file_exists($this->projectDir.'/config/packages/tenancy.yaml') && !$force) {
                $io->note('tenancy.yaml already exists; leaving as-is. Run "tenancy:install --force" to overwrite.');
                $this->runMailerSetupIfRequested($input, $io);
                $this->printNextSteps($io);

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        }

        $this->runMailerSetupIfRequested($input, $io);
        $this->printNextSteps($io);

        return Command::SUCCESS;
    }

    /**
     * Runs the optional --with-mailer step (Plan 20-08 / D-09).
     *
     * Skips silently when the flag is not set. When the flag is set but the
     * MailerSetupStep service is null (symfony/mailer not installed), emits a
     * warning and returns — does NOT fail the install (graceful degradation
     * per the Phase 18 "refusal-is-success" pattern).
     */
    private function runMailerSetupIfRequested(InputInterface $input, SymfonyStyle $io): void
    {
        if (!(bool) $input->getOption('with-mailer')) {
            return;
        }

        if (null === $this->mailerSetupStep) {
            $io->warning('--with-mailer requested but MailerSetupStep is not wired (symfony/mailer not installed). Skipping.');

            return;
        }

        $io->section('Mailer setup (--with-mailer)');

        $entityPath = $this->resolveTenantEntityPath();
        $migrationsDir = $this->resolveMigrationsDir();
        $tenancyYamlPath = $this->resolveTenancyYamlPath();
        $dryRun = (bool) $input->getOption('dry-run');

        $this->mailerSetupStep->run(
            $io,
            $entityPath,
            $migrationsDir,
            $tenancyYamlPath,
            $dryRun,
        );
    }

    /**
     * Resolves the absolute filesystem path of the user's Tenant entity.
     *
     * Strategy:
     *   1. If `tenancy.tenant_entity_class` resolves to a loadable class whose
     *      file lives inside `$this->projectDir`, use that.
     *   2. Otherwise (bundle's own default entity, unloadable class, or file
     *      outside the projectDir) fall back to the Symfony / Doctrine
     *      convention path `<projectDir>/src/Entity/Tenant.php`.
     *
     * The "inside projectDir" guard prevents `--with-mailer` from accidentally
     * mutating the bundle's own `src/Entity/Tenant.php` when the user has not
     * overridden the config key (the default class resolves to the bundle's
     * own file via the composer autoloader).
     */
    private function resolveTenantEntityPath(): string
    {
        $fallback = $this->projectDir.'/src/Entity/Tenant.php';

        if (null === $this->tenantEntityClass || !class_exists($this->tenantEntityClass)) {
            return $fallback;
        }

        try {
            $fileName = (new \ReflectionClass($this->tenantEntityClass))->getFileName();
        } catch (\ReflectionException) {
            return $fallback;
        }
        if (false === $fileName) {
            return $fallback;
        }

        // Only honour the reflected path when it lives inside the user's project root.
        $projectDirReal = realpath($this->projectDir);
        $fileReal = realpath($fileName);
        if (false === $projectDirReal || false === $fileReal) {
            return $fallback;
        }
        if (!str_starts_with($fileReal, rtrim($projectDirReal, '/').'/')) {
            return $fallback;
        }

        return $fileReal;
    }

    /**
     * Resolves the Doctrine migrations directory.
     *
     * Uses the Symfony convention `<projectDir>/migrations`. Users with a
     * custom migrations path documented in doctrine_migrations.yaml are not
     * yet supported — they receive the migration in the conventional dir.
     */
    private function resolveMigrationsDir(): string
    {
        return $this->projectDir.'/migrations';
    }

    /**
     * Resolves the absolute filesystem path to config/packages/tenancy.yaml.
     */
    private function resolveTenancyYamlPath(): string
    {
        return $this->projectDir.'/config/packages/tenancy.yaml';
    }

    private function printNextSteps(SymfonyStyle $io): void
    {
        $io->section('Next steps');
        $io->listing([
            'Open config/packages/tenancy.yaml and uncomment the keys you need.',
            'Implement Tenancy\\Bundle\\TenantInterface on your Tenant entity.',
            'Run: bin/console tenancy:migrate (or doctrine:schema:update for dev).',
        ]);
        $io->text('Full reference: https://github.com/danplaton4/tenancy-bundle');
    }
}
