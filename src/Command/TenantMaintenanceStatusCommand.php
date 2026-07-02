<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Provider\TenantProviderInterface;

/**
 * Lists tenants currently in maintenance mode.
 *
 * Uses TenantProviderInterface::findAll() which intentionally bypasses the PSR cache
 * (see DoctrineTenantProvider:58 comment: "operator tool, not a hot path"). This ensures
 * the status command always reflects the live database state, not a 5-minute stale cache.
 *
 * Output:
 *   - Default (txt): SymfonyStyle table of in-maintenance tenants (Slug + Name columns).
 *   - --format=json: single aggregate object {"tenants":[...],"total":N} on stdout,
 *     using the same json_encode flags as TenantMigrateCommand (D-10).
 *
 * Tenants NOT in maintenance are never shown (D-10).
 */
#[AsCommand(
    name: 'tenancy:maintenance:status',
    description: 'List tenants currently in maintenance mode',
)]
final class TenantMaintenanceStatusCommand extends Command
{
    public function __construct(
        private readonly ?TenantProviderInterface $tenantProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
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

        if (null === $this->tenantProvider) {
            $io->error('Tenant provider is not available. Ensure doctrine/orm is installed and configured.');

            return Command::FAILURE;
        }

        $formatRaw = $input->getOption('format');
        $format = \is_string($formatRaw) ? $formatRaw : 'txt';

        if (!\in_array($format, ['txt', 'json'], true)) {
            $io->error(sprintf('Unknown format "%s". Use "txt" or "json".', $format));

            return Command::FAILURE;
        }

        // findAll() bypasses the PSR cache — safe for status (DoctrineTenantProvider:58-75).
        $allTenants = $this->tenantProvider->findAll();

        // Filter to only in-maintenance tenants (D-10).
        $maintenanceTenants = array_values(
            array_filter($allTenants, static fn ($t) => $t->isInMaintenance())
        );

        if ('json' === $format) {
            $rows = array_map(
                static fn ($t) => ['slug' => $t->getSlug(), 'inMaintenance' => true],
                $maintenanceTenants
            );

            $aggregate = [
                'tenants' => $rows,
                'total' => count($maintenanceTenants),
            ];

            $json = json_encode(
                $aggregate,
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
            );

            // Write to raw $output (not $io) so stdout carries only the JSON document.
            $output->writeln($json);

            return Command::SUCCESS;
        }

        // Default txt: table output (Slug + Name columns).
        if ([] === $maintenanceTenants) {
            $io->writeln('No tenants are currently in maintenance mode.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn ($t) => [$t->getSlug(), $t->getName()],
            $maintenanceTenants
        );

        $io->table(['Slug', 'Name'], $rows);

        return Command::SUCCESS;
    }
}
