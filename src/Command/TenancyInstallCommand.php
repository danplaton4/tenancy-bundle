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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing tenancy.yaml when delegating to tenancy:init')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the proposed bundles.php mutation; do not write; do not invoke tenancy:init');
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
                $this->printNextSteps($io);

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        }

        $this->printNextSteps($io);

        return Command::SUCCESS;
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
