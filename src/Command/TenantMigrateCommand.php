<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

#[AsCommand(name: 'tenancy:migrate', description: 'Run Doctrine migrations for all tenants')]
final class TenantMigrateCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
        private readonly Connection $tenantConnection,
        private readonly ?Configuration $migrationsConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tenant',
            null,
            InputOption::VALUE_OPTIONAL,
            'Run migrations for a single tenant only',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('shared_db' === $this->driver) {
            $errorOutput = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $errorOutput->writeln(
                '<error>tenancy:migrate is only available with the database_per_tenant driver.</error>'
            );

            return Command::FAILURE;
        }

        if (null === $this->migrationsConfig) {
            $io->error('doctrine/migrations is not configured. Install doctrine/doctrine-migrations-bundle and configure migrations.');

            return Command::FAILURE;
        }

        $tenantSlug = $input->getOption('tenant');

        if (null !== $tenantSlug && \is_string($tenantSlug)) {
            try {
                $tenants = [$this->tenantProvider->findBySlug($tenantSlug)];
            } catch (\Tenancy\Bundle\Exception\TenantNotFoundException|\Tenancy\Bundle\Exception\TenantInactiveException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        } else {
            $tenants = $this->tenantProvider->findAll();
        }

        if ([] === $tenants) {
            $io->writeln('No tenants found.');

            return Command::SUCCESS;
        }

        /** @var string[] $failures */
        $failures = [];

        foreach ($tenants as $tenant) {
            try {
                $this->runMigrationsForTenant($tenant, $this->migrationsConfig, $io);
                $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
            } catch (\Throwable $e) {
                $failures[] = $tenant->getSlug();
                $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
            } finally {
                $this->tenantContext->clear();
                $this->bootstrapperChain->clear();
            }
        }

        $succeeded = count($tenants) - count($failures);
        $io->writeln(sprintf('Completed: %d succeeded, %d failed', $succeeded, count($failures)));

        if ([] !== $failures) {
            $io->writeln('Failed tenants:');
            foreach ($failures as $slug) {
                $io->writeln(sprintf('  - %s', $slug));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function runMigrationsForTenant(TenantInterface $tenant, Configuration $migrationsConfig, SymfonyStyle $io): void
    {
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);

        $dependencyFactory = DependencyFactory::fromConnection(
            new ExistingConfiguration($migrationsConfig),
            new ExistingConnection($this->tenantConnection),
        );

        $dependencyFactory->getMetadataStorage()->ensureInitialized();

        $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
        $plan = $planCalculator->getPlanUntilVersion(
            $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest')
        );

        if (0 === count($plan)) {
            return;
        }

        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
