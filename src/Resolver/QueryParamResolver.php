<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class QueryParamResolver implements TenantResolverInterface
{
    public const PARAM_NAME = '_tenant';

    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
    ) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $slug = $request->query->get(self::PARAM_NAME);

        if ($slug === null || $slug === '') {
            return null;
        }

        try {
            return $this->tenantProvider->findBySlug((string) $slug);
        } catch (TenantNotFoundException) {
            return null;
        }
        // TenantInactiveException is NOT caught — bubbles up as HTTP 403
    }
}
