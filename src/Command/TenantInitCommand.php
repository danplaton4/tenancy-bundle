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
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($targetPath, $yamlContent);

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
            "    # cache_prefix_separator: ':'",
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
    }
}
