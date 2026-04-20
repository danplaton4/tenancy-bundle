<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Tenancy\Bundle\TenantInterface;

/**
 * Value object returned by ResolverChain::resolve() when a resolver successfully identifies a tenant.
 *
 * Deliberately minimal: does NOT carry the Request — TenantResolved event carries that downstream.
 * Keeping this structure tiny so the orchestrator's null-branch decision is type-system-enforced.
 */
final readonly class TenantResolution
{
    public function __construct(
        public TenantInterface $tenant,
        public string $resolvedBy,
    ) {
    }
}
