<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tenancy\Bundle\Event\TenantContextCleared;

#[AsEventListener(event: TenantContextCleared::class)]
final class EntityManagerResetListener
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function __invoke(TenantContextCleared $event): void
    {
        $this->managerRegistry->resetManager();
    }
}
