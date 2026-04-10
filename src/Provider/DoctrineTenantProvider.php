<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\TenantInterface;

final class DoctrineTenantProvider implements TenantProviderInterface
{
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
        private readonly string $tenantEntityClass,
    ) {
    }

    public function findBySlug(string $slug): TenantInterface
    {
        /** @var class-string<TenantInterface> $entityClass */
        $entityClass = $this->tenantEntityClass;

        /** @var TenantInterface|null $tenant */
        $tenant = $this->cache->get(
            'tenancy.tenant.' . $slug,
            function (ItemInterface $item) use ($slug, $entityClass): ?TenantInterface {
                $item->expiresAfter(self::CACHE_TTL);

                /** @var TenantInterface|null $result */
                $result = $this->entityManager
                    ->getRepository($entityClass)
                    ->findOneBy(['slug' => $slug]);

                return $result;
            }
        );

        if ($tenant === null) {
            throw new TenantNotFoundException(sprintf('Tenant "%s" not found.', $slug));
        }

        // is_active check runs AFTER cache retrieval — inactive tenants are cached
        // to prevent DB hammering on repeated requests for disabled tenants.
        if (!$tenant->isActive()) {
            throw new TenantInactiveException($slug);
        }

        return $tenant;
    }

    /**
     * Returns all tenants (active and inactive). Bypasses cache intentionally —
     * findAll() is an operator tool (migration commands), not a hot path.
     *
     * @return TenantInterface[]
     */
    public function findAll(): array
    {
        /** @var class-string<TenantInterface> $entityClass */
        $entityClass = $this->tenantEntityClass;

        /** @var TenantInterface[] $tenants */
        $tenants = $this->entityManager
            ->getRepository($entityClass)
            ->findAll();

        return $tenants;
    }
}
