<?php

declare(strict_types=1);

namespace App\Command;

use App\DataFixtures\LandlordTenantsFixture;
use App\Entity\Landlord\DemoTenant;
use App\Entity\Tenant\Post;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;

/**
 * Idempotent demo provisioning command.
 *
 * Resolves CONTEXT D-05 and D-06 discrepancies:
 * - D-05 referenced `tenancy:migrate --create-dbs` which does NOT exist in the bundle.
 * - D-06 referenced `TenantContextOrchestrator::executeAs()` which does NOT exist.
 *
 * This command uses the verified bundle primitives:
 * - ROOT DBAL connection for `CREATE DATABASE IF NOT EXISTS` (runtime `tenancy` user lacks CREATE DATABASE)
 * - Explicit setTenant → boot → try/finally → clear pattern from TenantMigrateCommand (lines 97-108)
 * - SchemaTool::updateSchema for idempotent schema application (no doctrine/migrations required)
 *
 * Run: `bin/console app:seed-demo`
 * The container entrypoint runs this BEFORE `exec frankenphp run` so the /health endpoint
 * only goes green after seeding completes (RESEARCH §"Pitfall 5" — Option 2 mitigation).
 */
#[AsCommand(name: 'app:seed-demo', description: 'Create per-tenant DBs and seed schemas + Posts. Idempotent.')]
final class SeedDemoCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly LandlordTenantsFixture $landlordsFixture,
        #[Autowire(env: 'MARIADB_ROOT_PASSWORD')]
        private readonly string $mariadbRootPassword,
        #[Autowire(env: 'default::DATABASE_HOST')]
        private readonly string $databaseHost = 'db',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // No options needed — command is idempotent by design.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Step 1: Landlord schema + tenants ────────────────────────────────────
        $io->section('Landlord schema + tenants');

        $schemaTool = new SchemaTool($this->landlordEm);
        $metadata = [$this->landlordEm->getClassMetadata(DemoTenant::class)];
        $schemaTool->updateSchema($metadata); // idempotent

        $executor = new ORMExecutor($this->landlordEm, new ORMPurger());
        $executor->execute([$this->landlordsFixture], true); // append: true — re-runs don't wipe

        $io->writeln(' <info>✓</info> Landlord schema updated and tenants seeded');

        // ── Step 2: Create per-tenant databases via root DBAL connection ──────────
        $io->section('Per-tenant database provisioning');

        $rootConnection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $this->databaseHost,
            'user' => 'root',
            'password' => $this->mariadbRootPassword,
            'charset' => 'utf8mb4',
        ]);

        foreach ($this->tenantProvider->findAll() as $tenant) {
            $dbName = 'tenant_'.$tenant->getSlug();
            $rootConnection->executeStatement(
                sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName)
            );
            $rootConnection->executeStatement(
                sprintf("GRANT ALL ON `%s`.* TO 'tenancy'@'%%'", $dbName)
            );
            $io->writeln(sprintf(' <info>✓</info> DB %s', $dbName));
        }

        $rootConnection->executeStatement('FLUSH PRIVILEGES');
        $rootConnection->close();

        // ── Step 3: Per-tenant schemas + posts ───────────────────────────────────
        $io->section('Per-tenant schemas + posts');

        foreach ($this->tenantProvider->findAll() as $tenant) {
            try {
                // Boot/clear pattern verbatim from src/Command/TenantMigrateCommand.php lines 97-108
                $this->tenantContext->setTenant($tenant);
                $this->bootstrapperChain->boot($tenant);

                // Schema (idempotent — updateSchema not createSchema)
                $tenantSchemaTool = new SchemaTool($this->tenantEm);
                $tenantMetadata = [$this->tenantEm->getClassMetadata(Post::class)];
                $tenantSchemaTool->updateSchema($tenantMetadata);

                // Seed posts only if empty (idempotency guard)
                $existing = $this->tenantEm->getRepository(Post::class)->count([]);
                if (0 === $existing) {
                    foreach ($this->seedPostsFor($tenant->getSlug()) as [$title, $body]) {
                        $this->tenantEm->persist(new Post($title, $body));
                    }
                    $this->tenantEm->flush();
                }

                $this->tenantEm->clear();
                $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
            } catch (\Throwable $e) {
                $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));

                return Command::FAILURE;
            } finally {
                $this->tenantContext->clear();
                $this->bootstrapperChain->clear();
            }
        }

        $io->success('Demo seeded successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function seedPostsFor(string $slug): array
    {
        return match ($slug) {
            'acme' => [
                ['Welcome to Acme', 'Acme Corporation onboards its first customers.'],
                ['Acme launches v2', 'New features rolled out across all regions.'],
                ['Acme Q3 numbers', 'Revenue up 42% year over year.'],
            ],
            'globex' => [
                ['Globex announces partnership', 'Strategic alliance with Initech announced today.'],
                ['Globex hits 100k customers', 'Reached our six-figure milestone.'],
            ],
            'initech' => [
                ['Initech files for IPO', 'Initech LLC begins the public offering process.'],
                ['Initech engineering retreat', 'Annual offsite kicks off in Austin.'],
            ],
            default => [],
        };
    }
}
