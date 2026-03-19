<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Doctrine\ORM\EntityManagerInterface;
use Tenancy\Bundle\TenantInterface;

final class DoctrineBootstrapper implements TenantBootstrapperInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function boot(TenantInterface $tenant): void
    {
        $this->em->clear();
    }

    public function clear(): void
    {
        $this->em->clear();
    }
}
