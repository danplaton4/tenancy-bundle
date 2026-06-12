<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Shared\SharedEntityCopierInterface;
use Tenancy\Bundle\TenantInterface;

#[AsCommand(name: 'tenancy:shared:resync', description: 'Re-sync all #[Shared] entities to target tenant(s)')]
final class SharedEntityResyncCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
        private readonly EntityManagerInterface $landlordEm,
        private readonly ManagerRegistry $registry,
        private readonly SharedEntityCopierInterface $copier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tenant',
            null,
            InputOption::VALUE_OPTIONAL,
            'Re-sync a single tenant only',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Classify drift without writing',
        );
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Skip the confirmation prompt',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // D-05: shared_db is an informational no-op (SUCCESS, not FAILURE).
        // Under shared_db, #[Shared] entities exist once in the single shared DB —
        // no per-tenant copies are needed. Resync is structurally inapplicable.
        if ('shared_db' === $this->driver) {
            $io->writeln('<info>tenancy:shared:resync is a no-op under the shared_db driver.</info>');
            $io->writeln('Under shared_db, #[Shared] entities exist once in the single shared DB — no per-tenant copies needed.');

            return Command::SUCCESS;
        }

        // D-01: resolve single tenant or all tenants
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

        // Enumerate shared classes once (not per tenant — RESEARCH anti-pattern)
        $sharedClasses = $this->copier->findSharedClasses($this->landlordEm);

        if ([] === $sharedClasses) {
            $io->writeln('No #[Shared] entity classes found.');

            return Command::SUCCESS;
        }

        // Materialize landlord rows per class once (not per tenant — RESEARCH anti-pattern)
        /** @var array<class-string, list<object>> $landlordRowsByClass */
        $landlordRowsByClass = [];
        foreach ($sharedClasses as $class) {
            /** @var list<object> $rows */
            $rows = $this->landlordEm->getRepository($class)->findAll();
            $landlordRowsByClass[$class] = $rows;
        }

        $isDryRun = (bool) $input->getOption('dry-run');

        // Classify pass: for each tenant, classify every shared row (read-only, no flush)
        // Also used to build the drift summary table shown before the apply prompt.
        /** @var array<string, array{insert: int, update: int, in-sync: int}> $driftSummary keyed by slug */
        $driftSummary = [];

        /** @var array<string, true> $classifyErrored slugs whose classify pass threw */
        $classifyErrored = [];

        foreach ($tenants as $tenant) {
            $slug = $tenant->getSlug();
            $driftSummary[$slug] = ['insert' => 0, 'update' => 0, 'in-sync' => 0];

            try {
                $this->tenantContext->setTenant($tenant);
                $this->bootstrapperChain->boot($tenant);

                /** @var EntityManagerInterface $tenantEm */
                $tenantEm = $this->registry->getManager('tenant');

                foreach ($landlordRowsByClass as $rows) {
                    foreach ($rows as $entity) {
                        $classification = $this->copier->classifyRow($this->landlordEm, $tenantEm, $entity);
                        ++$driftSummary[$slug][$classification];
                    }
                }
            } catch (\Throwable $e) {
                // WR-01: Mark this tenant as errored so the apply pass can skip it (WR-02) and
                // the summary table renders a distinct ERROR marker instead of a misleading 0/0/0.
                $classifyErrored[$slug] = true;
                $io->warning(sprintf('Classification failed for tenant "%s": %s', $slug, $e->getMessage()));
                // Defensive reset: a classify-phase failure may close the tenant EM; reset it so
                // the next tenant's classify pass obtains a fresh manager (mirrors CR-01 recovery).
                $this->registry->resetManager('tenant');
            } finally {
                $this->tenantContext->clear();
                $this->bootstrapperChain->clear();
            }
        }

        // Print the drift summary table — WR-01: errored tenants show STATUS=ERROR and '—' counts
        // so operators cannot mistake a crashed classify for "in-sync, nothing to do".
        $tableRows = [];
        foreach ($driftSummary as $slug => $counts) {
            if (isset($classifyErrored[$slug])) {
                $tableRows[] = [$slug, '—', '—', '—', '<error>ERROR</error>'];
            } else {
                $tableRows[] = [$slug, (string) $counts['insert'], (string) $counts['update'], (string) $counts['in-sync'], 'ok'];
            }
        }
        $io->table(['Tenant', 'Would-Insert', 'Would-Update', 'In-Sync', 'Status'], $tableRows);

        // D-03: dry-run exits SUCCESS here — never prompt, never write
        if ($isDryRun) {
            return Command::SUCCESS;
        }

        // D-04: confirm() gate — default-No; -n without --force aborts cleanly with SUCCESS
        $isForce = (bool) $input->getOption('force');
        if (!$isForce && !$io->confirm('Proceed with live resync?', false)) {
            return Command::SUCCESS;
        }

        // Apply pass: per-tenant try/catch/finally continue-on-failure (D-06)
        /** @var string[] $failures */
        $failures = [];
        // WR-03: count successes explicitly — do not derive by subtraction from tenant count.
        $succeeded = 0;

        foreach ($tenants as $tenant) {
            $slug = $tenant->getSlug();

            // WR-02-light: hard-skip tenants whose classify pass errored — the preview table showed
            // ERROR for them, so the operator knows they were not inspected. Treat as failure so the
            // command exits FAILURE and the operator is aware the resync was not complete.
            if (isset($classifyErrored[$slug])) {
                $failures[] = $slug;
                $io->writeln(sprintf(' <comment>skipped</comment> %s (classification failed)', $slug));

                continue;
            }

            try {
                $this->resyncForTenant($tenant, $landlordRowsByClass);
                ++$succeeded;
                $io->writeln(sprintf(' <info>✓</info> %s', $slug));
            } catch (\Throwable $e) {
                $failures[] = $slug;
                $io->writeln(sprintf(' <error>✗</error> %s (%s)', $slug, $e->getMessage()));
                // CR-01: A failed flush closes the Doctrine EM. Reset the tenant manager so the
                // next tenant's apply pass obtains a usable, open EM instead of the same closed
                // instance. Without this reset, one tenant's failure cascades to all later tenants
                // (mirror of SharedEntitySyncSubscriber::applyChange() which resets on catch).
                $this->registry->resetManager('tenant');
            } finally {
                $this->tenantContext->clear();
                $this->bootstrapperChain->clear();
            }
        }

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
     * Boot tenant context and apply all shared rows to the tenant EM.
     *
     * Called inside the continue-on-failure try block — throws on any error
     * so the caller's catch records the failure.
     *
     * @param array<class-string, list<object>> $landlordRowsByClass
     */
    private function resyncForTenant(TenantInterface $tenant, array $landlordRowsByClass): void
    {
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);

        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $this->registry->getManager('tenant');

        foreach ($landlordRowsByClass as $rows) {
            foreach ($rows as $entity) {
                $type = $this->copier->classifyRow($this->landlordEm, $tenantEm, $entity);
                if ('in-sync' !== $type) {
                    $this->copier->applyRow($this->landlordEm, $tenantEm, $entity, $type);
                }
            }
        }
    }
}
