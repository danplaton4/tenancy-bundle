<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;

final class ResolverChain
{
    /** @var TenantResolverInterface[] */
    private array $resolvers = [];

    public function addResolver(TenantResolverInterface $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * Iterates resolvers in insertion order and returns the first non-null result.
     *
     * @return array{tenant: \Tenancy\Bundle\TenantInterface, resolvedBy: string}
     *
     * @throws TenantNotFoundException when all resolvers return null
     */
    public function resolve(Request $request): array
    {
        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request);

            if ($tenant !== null) {
                return [
                    'tenant' => $tenant,
                    'resolvedBy' => $resolver::class,
                ];
            }
        }

        throw new TenantNotFoundException('No resolver could identify a tenant from the current request.');
    }
}
