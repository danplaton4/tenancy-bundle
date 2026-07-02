<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tenancy\Bundle\Event\TenantMaintenanceDisabled;

/**
 * Takes a single tenant out of maintenance mode.
 *
 * Landlord-side write only — does NOT boot the tenant context or call BootstrapperChain.
 * After flushing the DB, the PSR cache key `tenancy.tenant.<slug>` is deleted from
 * cache.app to ensure the maintenance-lift takes effect immediately (RESEARCH §Cache Coherence).
 * Without this delete, the per-process PSR cache holds a stale object for up to 5 minutes
 * (CACHE_TTL = 300 in DoctrineTenantProvider).
 *
 * Operation is idempotent (D-08): a second disable on an already-up tenant exits
 * SUCCESS without a flush, cache delete, or event dispatch.
 */
#[AsCommand(
    name: 'tenancy:maintenance:disable',
    description: 'Take a tenant out of maintenance mode (idempotent)',
)]
final class TenantMaintenanceDisableCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $landlordEm,
        private readonly string $tenantEntityClass,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'Tenant slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slugRaw = $input->getArgument('slug');
        $slug = \is_string($slugRaw) ? $slugRaw : '';

        /** @var class-string<\Tenancy\Bundle\TenantInterface> $entityClass */
        $entityClass = $this->tenantEntityClass;

        // Fetch fresh from the landlord EM repository — bypasses both the PSR cache
        // and the isActive() gate (RESEARCH §Cache Coherence / Pitfall 2).
        /** @var \Tenancy\Bundle\TenantInterface|null $tenant */
        $tenant = $this->landlordEm->getRepository($entityClass)->findOneBy(['slug' => $slug]);

        if (null === $tenant) {
            $io->error(sprintf('Tenant "%s" not found.', $slug));

            return Command::FAILURE;
        }

        // Idempotent guard (D-08): already up → print message, exit SUCCESS.
        // No flush, no cache delete, no event.
        if (!$tenant->isInMaintenance()) {
            $io->writeln(sprintf('Tenant "%s" is not in maintenance mode.', $slug));

            return Command::SUCCESS;
        }

        // Real transition: flip the bool and persist.
        $tenant->setInMaintenance(false);
        $this->landlordEm->flush();

        // REQUIRED: delete PSR cache key so web workers pick up the new DB state immediately
        // (cache.app key format confirmed from DoctrineTenantProvider:32).
        $this->cache->delete('tenancy.tenant.'.$slug);

        // Dispatch only on real transition (MAINT-08 / D-08).
        $this->eventDispatcher->dispatch(new TenantMaintenanceDisabled($tenant));

        $io->success(sprintf('Tenant "%s" is no longer in maintenance mode.', $slug));

        return Command::SUCCESS;
    }
}
