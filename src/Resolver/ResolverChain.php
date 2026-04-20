<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;

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
     * Returns null when no resolver claims the request — this is a valid, expected
     * outcome for public routes, health checks, and landlord pages. The orchestrator
     * branches on null to leave TenantContext empty and skip the bootstrapper chain.
     *
     * A resolver that extracts an identifier but cannot find a matching tenant
     * (via DoctrineTenantProvider::findBySlug) throws TenantNotFoundException internally;
     * per the Phase 02-02 decision resolvers catch+swallow that exception and return null,
     * so such a case still surfaces here as "null — no match".
     */
    public function resolve(Request $request): ?TenantResolution
    {
        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request);

            if (null !== $tenant) {
                return new TenantResolution($tenant, $resolver::class);
            }
        }

        return null;
    }
}
