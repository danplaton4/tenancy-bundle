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
final class TenantInitCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing configuration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Tenancy Bundle — Configuration Initializer');

        $targetPath = $this->projectDir.'/config/packages/tenancy.yaml';

        if (file_exists($targetPath) && !$input->getOption('force')) {
            $io->warning('Configuration file already exists: config/packages/tenancy.yaml');
            $io->text('Use --force to overwrite.');

            return Command::FAILURE;
        }

        if (file_exists($targetPath) && $input->getOption('force')) {
            $io->note('Overwriting existing configuration file.');
        }

        $doctrineDetected = interface_exists(\Doctrine\ORM\EntityManagerInterface::class);

        $yamlContent = $this->generateYamlContent($doctrineDetected);

        $dir = \dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $io->error('Could not create directory: '.$dir);

            return Command::FAILURE;
        }

        if (false === file_put_contents($targetPath, $yamlContent)) {
            $io->error('Could not write configuration file: '.$targetPath);

            return Command::FAILURE;
        }

        $io->success('Created config/packages/tenancy.yaml');

        if ($doctrineDetected) {
            $io->text([
                'Doctrine ORM detected — recommended driver: <info>database_per_tenant</info>',
                'Uncomment driver and set database.enabled: true in your config.',
            ]);
        } else {
            $io->text([
                'Doctrine ORM not detected — recommended driver: <info>shared_db</info>',
                'Install doctrine/orm to use database_per_tenant mode.',
            ]);
        }

        $this->printNextSteps($io);

        return Command::SUCCESS;
    }

    private function generateYamlContent(bool $doctrineDetected): string
    {
        $driverLine = $doctrineDetected
            ? '    driver: database_per_tenant'
            : '    # driver: database_per_tenant';

        $databaseEnabledLine = $doctrineDetected
            ? '    #     enabled: true'
            : '    #     enabled: false';

        $lines = [
            '# config/packages/tenancy.yaml',
            '#',
            '# Tenancy Bundle Configuration',
            '# Full reference: https://github.com/danplaton4/tenancy-bundle',
            '',
            'tenancy:',
            '    # Isolation driver: database_per_tenant (separate DB per tenant) or shared_db (single DB with SQL filter)',
            $driverLine,
            '',
            '    # Throw TenantMissingException when a #[TenantAware] entity is queried without an active tenant',
            '    # strict_mode: true',
            '',
            '    # DBAL connection name for the landlord (central) database',
            '    # landlord_connection: default',
            '',
            '    # Fully-qualified class name of your Tenant entity (must implement TenantInterface)',
            '    # tenant_entity_class: Tenancy\Bundle\Entity\Tenant',
            '',
            '    # Separator used for tenant cache namespace isolation',
            "    # cache_prefix_separator: '.'",
            '',
            '    # Enabled resolvers (order = priority: first match wins)',
            '    # resolvers: [host, header, query_param, console]',
            '',
            '    # HostResolver settings — extract tenant slug from subdomain',
            '    # host:',
            '    #     app_domain: app.example.com',
            '',
            '    # Database-per-tenant mode (requires doctrine/orm)',
            '    # Set enabled: true to activate two entity managers (landlord + tenant)',
            '    # database:',
            $databaseEnabledLine,
        ];

        return implode("\n", $lines)."\n";
    }

    private function printNextSteps(SymfonyStyle $io): void
    {
        $io->section('Next Steps');

        $io->listing([
            'Review and uncomment the configuration values in config/packages/tenancy.yaml',
            'Create your Tenant entity implementing Tenancy\\Bundle\\TenantInterface',
            'Configure your host.app_domain if using subdomain-based resolution',
            'Run bin/console doctrine:schema:update or create migrations for the Tenant entity',
            'Visit https://github.com/danplaton4/tenancy-bundle for full documentation',
        ]);

        $io->section('Sample doctrine.yaml (copy into config/packages/doctrine.yaml)');
        $io->writeln($this->sampleDoctrineYaml());

        $io->section('Driver family requirement');
        $io->warning(
            "The tenant connection's driver parameter MUST match the driver family of your tenant "
            ."databases. TenantDriverMiddleware merges tenant params at connect() time, but the "
            ."driver is resolved from the placeholder at container boot. Use pdo_mysql for MySQL, "
            .'pdo_pgsql for PostgreSQL, pdo_sqlite for SQLite (testing).'
        );
    }

    private function sampleDoctrineYaml(): string
    {
        return <<<'YAML'
            # config/packages/doctrine.yaml (example for MySQL tenants)
            doctrine:
                dbal:
                    default_connection: landlord
                    connections:
                        landlord:
                            url: '%env(DATABASE_URL)%'
                        tenant:
                            # Driver family MUST match your tenant databases (see callout below).
                            # Params below are merged with the active tenant's getConnectionConfig()
                            # at connect() time by TenantDriverMiddleware.
                            driver: pdo_mysql
                            host: '%env(TENANT_DB_HOST)%'
                            user: '%env(TENANT_DB_USER)%'
                            password: '%env(TENANT_DB_PASSWORD)%'
                            dbname: placeholder_tenant

                orm:
                    default_entity_manager: landlord
                    entity_managers:
                        landlord:
                            connection: landlord
                            mappings:
                                App:
                                    type: attribute
                                    dir: '%kernel.project_dir%/src/Entity/Landlord'
                                    prefix: 'App\Entity\Landlord'
                        tenant:
                            connection: tenant
                            mappings:
                                App:
                                    type: attribute
                                    dir: '%kernel.project_dir%/src/Entity/Tenant'
                                    prefix: 'App\Entity\Tenant'
            YAML;
    }
}
