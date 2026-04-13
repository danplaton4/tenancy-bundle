<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tenancy\Bundle\Event\TenantContextCleared;

#[AsEventListener(event: TenantContextCleared::class)]
final class EntityManagerResetListener
{
    /**
     * @param list<string|null> $managersToReset Manager names to reset. [null] = default EM only.
     */
    public function __construct(
        private readonly ?ManagerRegistry $managerRegistry,
        private readonly array $managersToReset = [null],
    ) {
    }

    public function __invoke(TenantContextCleared $event): void
    {
        if (null === $this->managerRegistry) {
            return;
        }

        foreach ($this->managersToReset as $name) {
            $this->managerRegistry->resetManager($name);
        }
    }
}
