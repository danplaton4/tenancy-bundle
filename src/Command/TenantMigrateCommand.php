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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Command\Migration\ParallelMigrationRunner;
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
        private readonly ?ParallelMigrationRunner $parallelRunner = null,
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
        $this->addOption(
            'parallel',
            null,
            InputOption::VALUE_NONE,
            'Run migrations in parallel using a bounded subprocess pool',
        );
        $this->addOption(
            'concurrency',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of concurrent subprocesses (default: 4, max: 32)',
            '4',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Compute the migration plan without applying it',
        );
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: txt (default) or json',
            'txt',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Safety net only: this branch is unreachable in production because the DI container
        // registers TenantMigrateCommand exclusively when database.enabled: true, and the
        // configuration schema rejects the combination of driver: shared_db + database.enabled: true.
        // At runtime the driver is always 'database_per_tenant'. Retained for testability and
        // defence-in-depth against future mis-wiring.
        //
        // D-06 / SC4 / T-31-05: this guard MUST run BEFORE the parallel branch so no subprocess
        // is ever spawned under shared_db. Guard-first ordering is critical.
        if ('shared_db' === $this->driver) {
            $errorOutput = $output instanceof ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $errorOutput->writeln(
                '<error>tenancy:migrate is only available with the database_per_tenant driver. Parallel migration is not supported under the shared_db driver.</error>'
            );

            return Command::FAILURE;
        }

        if (null === $this->migrationsConfig) {
            $io->error('doctrine/migrations is not configured. Install doctrine/doctrine-migrations-bundle and configure migrations.');

            return Command::FAILURE;
        }

        // Parse and validate --concurrency (ISOL-08 / SC2 / T-31-06).
        // This runs after the guards but before enumeration and any parallel branch.
        $concurrencyRaw = $input->getOption('concurrency');
        if (!\is_string($concurrencyRaw) || !\is_numeric($concurrencyRaw) || (int) $concurrencyRaw < 1) {
            $errorOutput = $output instanceof ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $errorOutput->writeln(sprintf(
                '<error>--concurrency must be a positive integer (received: %s).</error>',
                \is_string($concurrencyRaw) ? $concurrencyRaw : gettype($concurrencyRaw),
            ));

            return Command::INVALID;
        }

        $concurrency = (int) $concurrencyRaw;
        if ($concurrency > 32) {
            $concurrency = 32;
            $errorOutput = $output instanceof ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $errorOutput->writeln(
                '<comment>--concurrency clamped to 32 (maximum supported value).</comment>'
            );
        }

        $parallel = (bool) $input->getOption('parallel');
        $dryRun = (bool) $input->getOption('dry-run');
        $formatRaw = $input->getOption('format');
        $format = \is_string($formatRaw) ? $formatRaw : 'txt';

        // Tenant enumeration (unchanged from v0.4).
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

        // Delegation branch: --parallel with >1 tenant delegates to the runner.
        // Discretion: --parallel + single --tenant = no pool spawned (falls through to sequential).
        // Guard: null !== $this->parallelRunner ensures defensive fallback when runner is absent.
        if ($parallel && count($tenants) > 1 && null !== $this->parallelRunner) {
            // emitBlocks=false in JSON mode: runner stays silent; command renders JSON (D-04).
            // array_values() ensures the list<TenantInterface> type contract (non-empty-array ≠ list).
            $result = $this->parallelRunner->run(
                array_values($tenants),
                $concurrency,
                $dryRun,
                $output,
                'json' !== $format,
            );

            if ('json' === $format) {
                // D-03 / D-04: build the aggregate JSON object; write to raw $output (NOT SymfonyStyle
                // which adds decoration). stdout carries ONLY the JSON document.
                $rows = [];
                foreach ($result->tenants() as $row) {
                    $entry = [
                        'slug' => $row['slug'],
                        'status' => $row['status'],
                        'migrationsApplied' => $row['migrationsApplied'],
                        'durationMs' => $row['durationMs'],
                    ];
                    // Omit error key when null per D-03 "error?" semantics.
                    if (null !== $row['error']) {
                        $entry['error'] = $row['error'];
                    }
                    $rows[] = $entry;
                }

                $aggregate = [
                    'tenants' => $rows,
                    'summary' => [
                        'succeeded' => $result->succeeded(),
                        'failed' => $result->failed(),
                        'total' => $result->total(),
                        'wallClockMs' => $result->wallClockMs(),
                    ],
                ];

                $json = json_encode(
                    $aggregate,
                    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
                );
                $output->writeln($json);
            } else {
                // D-02: rich summary table (the runner already flushed atomic blocks live).
                $rows = [];
                foreach ($result->tenants() as $row) {
                    $rows[] = [
                        $row['slug'],
                        'succeeded' === $row['status'] ? '✓' : '✗',
                        (string) $row['migrationsApplied'],
                        $row['durationMs'].'ms',
                    ];
                }

                $io->table(
                    ['Tenant', 'Status', 'Migrations Applied', 'Duration'],
                    $rows,
                );

                $io->writeln(sprintf(
                    '%d succeeded, %d failed (wall-clock: %dms)',
                    $result->succeeded(),
                    $result->failed(),
                    $result->wallClockMs(),
                ));
            }

            // D-07: FAILURE if any tenant failed, else SUCCESS — in both human and JSON modes.
            return $result->failed() > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        // Sequential path (byte-identical to v0.4 when --parallel is absent).
        // Also reached when: --parallel + single tenant (no-pool Discretion) or runner is null.

        /** @var string[] $failures */
        $failures = [];

        foreach ($tenants as $tenant) {
            try {
                $this->runMigrationsForTenant($tenant, $this->migrationsConfig, $io, $dryRun);
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

    /**
     * Run (or dry-run) migrations for a single tenant in-process.
     *
     * D-05 / ISOL-10: when $dryRun is true the plan is computed but migrate() is NOT called.
     * Default false keeps existing call sites + sequential byte-identity.
     */
    private function runMigrationsForTenant(
        TenantInterface $tenant,
        Configuration $migrationsConfig,
        SymfonyStyle $io,
        bool $dryRun = false,
    ): void {
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
            if ($dryRun) {
                $io->writeln(sprintf('  [dry-run] %s: nothing to migrate', $tenant->getSlug()));
            }

            return;
        }

        if ($dryRun) {
            $io->writeln(sprintf('  [dry-run] %s: would apply %d migration(s)', $tenant->getSlug(), count($plan)));

            return;
        }

        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
