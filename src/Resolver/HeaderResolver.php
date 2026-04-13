<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class HeaderResolver implements TenantResolverInterface
{
    public const HEADER_NAME = 'X-Tenant-ID';

    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
    ) {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        $slug = $request->headers->get(self::HEADER_NAME);

        if (null === $slug || '' === $slug) {
            return null;
        }

        try {
            return $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null;
        }
        // TenantInactiveException is NOT caught — bubbles up as HTTP 403
    }
}
